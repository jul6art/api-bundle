<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\JsonSchemaFilterInterface;
use ApiPlatform\Metadata\OpenApiParameterFilterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/**
 * OR search filter — one search term applied to several fields, joined by OR.
 *
 * API Platform's own search filters combine parameters with AND; a global search box needs the
 * opposite: « does ANY of these columns contain what was typed? ».
 *
 * Supports **direct fields** (`title`, `email`), **embeddable columns** (`address.city`) and
 * **single-hop relation paths** (`contact.firstName`, `deal.title`). A relation path triggers a
 * `LEFT JOIN`, so a missing relation means « no match on that branch of the OR » instead of dropping
 * the row entirely.
 *
 * Numeric fields (`decimal`, `integer`, `float`) are coerced to text through `CONCAT(field, '')`:
 * DQL has no portable `CAST`, and `LOWER(numeric)` fails on PostgreSQL with a type mismatch.
 *
 * Declared on the resource (API Platform ≥ 4.4):
 *
 *   #[QueryParameter(key: 'search', filter: new OrSearchFilter(), properties: ['email', 'firstName', 'lastName'])]
 *
 *   GET /api/users?search=admin
 *   → WHERE LOWER(email) LIKE '%admin%' OR LOWER(firstName) LIKE '%admin%' OR …
 */
final class OrSearchFilter implements FilterInterface, OpenApiParameterFilterInterface, JsonSchemaFilterInterface
{
    use ParameterFilterTrait;

    /**
     * The parameter key these filters are conventionally declared under — the one the datatable's
     * global search box sends.
     */
    public const string PARAMETER_NAME = 'search';

    /**
     * Doctrine field types cast to text before the LIKE compare.
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

    /**
     * @param class-string         $resourceClass
     * @param array<string, mixed> $context
     */
    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $parameter = $this->parameterOf($context);
        $value = $parameter instanceof Parameter ? $this->valueOf($parameter) : null;
        $alias = $this->rootAliasOf($queryBuilder);

        if (!$parameter instanceof Parameter || !\is_string($value) || '' === $value || null === $alias) {
            return;
        }

        $orConditions = [];
        $paramName = $queryNameGenerator->generateParameterName('or_search');
        // One LEFT JOIN per relation, reused by every path that starts with it
        // (`contact.firstName` + `contact.lastName`).
        $relationAliases = [];

        foreach ($this->fieldsOf($parameter) as $field) {
            $column = $this->columnFor($queryBuilder, $queryNameGenerator, $resourceClass, $alias, $field, $relationAliases);

            if (null !== $column) {
                $orConditions[] = $queryBuilder->expr()->like($column, "LOWER(:{$paramName})");
            }
        }

        if ([] === $orConditions) {
            return;
        }

        $queryBuilder
            ->andWhere($queryBuilder->expr()->orX(...$orConditions))
            ->setParameter($paramName, '%'.$value.'%');
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(Parameter $parameter): array
    {
        return ['type' => 'string'];
    }

    /**
     * The fields the parameter declared, as strings — an empty list makes the filter a no-op rather
     * than a query on a field named after an array index.
     *
     * @return list<string>
     */
    private function fieldsOf(Parameter $parameter): array
    {
        return array_values(array_filter($parameter->getProperties() ?? [], \is_string(...)));
    }

    /**
     * The lowered DQL expression one field contributes to the OR.
     *
     * @param class-string          $resourceClass
     * @param array<string, string> $relationAliases join aliases already added, keyed by relation
     */
    private function columnFor(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        string $alias,
        string $field,
        array &$relationAliases,
    ): ?string {
        // ⚠️ A dot does NOT prove a relation. Doctrine maps an EMBEDDABLE's fields into the holder's
        // own table and addresses them as `w.address.city` — joining `w.address` raises
        // « Association name expected, 'address' is not an association », an uncaught 500 on a
        // collection that answered perfectly until someone typed in the search box. The embedded
        // case is therefore settled FIRST, on the metadata rather than on the shape of the string.
        if (!str_contains($field, '.') || null !== $this->fieldTypeOf($queryBuilder, $resourceClass, $field)) {
            return $this->lowered("{$alias}.{$field}", $this->fieldTypeOf($queryBuilder, $resourceClass, $field));
        }

        [$relation, $subField] = explode('.', $field, 2);
        $target = $this->associationTargetOf($queryBuilder, $resourceClass, $relation);

        if (null === $target) {
            return null;
        }

        if (!isset($relationAliases[$relation])) {
            $relationAliases[$relation] = $queryNameGenerator->generateJoinAlias($relation);
            $queryBuilder->leftJoin("{$alias}.{$relation}", $relationAliases[$relation]);
        }

        return $this->lowered("{$relationAliases[$relation]}.{$subField}", $this->fieldTypeOf($queryBuilder, $target, $subField));
    }

    private function lowered(string $path, ?string $type): string
    {
        return \in_array($type, self::NUMERIC_TYPES, true)
            ? "LOWER(CONCAT({$path}, ''))"
            : "LOWER({$path})";
    }
}
