<?php

namespace App\Print;

/** A printer or a print server refused a document, or could not be reached. */
final class PrintException extends \RuntimeException
{
    /** @param bool $retryable worth another attempt (network, printer busy…), not a refused document or wrong credentials */
    public function __construct(string $message, public readonly bool $retryable = true, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
