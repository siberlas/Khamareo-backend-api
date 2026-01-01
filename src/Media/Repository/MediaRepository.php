<?php
// src/Repository/MediaRepository.php

namespace App\Media\Repository;

use App\Media\Entity\Media;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Media>
 */
class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Media::class);
    }

    /**
     * Trouve les médias par tags
     */
    public function findByTags(array $tags): array
    {
        $qb = $this->createQueryBuilder('m');
        
        foreach ($tags as $index => $tag) {
            $qb->orWhere(':tag' . $index . ' = ANY(m.tags)')
               ->setParameter('tag' . $index, $tag);
        }
        
        return $qb->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les médias par folder
     */
    public function findByFolder(string $folder): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.folder = :folder')
            ->setParameter('folder', $folder)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche dans filename et alt_text
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.filename LIKE :query')
            ->orWhere('m.altText LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Statistiques globales
     */
    public function getStats(): array
    {
        $result = $this->createQueryBuilder('m')
            ->select('COUNT(m.id) as total, SUM(m.fileSize) as totalSize')
            ->getQuery()
            ->getSingleResult();

        return [
            'totalMedia' => (int) $result['total'],
            'totalSize' => (int) ($result['totalSize'] ?? 0),
            'totalSizeMB' => round((int) ($result['totalSize'] ?? 0) / 1024 / 1024, 2),
        ];
    }
}