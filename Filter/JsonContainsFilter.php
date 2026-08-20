<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Filter for JSON array columns (e.g. roles stored as JSON).
 *
 * Checks if the JSON column contains the given value using PostgreSQL's
 * text cast + LIKE operator.
 *
 * Usage:
 *   #[ApiFilter(JsonContainsFilter::class, properties: ['roles'])]
 *   GET /api/users?roles=ROLE_SUPER_ADMIN
 */
final class JsonContainsFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!\is_string($value) || '' === $value) {
            return;
        }

        if (!$this->isPropertyEnabled($property, $resourceClass)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $paramName = $queryNameGenerator->generateParameterName($property);

        $queryBuilder
            ->andWhere(\sprintf('JSON_TEXT(%s.%s) LIKE :%s', $alias, $property, $paramName))
            ->setParameter($paramName, '%'.$value.'%');
    }

    public function getDescription(string $resourceClass): array
    {
        $description = [];

        foreach (array_keys($this->getProperties() ?? []) as $property) {
            // API Platform décrit ses filtres par un tableau dont la clé **et** la propriété sont
            // des chaînes : une clé numérique passerait le typage PHP et casserait la description
            // OpenAPI en silence.
            $property = (string) $property;

            $description[$property] = [
                'property' => $property,
                'type' => 'string',
                'required' => false,
                'description' => 'Filter by JSON array content (contains)',
            ];
        }

        return $description;
    }
}
