<?php

namespace App\Repository;

use App\Entity\Screen;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Screen> */
class ScreenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Screen::class);
    }

    public function findOneByToken(string $token): ?Screen
    {
        return $this->findOneBy(['tokenHash' => Screen::hashToken($token)]);
    }
}
