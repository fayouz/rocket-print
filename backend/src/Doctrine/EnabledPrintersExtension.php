<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Printer;
use Doctrine\ORM\QueryBuilder;

/** GET /api/printers: the printers people can print on (administrators manage all of them under /api/admin/printers). */
final class EnabledPrintersExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Printer::class !== $resourceClass || 'printers' !== $operation?->getName()) {
            return;
        }
        $queryBuilder->andWhere(\sprintf('%s.enabled = true', $queryBuilder->getRootAliases()[0]));
    }
}
