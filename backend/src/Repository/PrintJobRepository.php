<?php

namespace App\Repository;

use App\Entity\PrintJob;
use App\Enum\PrintJobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PrintJob> */
class PrintJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrintJob::class);
    }

    /** @return list<PrintJob> finished jobs created before $before whose document is still stored */
    public function findPurgeable(\DateTimeImmutable $before, int $limit = 500): array
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.contentPurged = false')
            ->andWhere('j.status IN (:finished)')
            ->andWhere('j.createdAt < :before')
            ->setParameter('finished', [PrintJobStatus::Printed, PrintJobStatus::Failed, PrintJobStatus::Cancelled])
            ->setParameter('before', $before)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
