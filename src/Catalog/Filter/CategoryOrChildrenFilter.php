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

        // Catégorie principale ou son parent
        $queryBuilder
            ->leftJoin(sprintf('%s.category', $alias), 'c')
            ->leftJoin('c.parent', 'p');

        // Catégorie secondaire ou son parent — via sous-requête pour éviter les doublons
        $subQuery = $queryBuilder->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(sc_product.id)')
            ->from($resourceClass, 'sc_product')
            ->innerJoin('sc_product.categories', 'sc_cat')
            ->leftJoin('sc_cat.parent', 'sc_parent')
            ->where('sc_cat.slug = :' . $param . ' OR sc_parent.slug = :' . $param);

        $queryBuilder
            ->andWhere(
                'c.slug = :' . $param .
                ' OR p.slug = :' . $param .
                ' OR ' . $alias . '.id IN (' . $subQuery->getDQL() . ')'
            )
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
