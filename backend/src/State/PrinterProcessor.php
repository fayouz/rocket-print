<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Printer;
use App\Security\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts the printer password (null keeps the current one, "" removes it) and keeps a single default printer.
 *
 * @implements ProcessorInterface<Printer, Printer>
 */
final class PrinterProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<Printer, Printer> $persist */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly SecretBox $secrets,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Printer) {
            return $this->persist->process($data, $operation, $uriVariables, $context);
        }
        if (null !== $data->getPassword()) {
            $data->setEncryptedPassword('' === $data->getPassword() ? '' : $this->secrets->encrypt($data->getPassword()));
            $data->setPassword(null);
        }
        if ($data->isDefaultPrinter()) {
            $this->em->createQuery('UPDATE App\Entity\Printer p SET p.defaultPrinter = false WHERE p.id != :id')
                ->setParameter('id', $data->getId(), 'uuid')
                ->execute();
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
