<?php

namespace App\Enum;

/** How Rocket Print reaches a printer. */
enum PrinterConnectorType: string
{
    /** A printer shared by a Windows or Samba print server (smbclient). */
    case Samba = 'samba';
    /** A network printer or a CUPS queue speaking IPP (ipp://, ipps://, http://…). */
    case Ipp = 'ipp';
    /** A directory where documents are written instead of printed: tests, demo, archiving. */
    case Folder = 'folder';

    public function label(): string
    {
        return match ($this) {
            self::Samba => 'Partage Windows / Samba',
            self::Ipp => 'IPP / CUPS',
            self::Folder => 'Dossier (test)',
        };
    }
}
