<?php

namespace App\Catalog\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Doctrine\ORM\EntityManagerInterface;

class ManualTranslationProvider implements ProviderInterface
{
    public function __construct(
        private ProviderInterface $decorated,
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        // Charger l'entité normalement
        $result = $this->decorated->provide($operation, $uriVariables, $context);
        
        if (!$result) {
            return null;
        }

        // Récupérer la locale
        $request = $this->requestStack->getCurrentRequest();
        $locale = $request?->getLocale() ?? 'fr';
        
        error_log("=== ManualTranslationProvider: locale = $locale ===");

        // Si français (locale par défaut), pas de traduction
        if ($locale === 'fr') {
            error_log("=== Locale is FR, no translation needed ===");
            return $result;
        }

        // Appliquer les traductions
        $this->applyTranslations($result, $locale);

        return $result;
    }

    private function applyTranslations(object $entity, string $locale): void
    {
        $className = get_class($entity);
        
        // Récupérer l'ID de l'entité
        if (!method_exists($entity, 'getId')) {
            error_log("=== Entity has no getId() method ===");
            return;
        }
        
        $entityId = (string) $entity->getId();
        
        error_log("=== Searching translations: class=$className, id=$entityId, locale=$locale ===");

        // Chercher les traductions dans ext_translations
        $sql = 'SELECT field, content FROM ext_translations 
                WHERE locale = :locale 
                AND object_class = :class 
                AND foreign_key = :id';
        
        $translations = $this->entityManager->getConnection()->fetchAllAssociative(
            $sql,
            [
                'locale' => $locale,
                'class' => $className,
                'id' => $entityId,
            ]
        );

        error_log("=== Found " . count($translations) . " translation(s) ===");

        // Appliquer chaque traduction
        foreach ($translations as $translation) {
            $field = $translation['field'];
            $content = $translation['content'];
            $setter = 'set' . ucfirst($field);
            
            if (method_exists($entity, $setter)) {
                $entity->$setter($content);
                $preview = substr($content, 0, 40);
                error_log("=== Applied: $field = '$preview...' ===");
            } else {
                error_log("=== Warning: Method $setter not found ===");
            }
        }
        
        // Vérifier le résultat
        if (method_exists($entity, 'getName')) {
            $finalName = $entity->getName();
            error_log("=== Final product name: $finalName ===");
        }
    }
}