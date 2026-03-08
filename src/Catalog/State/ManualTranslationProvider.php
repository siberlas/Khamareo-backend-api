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
        $result = $this->decorated->provide($operation, $uriVariables, $context);

        if (!$result) {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest();
        $locale = $request?->getLocale() ?? 'fr';

        if ($locale === 'fr') {
            return $result;
        }

        $this->applyTranslations($result, $locale);

        return $result;
    }

    private function applyTranslations(object $entity, string $locale): void
    {
        $className = get_class($entity);

        if (!method_exists($entity, 'getId')) {
            return;
        }

        $entityId = (string) $entity->getId();

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

        foreach ($translations as $translation) {
            $field = $translation['field'];
            $content = $translation['content'];
            $setter = 'set' . ucfirst($field);

            if (method_exists($entity, $setter)) {
                $entity->$setter($content);
            }
        }
    }
}
