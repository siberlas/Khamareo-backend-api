<?php

namespace App\Filter;

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

        // produits dont la catégorie correspond OU dont la sous-catégorie a ce parent
        $queryBuilder
            ->leftJoin(sprintf('%s.category', $alias), 'c')
            ->leftJoin('c.parent', 'p')
            ->andWhere('c.slug = :'.$param.' OR p.slug = :'.$param)
            ->setParameter($param, $value);
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'category' => [
                'property' => 'category',
                'type' => Type::BUILTIN_TYPE_STRING,
                'required' => false,
                'swagger' => [
                    'description' => 'Filtre les produits par catégorie ou sous-catégorie (slug)',
                ],
            ],
        ];
    }
}
