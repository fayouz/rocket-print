<?php

namespace App\Print\Connector;

use App\Entity\Printer;
use App\Print\PrintException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The connector of each printer. */
final class PrinterConnectors
{
    /** @param iterable<PrinterConnectorInterface> $connectors */
    public function __construct(
        #[AutowireIterator('app.printer_connector')] private readonly iterable $connectors,
    ) {
    }

    public function for(Printer $printer): PrinterConnectorInterface
    {
        foreach ($this->connectors as $connector) {
            if ($connector->type() === $printer->getConnector()) {
                return $connector;
            }
        }

        throw new PrintException(\sprintf('No connector for "%s" printers.', $printer->getConnector()->value), false);
    }
}
