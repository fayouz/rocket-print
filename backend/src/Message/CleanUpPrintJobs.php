<?php

namespace App\Message;

/** Deletes the documents of finished jobs past the retention period and fails jobs stuck while printing (scheduler). */
final class CleanUpPrintJobs implements AsyncMessageInterface
{
}
