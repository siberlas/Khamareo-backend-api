<?php

namespace App\Media\Service;

use Cloudinary\Cloudinary;
use Psr\Log\LoggerInterface;

class CloudinaryService
{
    private Cloudinary $cloudinary;
    private string $cloudName;

    public function __construct(
        string $cloudName,
        string $apiKey,
        string $apiSecret,
        private LoggerInterface $logger
    ) {
        $this->cloudName = $cloudName;

        // Configuration Cloudinary
        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => $cloudName,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ],
        ]);
    }

    /**
     * Liste les images avec Search API (recommandé - plus puissant)
     * 
     * @param string $folder Dossier à rechercher (ex: "khamareo/blog")
     * @param int $maxResults Nombre maximum de résultats
     * @param array $tags Filtrer par tags (optionnel)
     * @return array Liste des images
     */
    public function listImages(
        string $folder = 'khamareo/blog', 
        int $maxResults = 100,
        array $tags = []
    ): array {
        try {
            // Construire l'expression de recherche
            $expression = "resource_type:image AND folder:{$folder}";
            
            // Ajouter filtre par tags si spécifié
            if (!empty($tags)) {
                $tagsQuery = implode(' OR ', array_map(fn($tag) => "tags={$tag}", $tags));
                $expression .= " AND ({$tagsQuery})";
            }

            // Utiliser Search API (plus puissant que assets())
            $result = $this->cloudinary->searchApi()->expression($expression)
                ->maxResults($maxResults)
                ->execute();

            $images = [];
            
            foreach ($result['resources'] as $resource) {
                $images[] = [
                    'assetId' => $resource['asset_id'],  // Clé primaire immuable
                    'publicId' => $resource['public_id'],
                    'url' => $resource['secure_url'],
                    'width' => $resource['width'],
                    'height' => $resource['height'],
                    'format' => $resource['format'],
                    'bytes' => $resource['bytes'],
                    'createdAt' => $resource['created_at'],
                    'tags' => $resource['tags'] ?? [],
                    'folder' => $resource['folder'] ?? null,
                    'displayName' => $resource['display_name'] ?? null,
                    'thumbnail' => $this->generateThumbnailUrl($resource['public_id']),
                ];
            }

            $this->logger->info('Cloudinary search completed', [
                'folder' => $folder,
                'count' => count($images),
                'expression' => $expression
            ]);

            return [
                'success' => true,
                'images' => $images,
                'total' => count($images),
            ];

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary search failed', [
                'folder' => $folder,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'images' => [],
                'total' => 0,
            ];
        }
    }

    /**
     * Récupère les détails d'une image par asset_id (recommandé)
     * 
     * @param string $assetId Asset ID (immuable)
     * @return array|null Détails de l'image
     */
    public function getImageByAssetId(string $assetId): array
    {
        try {
            $result = $this->cloudinary->adminApi()->assetByAssetId($assetId);

            return [
                'success' => true,
                'image' => [
                    'assetId' => $result['asset_id'],
                    'publicId' => $result['public_id'],
                    'url' => $result['secure_url'],
                    'width' => $result['width'],
                    'height' => $result['height'],
                    'format' => $result['format'],
                    'bytes' => $result['bytes'],
                    'createdAt' => $result['created_at'],
                    'tags' => $result['tags'] ?? [],
                    'folder' => $result['folder'] ?? null,
                    'displayName' => $result['display_name'] ?? null,
                    'thumbnail' => $this->generateThumbnailUrl($result['public_id']),
                ],
            ];

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary get image by asset_id failed', [
                'assetId' => $assetId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Récupère les détails d'une image par public_id
     * 
     * @param string $publicId Public ID de l'image
     * @return array Détails de l'image
     */
    public function getImageByPublicId(string $publicId): array
    {
        try {
            $result = $this->cloudinary->adminApi()->asset($publicId);

            return [
                'success' => true,
                'image' => [
                    'assetId' => $result['asset_id'],
                    'publicId' => $result['public_id'],
                    'url' => $result['secure_url'],
                    'width' => $result['width'],
                    'height' => $result['height'],
                    'format' => $result['format'],
                    'bytes' => $result['bytes'],
                    'createdAt' => $result['created_at'],
                    'tags' => $result['tags'] ?? [],
                    'folder' => $result['folder'] ?? null,
                    'displayName' => $result['display_name'] ?? null,
                    'thumbnail' => $this->generateThumbnailUrl($result['public_id']),
                ],
            ];

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary get image by public_id failed', [
                'publicId' => $publicId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Met à jour une image avec Explicit API (recommandé - pas de rate limit)
     * 
     * @param string $assetId Asset ID
     * @param array $data Données à mettre à jour (tags, context)
     * @return array Résultat
     */
    public function updateImage(string $assetId, array $data): array
    {
        try {
            // 1. Récupérer le publicId depuis l'assetId
            $asset = $this->getImageByAssetId($assetId);
            
            if (!$asset['success']) {
                return [
                    'success' => false,
                    'error' => 'Asset non trouvé',
                    'message' => 'Impossible de trouver l\'image avec cet asset_id'
                ];
            }

            $publicId = $asset['image']['publicId'];

            // 2. Construire les paramètres Explicit API
            $explicitParams = [
                'type' => 'upload',
            ];

            if (isset($data['tags'])) {
                $explicitParams['tags'] = $data['tags'];
            }

            if (isset($data['context'])) {
                // Context format: key1=value1|key2=value2
                $explicitParams['context'] = $data['context'];
            }

            // 3. Utiliser Explicit API (pas de rate limit)
            $result = $this->cloudinary->uploadApi()->explicit($publicId, $explicitParams);

            $this->logger->info('Cloudinary image updated via explicit', [
                'assetId' => $assetId,
                'publicId' => $publicId,
                'updates' => array_keys($data)
            ]);

            return [
                'success' => true,
                'assetId' => $assetId,
                'publicId' => $result['public_id'],
                'tags' => $result['tags'] ?? [],
                'url' => $result['secure_url'],
                'message' => 'Image mise à jour avec succès'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary explicit update failed', [
                'assetId' => $assetId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Erreur lors de la mise à jour de l\'image'
            ];
        }
    }

    /**
     * Ajoute des tags à une image (via Explicit API)
     * 
     * @param string $assetId Asset ID
     * @param array $tags Tags à ajouter
     * @return array Résultat
     */
    public function addTags(string $assetId, array $tags): array
    {
        try {
            // Récupérer les tags actuels
            $current = $this->getImageByAssetId($assetId);
            
            if (!$current['success']) {
                return $current;
            }

            $existingTags = $current['image']['tags'] ?? [];
            $newTags = array_unique(array_merge($existingTags, $tags));

            return $this->updateImage($assetId, ['tags' => $newTags]);

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary add tags failed', [
                'assetId' => $assetId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Supprime des tags d'une image
     * 
     * @param string $assetId Asset ID
     * @param array $tags Tags à supprimer
     * @return array Résultat
     */
    public function removeTags(string $assetId, array $tags): array
    {
        try {
            $current = $this->getImageByAssetId($assetId);
            
            if (!$current['success']) {
                return $current;
            }

            $existingTags = $current['image']['tags'] ?? [];
            $newTags = array_diff($existingTags, $tags);

            return $this->updateImage($assetId, ['tags' => array_values($newTags)]);

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary remove tags failed', [
                'assetId' => $assetId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Supprime une image par asset_id (recommandé)
     * Invalide automatiquement le cache CDN
     * 
     * @param string $assetId Asset ID
     * @return array Résultat
     */
    public function deleteImage(string $assetId): array
    {
        try {
            $result = $this->cloudinary->adminApi()->deleteAssetsByAssetIds(
                [$assetId],
                ['invalidate' => true]  // Invalide le cache CDN
            );

            $success = !empty($result['deleted']);

            if ($success) {
                $this->logger->info('Cloudinary image deleted', [
                    'assetId' => $assetId
                ]);
            }

            return [
                'success' => $success,
                'assetId' => $assetId,
                'deleted' => $result['deleted'] ?? [],
                'message' => $success ? 'Image supprimée avec succès' : 'Échec de la suppression'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary delete image failed', [
                'assetId' => $assetId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Erreur lors de la suppression de l\'image'
            ];
        }
    }

    /**
     * Supprime une image par public_id (alternative)
     * 
     * @param string $publicId Public ID
     * @return array Résultat
     */
    public function deleteImageByPublicId(string $publicId): array
    {
        try {
            $result = $this->cloudinary->uploadApi()->destroy($publicId, [
                'invalidate' => true
            ]);

            $success = $result['result'] === 'ok';

            if ($success) {
                $this->logger->info('Cloudinary image deleted', [
                    'publicId' => $publicId
                ]);
            }

            return [
                'success' => $success,
                'publicId' => $publicId,
                'result' => $result['result'],
                'message' => $success ? 'Image supprimée avec succès' : 'Échec de la suppression'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary delete image failed', [
                'publicId' => $publicId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Erreur lors de la suppression de l\'image'
            ];
        }
    }

    /**
     * Upload une image (signed - pour backend)
     * 
     * @param string $file Chemin du fichier ou URL
     * @param array $options Options d'upload
     * @return array Résultat avec asset_id
     */
    public function uploadImage(string $file, array $options = []): array
    {
        $defaultOptions = [
            'asset_folder' => 'khamareo/blog',
            'tags' => ['blog'],
            'resource_type' => 'image',
        ];

        $mergedOptions = array_merge($defaultOptions, $options);

        try {
            $result = $this->cloudinary->uploadApi()->upload($file, $mergedOptions);

            $this->logger->info('Cloudinary upload successful', [
                'asset_id' => $result['asset_id'],
                'public_id' => $result['public_id']
            ]);

            return [
                'success' => true,
                'assetId' => $result['asset_id'],
                'publicId' => $result['public_id'],
                'url' => $result['secure_url'],
                'width' => $result['width'] ?? null,
                'height' => $result['height'] ?? null,
                'format' => $result['format'] ?? null,
                'bytes' => $result['bytes'] ?? null,
                'tags' => $result['tags'] ?? [],
            ];

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary upload failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Recherche avancée avec expression personnalisée
     * 
     * @param string $expression Expression de recherche Cloudinary
     * @param int $maxResults Nombre maximum de résultats
     * @return array Résultats
     */
    public function search(string $expression, int $maxResults = 100): array
    {
        try {
            $result = $this->cloudinary->searchApi()->expression($expression)
                ->maxResults($maxResults)
                ->execute();

            $images = [];
            
            foreach ($result['resources'] as $resource) {
                $images[] = [
                    'assetId' => $resource['asset_id'],
                    'publicId' => $resource['public_id'],
                    'url' => $resource['secure_url'],
                    'width' => $resource['width'],
                    'height' => $resource['height'],
                    'format' => $resource['format'],
                    'bytes' => $resource['bytes'],
                    'createdAt' => $resource['created_at'],
                    'tags' => $resource['tags'] ?? [],
                    'folder' => $resource['folder'] ?? null,
                    'thumbnail' => $this->generateThumbnailUrl($resource['public_id']),
                ];
            }

            $this->logger->info('Cloudinary search completed', [
                'expression' => $expression,
                'count' => count($images)
            ]);

            return [
                'success' => true,
                'images' => $images,
                'total' => count($images),
            ];

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary search failed', [
                'expression' => $expression,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'images' => [],
                'total' => 0,
            ];
        }
    }

    /**
     * Obtient les statistiques d'un dossier
     * 
     * @param string $folder Dossier à analyser
     * @return array Statistiques
     */
    public function getFolderStats(string $folder = 'khamareo/blog'): array
    {
        try {
            $result = $this->search("resource_type:image AND folder:{$folder}", 500);

            if (!$result['success']) {
                return $result;
            }

            $totalSize = 0;
            $formats = [];
            $tags = [];

            foreach ($result['images'] as $image) {
                $totalSize += $image['bytes'];
                
                $format = $image['format'];
                if (!isset($formats[$format])) {
                    $formats[$format] = 0;
                }
                $formats[$format]++;

                foreach ($image['tags'] as $tag) {
                    if (!isset($tags[$tag])) {
                        $tags[$tag] = 0;
                    }
                    $tags[$tag]++;
                }
            }

            return [
                'success' => true,
                'stats' => [
                    'totalImages' => $result['total'],
                    'totalSize' => $totalSize,
                    'totalSizeMB' => round($totalSize / 1024 / 1024, 2),
                    'formats' => $formats,
                    'tags' => $tags,
                    'folder' => $folder,
                ],
            ];

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary folder stats failed', [
                'folder' => $folder,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Télécharge le contenu d'une ressource raw Cloudinary via URL signée (Admin API).
     * Contourne les ACL/restrictions du compte.
     *
     * @param string $cloudinaryUrl URL publique stockée (ex: https://res.cloudinary.com/...)
     * @return string|false Contenu binaire du fichier, ou false en cas d'échec
     */
    public function downloadRawContent(string $cloudinaryUrl): string|false
    {
        // Extraire le public_id depuis l'URL stockée
        // Format: .../raw/upload/v{version}/{public_id}.{ext}  ou  .../raw/upload/{public_id}.{ext}
        if (!preg_match('#/raw/upload/(?:v\d+/)?(.+)$#', $cloudinaryUrl, $matches)) {
            $this->logger->error('Cannot extract public_id from Cloudinary URL', ['url' => $cloudinaryUrl]);
            return false;
        }
        $publicId = $matches[1];

        try {
            // Génère une URL signée temporaire via UploadApi::privateDownloadUrl()
            // 'type' => 'upload' est obligatoire pour les assets raw uploadés normalement
            $signedUrl = $this->cloudinary->uploadApi()->privateDownloadUrl($publicId, '', [
                'resource_type' => 'raw',
                'type'          => 'upload',
                'attachment'    => true,
            ]);

            $content = file_get_contents($signedUrl);
            if ($content === false) {
                $this->logger->error('Failed to fetch Cloudinary signed URL', ['public_id' => $publicId]);
            }
            return $content;

        } catch (\Exception $e) {
            $this->logger->error('Cloudinary downloadRawContent failed', [
                'public_id' => $publicId,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Génère une URL de thumbnail optimisée
     * 
     * @param string $publicId Public ID de l'image
     * @param int $width Largeur du thumbnail
     * @param int $height Hauteur du thumbnail
     * @return string URL du thumbnail
     */
    public function generateThumbnailUrl(
        string $publicId, 
        int $width = 300, 
        int $height = 200
    ): string {
        return sprintf(
            'https://res.cloudinary.com/%s/image/upload/w_%d,h_%d,c_fill,q_auto,f_auto/%s',
            $this->cloudName,
            $width,
            $height,
            $publicId
        );
    }

    /**
     * Génère une URL transformée personnalisée
     * 
     * @param string $publicId Public ID de l'image
     * @param array $transformations Transformations à appliquer
     * @return string URL transformée
     */
    public function generateTransformedUrl(string $publicId, array $transformations = []): string
    {
        $baseUrl = sprintf(
            'https://res.cloudinary.com/%s/image/upload',
            $this->cloudName
        );

        if (empty($transformations)) {
            return sprintf('%s/%s', $baseUrl, $publicId);
        }

        $transformString = $this->buildTransformationString($transformations);
        return sprintf('%s/%s/%s', $baseUrl, $transformString, $publicId);
    }

    /**
     * Construit la chaîne de transformation Cloudinary
     * 
     * @param array $transformations Tableau des transformations
     * @return string Chaîne de transformation
     */
    private function buildTransformationString(array $transformations): string
    {
        $parts = [];

        if (isset($transformations['width'])) {
            $parts[] = 'w_' . $transformations['width'];
        }
        if (isset($transformations['height'])) {
            $parts[] = 'h_' . $transformations['height'];
        }
        if (isset($transformations['crop'])) {
            $parts[] = 'c_' . $transformations['crop'];
        }
        if (isset($transformations['quality'])) {
            $parts[] = 'q_' . $transformations['quality'];
        } else {
            $parts[] = 'q_auto';
        }
        if (isset($transformations['format'])) {
            $parts[] = 'f_' . $transformations['format'];
        } else {
            $parts[] = 'f_auto';
        }

        return implode(',', $parts);
    }
}