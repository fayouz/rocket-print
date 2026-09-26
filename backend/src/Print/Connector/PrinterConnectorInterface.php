<?php

namespace App\Print\Connector;

use App\Entity\Printer;
use App\Enum\PrinterConnectorType;
use App\Print\PrintException;
use App\Print\PrintRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Sends documents to one kind of printer (Samba share, IPP, folder…). */
#[AutoconfigureTag('app.printer_connector')]
interface PrinterConnectorInterface
{
    public function type(): PrinterConnectorType;

    /**
     * @return string|null reference given by the printer or the server (job id…)
     *
     * @throws PrintException
     */
    public function print(Printer $printer, PrintRequest $request): ?string;

    /**
     * Checks that the printer answers, without printing anything.
     *
     * @return string what was found, e.g. "Prête · HP LaserJet"
     *
     * @throws PrintException
     */
    public function check(Printer $printer): string;
}
