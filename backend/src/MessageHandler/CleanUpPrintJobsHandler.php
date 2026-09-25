<?php

namespace App\MessageHandler;

use App\Enum\PrintJobStatus;
use App\Message\CleanUpPrintJobs;
use App\Print\DocumentStorage;
use App\Repository\PrintJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CleanUpPrintJobsHandler
{
    /** A job still "printing" after this long lost its worker (restart, crash). */
    private const STUCK_AFTER = '-15 minutes';

    public function __construct(
        private readonly PrintJobRepository $jobs,
        private readonly DocumentStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'int:PRINT_RETENTION_DAYS')] private readonly int $retentionDays,
    ) {
    }

    public function __invoke(CleanUpPrintJobs $message): void
    {
        $now = $this->clock->now();
        foreach ($this->jobs->findBy(['status' => PrintJobStatus::Printing]) as $job) {
            if ($job->getUpdatedAt() < $now->modify(self::STUCK_AFTER)) {
                $job->markFailed('Interrupted while printing (worker restarted): print it again if it did not come out.', false);
            }
        }
        $this->em->flush();

        if ($this->retentionDays < 0) {
            return;
        }
        do {
            $batch = $this->jobs->findPurgeable($now->modify(\sprintf('-%d days', $this->retentionDays)));
            foreach ($batch as $job) {
                $this->storage->delete($job);
                $job->markContentPurged();
            }
            $this->em->flush();
        } while (\count($batch) > 0);
    }
}
