<?php

namespace App\Print;

use App\Message\CleanUpPrintJobs;
use Rocket\Core\Scheduler\RecurringTaskProviderInterface;
use Symfony\Component\Scheduler\RecurringMessage;

final class PrintRecurringTasks implements RecurringTaskProviderInterface
{
    public function recurringMessages(): iterable
    {
        // Documents of finished print jobs past PRINT_RETENTION_DAYS, jobs interrupted while printing.
        yield RecurringMessage::every('1 hour', new CleanUpPrintJobs());
    }
}
