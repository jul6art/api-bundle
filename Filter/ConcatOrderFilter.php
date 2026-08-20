<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Concat Order Filter — allows ordering by a virtual concatenated field.
 *
 * Registers a virtual order property that sorts by CONCAT of multiple real fields.
 *
 * Usage on entity:
 *   #[ApiFilter(ConcatOrderFilter::class, properties: ['fullName' => ['firstName', 'lastName']])]
 *
 * API call:
 *   GET /api/users?order[fullName]=asc
 *   → ORDER BY firstName ASC, lastName ASC
 */
final class ConcatOrderFilter extends AbstractFilter
{
    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $orderParams = $filters['order'] ?? [];

        if (!\is_array($orderParams)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0] ?? null;

        if (!\is_string($alias)) {
            return;
        }
        $properties = $this->getProperties() ?? [];

        foreach ($orderParams as $property => $direction) {
            if (!\array_key_exists($property, $properties)) {
                continue;
            }

            $fields = $properties[$property];

            if (!\is_array($fields) || [] === $fields) {
                continue;
            }

            $direction = \is_string($direction) && 'DESC' === strtoupper($direction) ? 'DESC' : 'ASC';

            foreach ($fields as $field) {
                // Un champ qui ne serait pas une chaîne construirait un DQL invalide : on
                // l'ignore plutôt que de laisser Doctrine échouer sur une expression bâtarde.
                if (\is_string($field)) {
                    $queryBuilder->addOrderBy(\sprintf('%s.%s', $alias, $field), $direction);
                }
            }
        }
    }

    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        // Not used — apply() handles everything
    }

    public function getDescription(string $resourceClass): array
    {
        $description = [];

        foreach (array_keys($this->getProperties() ?? []) as $property) {
            $description["order[{$property}]"] = [
                'property' => $property,
                'type' => 'string',
                'required' => false,
                'description' => "Order by virtual field {$property} (asc/desc)",
                'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']],
            ];
        }

        return $description;
    }
}
