<?php

namespace App\Print;

use App\Entity\Application;
use App\Entity\Printer;
use App\Entity\PrintJob;
use App\Entity\User;
use App\Message\PrintDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\MimeTypes;

/** Puts documents in the print queue: checks, stores the document, and hands the job to the worker. */
class PrintSpooler
{
    /** Formats printers or print servers understand without a driver on our side. */
    public const FORMATS = [
        'application/pdf' => 'PDF',
        'application/postscript' => 'PostScript',
        'application/vnd.hp-pcl' => 'PCL',
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'text/plain' => 'Texte',
    ];

    private const EXTENSIONS = ['pdf' => 'application/pdf', 'ps' => 'application/postscript', 'pcl' => 'application/vnd.hp-pcl', 'prn' => 'application/vnd.hp-pcl', 'txt' => 'text/plain'];

    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        #[Autowire(env: 'int:PRINT_MAX_FILE_SIZE')] private readonly int $maxFileSize,
    ) {
    }

    public function maxFileSize(): int
    {
        return $this->maxFileSize;
    }

    /** @param array{copies?: int, duplex?: bool, color?: bool} $options */
    public function submit(UploadedFile $upload, Printer $printer, User $owner, ?Application $application, array $options = [], ?string $title = null): PrintJob
    {
        if (!$upload->isValid()) {
            throw new UnprocessableEntityHttpException($upload->getErrorMessage());
        }

        return $this->queue(new File($upload->getPathname()), $title ?? $upload->getClientOriginalName(), $printer, $owner, $application, $options);
    }

    /** @param array{copies?: int, duplex?: bool, color?: bool} $options */
    public function queue(File $document, string $title, Printer $printer, User $owner, ?Application $application = null, array $options = []): PrintJob
    {
        if (!$printer->isEnabled()) {
            throw new UnprocessableEntityHttpException('This printer is disabled.');
        }
        $size = (int) $document->getSize();
        if (0 === $size) {
            throw new UnprocessableEntityHttpException('The document is empty.');
        }
        if ($size > $this->maxFileSize) {
            throw new HttpException(413, \sprintf('The document exceeds the maximum size (%d bytes).', $this->maxFileSize));
        }
        $mime = self::mimeType($document->getPathname(), $title);
        if (!isset(self::FORMATS[$mime])) {
            throw new UnprocessableEntityHttpException(\sprintf('Unsupported format (%s): send a PDF, PostScript, PCL, JPEG, PNG or text document.', $mime));
        }
        $copies = $options['copies'] ?? 1;
        if ($copies < 1 || $copies > 99) {
            throw new UnprocessableEntityHttpException('The number of copies must be between 1 and 99.');
        }

        $title = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title)) ?: 'Document';
        $job = (new PrintJob($owner, $printer, mb_substr($title, 0, 255), $mime, $size))
            ->setApplication($application)
            ->setCopies($copies)
            ->setDuplex(($options['duplex'] ?? false) && $printer->isDuplexSupported())
            ->setColor(($options['color'] ?? false) && $printer->isColorSupported());
        $this->storage->store($job, $document);
        $this->em->persist($job);
        $this->em->flush();

        $this->bus->dispatch(new PrintDocument($job->getId()->toRfc4122()));

        return $job;
    }

    public static function mimeType(string $path, string $name): string
    {
        $mime = strtolower(MimeTypes::getDefault()->guessMimeType($path) ?? 'application/octet-stream');
        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
        if (\in_array($mime, ['application/octet-stream', 'text/x-pcl'], true) || str_contains($mime, 'pcl')) {
            return self::EXTENSIONS[$extension] ?? (str_contains($mime, 'pcl') ? 'application/vnd.hp-pcl' : $mime);
        }

        return $mime;
    }
}
