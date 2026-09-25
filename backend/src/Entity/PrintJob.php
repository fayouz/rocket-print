<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\PrintJobStatus;
use App\Repository\PrintJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A document sent to a printer. Created with POST /api/print-jobs (multipart), printed by the worker.
 * Everyone lists their own jobs; administrators list all of them with ?all=1.
 */
#[ORM\Entity(repositoryClass: PrintJobRepository::class)]
#[ORM\Index(name: 'idx_print_job_owner_created', columns: ['owner_id', 'created_at'])]
#[ORM\Index(name: 'idx_print_job_status', columns: ['status'])]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/print-jobs'),
        new Get(uriTemplate: '/print-jobs/{id}', security: "object.getOwner() == user or is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['print_job:read', 'tracking']],
    security: "is_granted('ROLE_USER')",
    order: ['createdAt' => 'DESC'],
    paginationClientItemsPerPage: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'printer' => 'exact', 'title' => 'ipartial'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'title', 'status'])]
class PrintJob
{
    public const MAX_ATTEMPTS = 3;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['print_job:read'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** The application that sent it, on behalf of the owner. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Application $application = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Printer $printer;

    /** Kept when the printer is deleted. */
    #[ORM\Column(length: 120)]
    #[Groups(['print_job:read'])]
    private string $printerName;

    /** Name of the document. */
    #[ORM\Column(length: 255)]
    #[Groups(['print_job:read'])]
    private string $title;

    #[ORM\Column(length: 127)]
    #[Groups(['print_job:read'])]
    private string $mimeType;

    #[ORM\Column]
    #[Groups(['print_job:read'])]
    private int $size;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Groups(['print_job:read'])]
    private int $copies = 1;

    #[ORM\Column]
    #[Groups(['print_job:read'])]
    private bool $duplex = false;

    #[ORM\Column]
    #[Groups(['print_job:read'])]
    private bool $color = false;

    #[ORM\Column(length: 16, enumType: PrintJobStatus::class)]
    #[Groups(['print_job:read'])]
    private PrintJobStatus $status = PrintJobStatus::Queued;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Groups(['print_job:read'])]
    private int $attempts = 0;

    /** Last error of the connector. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['print_job:read'])]
    private ?string $error = null;

    /** Reference given by the printer or the print server (IPP job-id…). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['print_job:read'])]
    private ?string $externalId = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['print_job:read'])]
    private ?\DateTimeImmutable $printedAt = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $storageKey;

    /** The document was deleted after the retention period (PRINT_RETENTION_DAYS). */
    #[ORM\Column]
    #[Groups(['print_job:read'])]
    private bool $contentPurged = false;

    use TrackedTrait;

    public function __construct(User $owner, Printer $printer, string $title, string $mimeType, int $size)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->printer = $printer;
        $this->printerName = $printer->getName();
        $this->title = $title;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->storageKey = substr((string) $this->id, 0, 2).'/'.$this->id;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    #[Groups(['print_job:read'])]
    public function getOwnerEmail(): string
    {
        return $this->owner->getEmail();
    }

    public function getApplication(): ?Application
    {
        return $this->application;
    }

    public function setApplication(?Application $application): static
    {
        $this->application = $application;

        return $this;
    }

    #[Groups(['print_job:read'])]
    public function getApplicationName(): ?string
    {
        return $this->application?->getName();
    }

    public function getPrinter(): ?Printer
    {
        return $this->printer;
    }

    #[Groups(['print_job:read'])]
    public function getPrinterId(): ?string
    {
        return $this->printer?->getId()->toRfc4122();
    }

    public function getPrinterName(): string
    {
        return $this->printerName;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getCopies(): int
    {
        return $this->copies;
    }

    public function setCopies(int $copies): static
    {
        $this->copies = $copies;

        return $this;
    }

    public function isDuplex(): bool
    {
        return $this->duplex;
    }

    public function setDuplex(bool $duplex): static
    {
        $this->duplex = $duplex;

        return $this;
    }

    public function isColor(): bool
    {
        return $this->color;
    }

    public function setColor(bool $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getStatus(): PrintJobStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getPrintedAt(): ?\DateTimeImmutable
    {
        return $this->printedAt;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function isContentPurged(): bool
    {
        return $this->contentPurged;
    }

    public function markContentPurged(): void
    {
        $this->contentPurged = true;
    }

    public function start(): void
    {
        $this->status = PrintJobStatus::Printing;
        ++$this->attempts;
    }

    public function markPrinted(?string $externalId, \DateTimeImmutable $at): void
    {
        $this->status = PrintJobStatus::Printed;
        $this->externalId = $externalId;
        $this->error = null;
        $this->printedAt = $at;
    }

    /** @return bool whether another attempt will be made */
    public function markFailed(string $error, bool $retryable): bool
    {
        $this->error = mb_substr($error, 0, 2000);
        $retry = $retryable && $this->attempts < self::MAX_ATTEMPTS;
        $this->status = $retry ? PrintJobStatus::Queued : PrintJobStatus::Failed;

        return $retry;
    }

    public function cancel(): void
    {
        $this->status = PrintJobStatus::Cancelled;
    }

    /** Back in the queue after a failure or a cancellation, with a fresh set of attempts. */
    public function requeue(): void
    {
        $this->status = PrintJobStatus::Queued;
        $this->attempts = 0;
        $this->error = null;
    }

    #[Groups(['print_job:read'])]
    public function isCancellable(): bool
    {
        return PrintJobStatus::Queued === $this->status;
    }

    #[Groups(['print_job:read'])]
    public function isRetryable(): bool
    {
        return \in_array($this->status, [PrintJobStatus::Failed, PrintJobStatus::Cancelled], true)
            && !$this->contentPurged && null !== $this->printer;
    }
}
