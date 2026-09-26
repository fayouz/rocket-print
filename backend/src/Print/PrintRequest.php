<?php

namespace App\Print;

/** What a connector prints: a document on disk and the options chosen. */
final class PrintRequest
{
    public function __construct(
        public readonly string $path,
        public readonly string $title,
        public readonly string $mimeType,
        public readonly string $user,
        public readonly int $copies = 1,
        public readonly bool $duplex = false,
        public readonly bool $color = false,
        public readonly string $reference = '',
    ) {
    }
}
