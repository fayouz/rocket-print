<?php

namespace App\MessageHandler;

use App\Enum\PrintJobStatus;
use App\Message\PrintDocument;
use App\Print\Connector\PrinterConnectors;
use App\Print\DocumentStorage;
use App\Print\PrintException;
use App\Print\PrintRequest;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Sends a print job to its printer. A failure worth retrying (network, busy printer…) puts the job back in the queue,
 * 1, then 5 minutes later; after PrintJob::MAX_ATTEMPTS attempts, or a refused document, the job fails.
 */
#[AsMessageHandler]
final class PrintDocumentHandler
{
    private const RETRY_DELAYS = [1 => 60_000, 2 => 300_000];

    public function __construct(
        private readonly PrintJobRepository $jobs,
        private readonly PrinterConnectors $connectors,
        private readonly DocumentStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PrintDocument $message): void
    {
        $job = $this->jobs->find($message->jobId);
        // Cancelled, or already handled.
        if (null === $job || PrintJobStatus::Queued !== $job->getStatus()) {
            return;
        }
        $printer = $job->getPrinter();
        $job->start();
        $this->em->flush();

        try {
            if (null === $printer) {
                throw new PrintException('The printer was deleted.', false);
            }
            if (!$printer->isEnabled()) {
                throw new PrintException('The printer is disabled.', false);
            }
            $path = $this->storage->path($job);
            if ($job->isContentPurged() || !is_file($path)) {
                throw new PrintException('The document is no longer available.', false);
            }
            $externalId = $this->connectors->for($printer)->print($printer, new PrintRequest(
                path: $path,
                title: $job->getTitle(),
                mimeType: $job->getMimeType(),
                user: $job->getOwner()->getEmail(),
                copies: $job->getCopies(),
                duplex: $job->isDuplex(),
                color: $job->isColor(),
                reference: $job->getId()->toRfc4122(),
            ));
            $job->markPrinted($externalId, $this->clock->now());
            $this->em->flush();
        } catch (PrintException $e) {
            $this->logger->warning('Print job {job} failed on {printer}: {error}', ['job' => $job->getId(), 'printer' => $job->getPrinterName(), 'error' => $e->getMessage()]);
            $retry = $job->markFailed($e->getMessage(), $e->retryable);
            $this->em->flush();
            if ($retry) {
                $this->bus->dispatch(new PrintDocument($message->jobId), [new DelayStamp(self::RETRY_DELAYS[$job->getAttempts()] ?? 300_000)]);
            }
        }
    }
}
