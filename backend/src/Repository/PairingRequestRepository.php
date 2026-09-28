<?php

namespace App\Repository;

use App\Secret\SecretStoreInterface;
use App\Entity\PairingRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PairingRequest> */
class PairingRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PairingRequest::class);
    }

    public function findOneBySecret(string $secret): ?PairingRequest
    {
        return $this->findOneBy(['secretHash' => hash('sha256', $secret)]);
    }

    /** The open request with this code (not expired, not yet paired). */
    public function findOpenByCode(string $code, \DateTimeImmutable $now): ?PairingRequest
    {
        return $this->createQueryBuilder('p')
            ->where('p.code = :code AND p.expiresAt > :now AND p.screen IS NULL')
            ->setParameter('code', PairingRequest::normaliseCode($code))
            ->setParameter('now', $now)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Deletes the expired requests, and the kiosk tokens they still kept sealed. */
    public function purgeExpired(\DateTimeImmutable $now, ?SecretStoreInterface $secrets = null): int
    {
        if (null !== $secrets) {
            $sealed = $this->createQueryBuilder('p')->select('p.sealedToken')->where('p.expiresAt <= :now AND p.sealedToken IS NOT NULL')
                ->setParameter('now', $now)->getQuery()->getSingleColumnResult();
            foreach ($sealed as $token) {
                $secrets->forget((string) $token);
            }
        }
        return $this->createQueryBuilder('p')->delete()->where('p.expiresAt <= :now')->setParameter('now', $now)->getQuery()->execute();
    }
}
