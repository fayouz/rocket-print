<?php

namespace App\Print;

use App\Health\ServiceProbeInterface;
use App\Print\Connector\PrinterConnectors;
use App\Repository\PrinterRepository;

/** The enabled printers on the dashboard, checked every 5 minutes by the worker. */
final class PrintersProbe implements ServiceProbeInterface
{
    public function __construct(
        private readonly PrinterRepository $printers,
        private readonly PrinterConnectors $connectors,
    ) {
    }

    public function id(): string
    {
        return 'printers';
    }

    public function label(): string
    {
        return 'Imprimantes';
    }

    public function targets(): iterable
    {
        foreach ($this->printers->findEnabled() as $printer) {
            yield $printer->getId()->toRfc4122() => [
                'name' => $printer->getName(),
                'check' => fn () => $this->connectors->for($printer)->check($printer),
            ];
        }
    }
}
