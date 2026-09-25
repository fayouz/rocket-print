<?php

namespace App\Print;

use App\Entity\Printer;
use App\Security\SecretBox;

/** The decrypted password of a printer, for its connector. */
final class PrinterSecrets
{
    public function __construct(private readonly SecretBox $secrets)
    {
    }

    public function password(Printer $printer): string
    {
        return '' === $printer->getEncryptedPassword() ? '' : $this->secrets->decrypt($printer->getEncryptedPassword());
    }
}
