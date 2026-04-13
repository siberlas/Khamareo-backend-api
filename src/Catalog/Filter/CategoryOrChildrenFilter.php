<?php

namespace App\Catalog\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\PropertyInfo\Type;

class CategoryOrChildrenFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if ('category.slug' !== $property) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $param = $queryNameGenerator->generateParameterName($property);

        // Produits dont :
        // - la catégorie principale correspond OU son parent correspond
        // - OU une catégorie secondaire correspond OU son parent correspond
        $queryBuilder
            ->leftJoin(sprintf('%s.category', $alias), 'c')
            ->leftJoin('c.parent', 'p')
            ->leftJoin(sprintf('%s.categories', $alias), 'sc')
            ->leftJoin('sc.parent', 'scp')
            ->andWhere(
                'c.slug = :' . $param .
                ' OR p.slug = :' . $param .
                ' OR sc.slug = :' . $param .
                ' OR scp.slug = :' . $param
            )
            ->setParameter($param, $value);

        // Éviter les doublons — utiliser GROUP BY sur l'ID au lieu de DISTINCT (incompatible avec json columns)
        $queryBuilder->groupBy(sprintf('%s.id', $alias));
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'category' => [
                'property' => 'category',
                'type' => Type::BUILTIN_TYPE_STRING,
                'required' => false,
                'swagger' => [
                    'description' => 'Filtre les produits par catégorie ou sous-catégorie (slug) — cherche dans la catégorie principale et les catégories secondaires',
                ],
            ],
        ];
    }
}
