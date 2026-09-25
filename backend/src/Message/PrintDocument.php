<?php

namespace App\Message;

/** Send a queued print job to its printer (worker). */
final class PrintDocument implements AsyncMessageInterface
{
    public function __construct(public readonly string $jobId)
    {
    }
}
