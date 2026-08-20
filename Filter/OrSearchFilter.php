<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata as ORMClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;

/**
 * OR search filter — searches across multiple properties with OR logic.
 *
 * Unlike the default SearchFilter which uses AND between properties,
 * this filter applies a single search term to multiple fields with OR.
 *
 * Supports both **direct fields** (`title`, `email`) and **single-hop
 * relation paths** (`contact.firstName`, `deal.title`). Relation paths
 * trigger a `LEFT JOIN` so a missing relation simply means "no row
 * matches that branch of the OR" instead of dropping the row entirely.
 *
 * Numeric properties (`decimal`, `integer`, `float`) are coerced to string
 * via `CONCAT(field, '')` — DQL does not have a portable `CAST` function,
 * and `LOWER(numeric)` blows up in PostgreSQL with a type mismatch. Wrapping
 * the column in `CONCAT(col, '')` forces the DB to render it as text so
 * `?search=15000` matches a `Deal.amount` of `15000.00`.
 *
 * Usage on entity:
 *   #[ApiFilter(OrSearchFilter::class, properties: ['email', 'firstName', 'lastName'])]
 *   #[ApiFilter(OrSearchFilter::class, properties: ['title', 'amount'])]
 *   #[ApiFilter(OrSearchFilter::class, properties: ['title', 'type', 'contact.firstName', 'contact.lastName', 'deal.title'])]
 *
 * API call:
 *   GET /api/users?search=admin
 *   → WHERE LOWER(email) LIKE '%admin%' OR LOWER(firstName) LIKE '%admin%' OR ...
 *   GET /api/activities?search=lambert
 *   → LEFT JOIN contact JOIN deal WHERE LOWER(title) LIKE '%lambert%' OR LOWER(contact.lastName) LIKE '%lambert%' OR ...
 */
final class OrSearchFilter extends AbstractFilter
{
    public const string PARAMETER_NAME = 'search';

    /**
     * Doctrine field types cast to string before the LIKE compare.
     *
     * @var list<string>
     */
    private const array NUMERIC_TYPES = [
        Types::DECIMAL,
        Types::INTEGER,
        Types::SMALLINT,
        Types::BIGINT,
        Types::FLOAT,
    ];

    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $value = $filters[self::PARAMETER_NAME] ?? null;

        if (!\is_string($value) || '' === $value) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $orConditions = [];
        $paramName = $queryNameGenerator->generateParameterName('or_search');
        // Cache aliases for relation joins so multiple paths sharing the
        // same first segment (e.g. `contact.firstName` + `contact.lastName`)
        // reuse a single LEFT JOIN.
        $relationAliases = [];

        foreach (array_keys($this->getProperties() ?? []) as $field) {
            $field = (string) $field;
            if (str_contains($field, '.')) {
                [$relation, $subField] = explode('.', $field, 2);
                if (!isset($relationAliases[$relation])) {
                    $joinAlias = $queryNameGenerator->generateJoinAlias($relation);
                    $queryBuilder->leftJoin("{$alias}.{$relation}", $joinAlias);
                    $relationAliases[$relation] = $joinAlias;
                }
                $joinAlias = $relationAliases[$relation];
                $column = $this->isNumericRelationField($resourceClass, $relation, $subField)
                    ? "LOWER(CONCAT({$joinAlias}.{$subField}, ''))"
                    : "LOWER({$joinAlias}.{$subField})";
            } else {
                $column = $this->isNumericField($resourceClass, $field)
                    ? "LOWER(CONCAT({$alias}.{$field}, ''))"
                    : "LOWER({$alias}.{$field})";
            }

            $orConditions[] = $queryBuilder->expr()->like(
                $column,
                "LOWER(:{$paramName})",
            );
        }

        if ([] === $orConditions) {
            return;
        }

        $queryBuilder
            ->andWhere($queryBuilder->expr()->orX(...$orConditions))
            ->setParameter($paramName, '%'.$value.'%');
    }

    private function isNumericRelationField(string $resourceClass, string $relation, string $field): bool
    {
        if (!$this->managerRegistry instanceof ManagerRegistry || !class_exists($resourceClass)) {
            return false;
        }

        /** @var class-string $resourceClass */
        $manager = $this->managerRegistry->getManagerForClass($resourceClass);
        if (!$manager instanceof ObjectManager) {
            return false;
        }

        $rootMetadata = $manager->getClassMetadata($resourceClass);
        // `getAssociationMapping` is ORM-specific — narrow from the abstract
        // `Doctrine\Persistence\Mapping\ClassMetadata` to the ORM variant.
        if (!$rootMetadata instanceof ORMClassMetadata) {
            return false;
        }
        if (!$rootMetadata->hasAssociation($relation)) {
            return false;
        }

        $targetClass = $rootMetadata->getAssociationMapping($relation)['targetEntity'] ?? null;
        if (!\is_string($targetClass) || !class_exists($targetClass)) {
            return false;
        }

        /** @var class-string $targetClass */
        $targetMetadata = $manager->getClassMetadata($targetClass);
        if (!$targetMetadata->hasField($field)) {
            return false;
        }

        return \in_array($targetMetadata->getTypeOfField($field), self::NUMERIC_TYPES, true);
    }

    private function isNumericField(string $resourceClass, string $property): bool
    {
        if (!$this->managerRegistry instanceof ManagerRegistry || !class_exists($resourceClass)) {
            return false;
        }

        /** @var class-string $resourceClass */
        $manager = $this->managerRegistry->getManagerForClass($resourceClass);
        if (!$manager instanceof ObjectManager) {
            return false;
        }

        $metadata = $manager->getClassMetadata($resourceClass);
        if (!$metadata->hasField($property)) {
            return false;
        }

        return \in_array($metadata->getTypeOfField($property), self::NUMERIC_TYPES, true);
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
        $fields = array_keys($this->getProperties() ?? []);

        return [
            self::PARAMETER_NAME => [
                'property' => implode(', ', $fields),
                'type' => 'string',
                'required' => false,
                'description' => 'OR search across: '.implode(', ', $fields),
            ],
        ];
    }
}
