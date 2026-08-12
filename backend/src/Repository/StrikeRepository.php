<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Strike;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Strike>
 */
class StrikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Strike::class);
    }

    /**
     * Fulmini in un intervallo temporale e (opzionale) in un bounding box.
     * Base per le statistiche/mappe storiche private.
     *
     * @return list<Strike>
     */
    public function findInWindow(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?float $minLat = null,
        ?float $maxLat = null,
        ?float $minLon = null,
        ?float $maxLon = null,
        int $limit = 5000,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.occurredAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.occurredAt', 'DESC')
            ->setMaxResults($limit);

        if ($minLat !== null && $maxLat !== null) {
            $qb->andWhere('s.lat BETWEEN :minLat AND :maxLat')
                ->setParameter('minLat', $minLat)
                ->setParameter('maxLat', $maxLat);
        }
        if ($minLon !== null && $maxLon !== null) {
            $qb->andWhere('s.lon BETWEEN :minLon AND :maxLon')
                ->setParameter('minLon', $minLon)
                ->setParameter('maxLon', $maxLon);
        }

        return $qb->getQuery()->getResult();
    }
}
