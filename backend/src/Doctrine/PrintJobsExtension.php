<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\PrintJob;
use Rocket\Core\Security\ActorContext;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/** Everyone lists their own print jobs; administrators list all of them with ?all=1. */
final class PrintJobsExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly Security $security,
        private readonly RequestStack $requests,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (PrintJob::class !== $resourceClass) {
            return;
        }
        $alias = $queryBuilder->getRootAliases()[0];
        if ($this->security->isGranted('ROLE_ADMIN') && $this->requests->getCurrentRequest()?->query->getBoolean('all')) {
            return;
        }
        $user = $this->actor->getUser();
        if (null === $user) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $param = $queryNameGenerator->generateParameterName('owner');
        $queryBuilder->andWhere(\sprintf('%s.owner = :%s', $alias, $param))->setParameter($param, $user->getId(), 'uuid');
    }
}
