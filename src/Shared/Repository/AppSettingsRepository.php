<?php

namespace App\Shared\Repository;

use App\Shared\Entity\AppSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AppSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSettings::class);
    }

    public function findByKey(string $key): ?AppSettings
    {
        return $this->findOneBy(['settingKey' => $key]);
    }
}
