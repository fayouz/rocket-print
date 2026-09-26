<?php

namespace App\Entity;

use Rocket\Core\Entity\TrackedTrait;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\PrinterConnectorType;
use App\Repository\PrinterRepository;
use App\State\PrinterProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A printer of the organisation, declared by an administrator with the connector that reaches it.
 * Users list the enabled printers (GET /api/printers); the connection settings are only exposed under /api/admin/printers.
 */
#[ORM\Entity(repositoryClass: PrinterRepository::class)]
#[UniqueEntity('name', message: 'A printer already has this name.')]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/printers', name: 'printers'),
        new Get(uriTemplate: '/printers/{id}', security: 'object.isEnabled() or is_granted("ROLE_ADMIN")'),
    ],
    normalizationContext: ['groups' => ['printer:read']],
    security: "is_granted('ROLE_USER')",
    order: ['defaultPrinter' => 'DESC', 'name' => 'ASC'],
)]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/admin/printers'),
        new Get(uriTemplate: '/admin/printers/{id}'),
        new Post(uriTemplate: '/admin/printers', processor: PrinterProcessor::class),
        new Patch(uriTemplate: '/admin/printers/{id}', processor: PrinterProcessor::class),
        new Delete(uriTemplate: '/admin/printers/{id}'),
    ],
    normalizationContext: ['groups' => ['printer:read', 'printer:admin', 'tracking']],
    denormalizationContext: ['groups' => ['printer:write']],
    security: "is_granted('ROLE_ADMIN')",
    order: ['name' => 'ASC'],
)]
class Printer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['printer:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['printer:read', 'printer:write'])]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['printer:read', 'printer:write'])]
    private ?string $description = null;

    /** Where it is, e.g. "2e étage, open space". */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    #[Groups(['printer:read', 'printer:write'])]
    private ?string $location = null;

    #[ORM\Column(length: 16, enumType: PrinterConnectorType::class)]
    #[Groups(['printer:admin', 'printer:write'])]
    private PrinterConnectorType $connector = PrinterConnectorType::Samba;

    /**
     * Samba: //server/share (also smb://server/share or \\server\share); IPP: ipp://host:631/printers/queue (ipps://, http(s)://);
     * folder: a sub-directory of PRINT_FOLDER_ROOT.
     */
    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    #[Groups(['printer:admin', 'printer:write'])]
    private string $uri = '';

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    #[Groups(['printer:admin', 'printer:write'])]
    private ?string $username = null;

    /** Samba domain or workgroup. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Groups(['printer:admin', 'printer:write'])]
    private ?string $domain = null;

    /** Encrypted (SecretBox); never returned by the API. */
    #[ORM\Column(name: 'password', type: Types::TEXT, options: ['default' => ''])]
    private string $encryptedPassword = '';

    /** Submitted password, encrypted by PrinterProcessor; null keeps the current one, "" removes it. */
    #[Groups(['printer:write'])]
    private ?string $password = null;

    #[ORM\Column]
    #[Groups(['printer:read', 'printer:write'])]
    private bool $colorSupported = false;

    #[ORM\Column]
    #[Groups(['printer:read', 'printer:write'])]
    private bool $duplexSupported = false;

    /** Preselected on the print page. At most one printer is the default. */
    #[ORM\Column]
    #[Groups(['printer:read', 'printer:write'])]
    private bool $defaultPrinter = false;

    #[ORM\Column]
    #[Groups(['printer:read', 'printer:write'])]
    private bool $enabled = true;

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    #[Assert\Callback]
    public function validateUri(ExecutionContextInterface $context): void
    {
        $valid = match ($this->connector) {
            PrinterConnectorType::Samba => null !== self::sambaShare($this->uri),
            PrinterConnectorType::Ipp => (bool) preg_match('#^(ipps?|https?)://[^/\s:]+(:\d+)?(/\S*)?$#i', $this->uri),
            PrinterConnectorType::Folder => (bool) preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#', $this->uri),
        };
        if (!$valid) {
            $context->buildViolation(match ($this->connector) {
                PrinterConnectorType::Samba => 'Expected a share such as //server/printer.',
                PrinterConnectorType::Ipp => 'Expected an address such as ipp://server:631/printers/queue.',
                PrinterConnectorType::Folder => 'Expected a sub-directory name, such as "tests" or "archives/2026".',
            })->atPath('uri')->addViolation();
        }
    }

    /**
     * "//server/share" from //server/share, smb://server/share or \\server\share; null when it is not a share.
     */
    public static function sambaShare(string $uri): ?string
    {
        $target = self::sambaTarget($uri);

        return null === $target ? null : '//'.$target['server'].'/'.$target['share'];
    }

    /** @return array{server: string, share: string, port: int|null}|null */
    public static function sambaTarget(string $uri): ?array
    {
        $uri = str_replace('\\', '/', trim($uri));
        $uri = (string) preg_replace('#^smb:#i', '', $uri);
        if (!preg_match('#^//([A-Za-z0-9.-]+|\[[0-9a-fA-F:]+\])(?::(\d{1,5}))?/([^/\s"\'<>|*?;%](?:[^/"\'<>|*?;%\t\r\n]*[^/\s"\'<>|*?;%])?)/?$#', $uri, $m)) {
            return null;
        }

        return ['server' => $m[1], 'share' => $m[3], 'port' => '' === $m[2] ? null : (int) $m[2]];
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getConnector(): PrinterConnectorType
    {
        return $this->connector;
    }

    public function setConnector(PrinterConnectorType $connector): static
    {
        $this->connector = $connector;

        return $this;
    }

    /** Label of the connector, shown to everyone. */
    #[Groups(['printer:read'])]
    public function getConnectorLabel(): string
    {
        return $this->connector->label();
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function setUri(string $uri): static
    {
        $this->uri = trim($uri);

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = '' === $username ? null : $username;

        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(?string $domain): static
    {
        $this->domain = '' === $domain ? null : $domain;

        return $this;
    }

    public function getEncryptedPassword(): string
    {
        return $this->encryptedPassword;
    }

    public function setEncryptedPassword(string $encryptedPassword): static
    {
        $this->encryptedPassword = $encryptedPassword;

        return $this;
    }

    #[Groups(['printer:admin'])]
    public function getHasPassword(): bool
    {
        return '' !== $this->encryptedPassword;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isColorSupported(): bool
    {
        return $this->colorSupported;
    }

    public function setColorSupported(bool $colorSupported): static
    {
        $this->colorSupported = $colorSupported;

        return $this;
    }

    public function isDuplexSupported(): bool
    {
        return $this->duplexSupported;
    }

    public function setDuplexSupported(bool $duplexSupported): static
    {
        $this->duplexSupported = $duplexSupported;

        return $this;
    }

    public function isDefaultPrinter(): bool
    {
        return $this->defaultPrinter;
    }

    public function setDefaultPrinter(bool $defaultPrinter): static
    {
        $this->defaultPrinter = $defaultPrinter;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }
}
