<?php
// src/Service/MediaService.php

namespace App\Media\Service;

use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Media\Service\CloudinaryService;
use App\Media\Entity\Media;

class MediaService
{
    public function __construct(
        private CloudinaryService $cloudinaryService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    /**
     * Upload une image vers Cloudinary et crée l'entité Media
     */
    public function uploadImage(
        UploadedFile $file,
        string $folder = 'khamareo',
        array $tags = [],
        ?User $uploadedBy = null,
        ?string $altText = null
    ): Media {
        try {
            // 1. Upload vers Cloudinary
            $uploadResult = $this->cloudinaryService->uploadImage(
                $file->getPathname(),
                [
                    'asset_folder' => $folder,
                    'tags' => $tags,
                    'resource_type' => 'image',
                ]
            );

            if (!$uploadResult['success']) {
                throw new \Exception('Upload Cloudinary échoué : ' . ($uploadResult['error'] ?? 'Erreur inconnue'));
            }

            // 2. Créer l'entité Media
            $media = new Media();
            $media->setCloudinaryPublicId($uploadResult['publicId']);
            $media->setCloudinaryAssetId($uploadResult['assetId'] ?? null);
            $media->setUrl($uploadResult['url']);
            $media->setFilename($file->getClientOriginalName());
            $media->setAltText($altText ?? $file->getClientOriginalName());
            $media->setMediaType('image');
            $media->setMimeType($file->getMimeType());
            $media->setWidth($uploadResult['width'] ?? null);
            $media->setHeight($uploadResult['height'] ?? null);
            $media->setFileSize($uploadResult['bytes'] ?? $file->getSize());
            $media->setTags($tags);
            $media->setFolder($folder);
            $media->setCreatedBy($uploadedBy);

            // 3. Générer thumbnail URL
            $thumbnailUrl = $this->cloudinaryService->generateThumbnailUrl(
                $uploadResult['publicId'],
                400,
                300
            );
            $media->setThumbnailUrl($thumbnailUrl);

            // 4. Sauvegarder en BDD
            $this->em->persist($media);
            $this->em->flush();

            $this->logger->info('Media uploaded successfully', [
                'media_id' => $media->getId()->toRfc4122(),
                'cloudinary_public_id' => $uploadResult['publicId'],
                'filename' => $file->getClientOriginalName(),
            ]);

            return $media;

        } catch (\Exception $e) {
            $this->logger->error('Media upload failed', [
                'error' => $e->getMessage(),
                'filename' => $file->getClientOriginalName(),
            ]);
            throw $e;
        }
    }

    /**
     * Upload depuis une URL (pour migration)
     */
    public function uploadFromUrl(
        string $url,
        string $folder = 'khamareo',
        array $tags = [],
        ?string $altText = null
    ): Media {
        try {
            // 1. Upload vers Cloudinary depuis URL
            $uploadResult = $this->cloudinaryService->uploadImage($url, [
                'asset_folder' => $folder,
                'tags' => $tags,
                'resource_type' => 'image',
            ]);

            if (!$uploadResult['success']) {
                throw new \Exception('Upload Cloudinary échoué : ' . ($uploadResult['error'] ?? 'Erreur inconnue'));
            }

            // 2. Créer l'entité Media
            $media = new Media();
            $media->setCloudinaryPublicId($uploadResult['publicId']);
            $media->setCloudinaryAssetId($uploadResult['assetId'] ?? null);
            $media->setUrl($uploadResult['url']);
            $media->setFilename(basename($url));
            $media->setAltText($altText ?? basename($url));
            $media->setMediaType('image');
            $media->setWidth($uploadResult['width'] ?? null);
            $media->setHeight($uploadResult['height'] ?? null);
            $media->setFileSize($uploadResult['bytes'] ?? null);
            $media->setTags($tags);
            $media->setFolder($folder);

            // 3. Générer thumbnail
            $thumbnailUrl = $this->cloudinaryService->generateThumbnailUrl(
                $uploadResult['publicId'],
                400,
                300
            );
            $media->setThumbnailUrl($thumbnailUrl);

            // 4. Sauvegarder
            $this->em->persist($media);
            $this->em->flush();

            $this->logger->info('Media uploaded from URL', [
                'media_id' => $media->getId()->toRfc4122(),
                'source_url' => $url,
            ]);

            return $media;

        } catch (\Exception $e) {
            $this->logger->error('Media upload from URL failed', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            throw $e;
        }
    }

    /**
     * Mettre à jour les métadonnées d'un média
     */
    public function updateMedia(
        Media $media,
        ?string $altText = null,
        ?array $tags = null
    ): Media {
        if ($altText !== null) {
            $media->setAltText($altText);
        }

        if ($tags !== null) {
            $media->setTags($tags);

            // Mettre à jour aussi sur Cloudinary
            try {
                $this->cloudinaryService->updateImage(
                    $media->getCloudinaryAssetId() ?? $media->getCloudinaryPublicId(),
                    ['tags' => $tags]
                );
            } catch (\Exception $e) {
                $this->logger->warning('Failed to update Cloudinary tags', [
                    'media_id' => $media->getId()->toRfc4122(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();

        return $media;
    }

    /**
     * Supprimer un média (BDD + Cloudinary)
     */
    public function deleteMedia(Media $media, bool $deleteFromCloudinary = true): void
    {
        try {
            $publicId = $media->getCloudinaryPublicId();
            $assetId = $media->getCloudinaryAssetId();

            // 1. Supprimer de la BDD
            $this->em->remove($media);
            $this->em->flush();

            // 2. Supprimer de Cloudinary
            if ($deleteFromCloudinary) {
                if ($assetId) {
                    $this->cloudinaryService->deleteImage($assetId);
                } else {
                    $this->cloudinaryService->deleteImageByPublicId($publicId);
                }
            }

            $this->logger->info('Media deleted', [
                'media_id' => $media->getId()->toRfc4122(),
                'cloudinary_public_id' => $publicId,
                'deleted_from_cloudinary' => $deleteFromCloudinary,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Media deletion failed', [
                'media_id' => $media->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Synchroniser avec Cloudinary (récupérer métadonnées à jour)
     */
    public function syncWithCloudinary(Media $media): Media
    {
        try {
            $cloudinaryData = $this->cloudinaryService->getImageByAssetId(
                $media->getCloudinaryAssetId() ?? $media->getCloudinaryPublicId()
            );

            if ($cloudinaryData['success']) {
                $image = $cloudinaryData['image'];
                
                $media->setUrl($image['url']);
                $media->setWidth($image['width']);
                $media->setHeight($image['height']);
                $media->setFileSize($image['bytes']);
                $media->setTags($image['tags']);

                // Régénérer thumbnail
                $thumbnailUrl = $this->cloudinaryService->generateThumbnailUrl(
                    $media->getCloudinaryPublicId(),
                    400,
                    300
                );
                $media->setThumbnailUrl($thumbnailUrl);

                $this->em->flush();

                $this->logger->info('Media synced with Cloudinary', [
                    'media_id' => $media->getId()->toRfc4122(),
                ]);
            }

            return $media;

        } catch (\Exception $e) {
            $this->logger->error('Media sync failed', [
                'media_id' => $media->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Récupérer les statistiques des médias
     */
    public function getStats(): array
    {
        $qb = $this->em->createQueryBuilder();
        
        $result = $qb->select('COUNT(m.id) as total, SUM(m.fileSize) as totalSize')
            ->from(Media::class, 'm')
            ->getQuery()
            ->getSingleResult();

        // Stats par folder
        $byFolder = $this->em->createQueryBuilder()
            ->select('m.folder, COUNT(m.id) as count')
            ->from(Media::class, 'm')
            ->groupBy('m.folder')
            ->getQuery()
            ->getResult();

        return [
            'totalMedia' => (int) $result['total'],
            'totalSize' => (int) ($result['totalSize'] ?? 0),
            'totalSizeMB' => round((int) ($result['totalSize'] ?? 0) / 1024 / 1024, 2),
            'byFolder' => $byFolder,
        ];
    }

    /**
     * Rechercher des médias
     */
    public function search(
        ?string $query = null,
        ?array $tags = null,
        ?string $folder = null,
        int $limit = 50
    ): array {
        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm');

        if ($query) {
            $qb->andWhere('m.filename LIKE :query OR m.altText LIKE :query')
               ->setParameter('query', '%' . $query . '%');
        }

        if ($tags && !empty($tags)) {
            // Recherche dans les tags (JSON array)
            foreach ($tags as $index => $tag) {
                $qb->andWhere("JSON_CONTAINS(m.tags, :tag{$index}) = 1")
                   ->setParameter("tag{$index}", json_encode($tag));
            }
        }

        if ($folder) {
            $qb->andWhere('m.folder = :folder')
               ->setParameter('folder', $folder);
        }

        $qb->orderBy('m.createdAt', 'DESC')
           ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }
}