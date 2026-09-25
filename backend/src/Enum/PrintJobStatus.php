<?php

namespace App\Enum;

enum PrintJobStatus: string
{
    /** Waiting for the worker (also between two attempts). */
    case Queued = 'queued';
    /** Being sent to the printer. */
    case Printing = 'printing';
    /** Accepted by the printer or the print server. */
    case Printed = 'printed';
    /** Every attempt failed. */
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return \in_array($this, [self::Printed, self::Failed, self::Cancelled], true);
    }
}
