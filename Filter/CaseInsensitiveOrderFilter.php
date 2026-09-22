<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Common\Filter\OrderFilterInterface;
use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\JsonSchemaFilterInterface;
use ApiPlatform\Metadata\OpenApiParameterFilterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use ApiPlatform\Metadata\SortFilterInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

/**
 * API Platform's {@see \ApiPlatform\Doctrine\Orm\Filter\SortFilter}, but case-insensitive on text
 * columns (`Apple, banana, cherry` instead of `Apple, Banana, apple, banana`).
 *
 *   - Text columns (`string`, `text`, `ascii_string`, `guid`) are ordered by `LOWER(...)`.
 *   - Other columns (int, datetime, enum, boolean…) sort exactly as `SortFilter` would.
 *   - Nested properties (`order[contact.firstName]`) are LEFT-joined, and `LOWER()` applies to the
 *     joined column when it is text.
 *
 * Declared on the resource (API Platform ≥ 4.4), with the sortable properties listed — a
 * `:property` template WITHOUT `properties` expands to every property of the resource, which would
 * make columns sortable that nobody meant to expose:
 *
 *   #[QueryParameter(key: 'order[:property]', filter: new CaseInsensitiveOrderFilter(), properties: ['name', 'email', 'createdAt'])]
 *
 * ⚠️ **One `order[:property]` template per resource.** Parameters are keyed by their key, so a second
 * template on the same class replaces the first. A property ordered by another filter
 * ({@see RankedOrderFilter}, {@see ConcatOrderFilter}) is declared under its own explicit key.
 *
 * Tradeoff: `LOWER()` defeats indexes that are not `LOWER()`-aware. Where sort speed matters on a
 * large table, add a `CREATE INDEX … (LOWER(name))` next to the plain one.
 */
final readonly class CaseInsensitiveOrderFilter implements FilterInterface, OpenApiParameterFilterInterface, JsonSchemaFilterInterface, SortFilterInterface
{
    use NestedJoinTrait;
    use ParameterFilterTrait;

    /** @var array<string, true> Doctrine field types ordered through LOWER(). */
    private const array TEXT_TYPES = [
        Types::STRING => true,
        Types::TEXT => true,
        Types::ASCII_STRING => true,
        Types::GUID => true,
    ];

    /**
     * @param string|null $nullsComparison one of the `OrderFilterInterface::NULLS_*` modes, or null to
     *                                     leave NULL placement to the database
     */
    public function __construct(
        private ?string $nullsComparison = null,
    ) {
    }

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
        $property = $parameter?->getProperty();
        $direction = $parameter instanceof Parameter ? $this->sortDirectionOf($this->valueOf($parameter)) : null;
        $rootAlias = $this->rootAliasOf($queryBuilder);

        if (!$parameter instanceof Parameter || !\is_string($property)) {
            return;
        }

        if (!$direction instanceof \SortDirection || !\is_string($rootAlias)) {
            return;
        }

        $joined = $this->joinedPathOf($property, $rootAlias, $queryBuilder, $queryNameGenerator, $parameter, Join::LEFT_JOIN);

        if (null === $joined) {
            return;
        }

        [$alias, $field] = $joined;
        $leafClass = $this->leafClassOf($queryBuilder, $resourceClass, $property);

        $this->orderNulls($queryBuilder, $alias, $field, $direction);

        $type = null === $leafClass ? null : $this->fieldTypeOf($queryBuilder, $leafClass, $field);

        if (null !== $type && isset(self::TEXT_TYPES[$type])) {
            // API Platform's `PaginationExtension` parses ORDER BY clauses naively (split on `.`,
            // first token = root alias): `LOWER(c.lastName)` breaks it with « The alias "LOWER(c"
            // does not exist ». The lowered expression goes into a HIDDEN select with a plain alias,
            // and the query orders by that alias.
            $hiddenAlias = \sprintf('_ci_order_%s_%s', $alias, str_replace('.', '_', $field));
            $queryBuilder->addSelect(\sprintf('LOWER(%s.%s) AS HIDDEN %s', $alias, $field, $hiddenAlias));
            $queryBuilder->addOrderBy($hiddenAlias, $direction);

            return;
        }

        $queryBuilder->addOrderBy(\sprintf('%s.%s', $alias, $field), $direction);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(Parameter $parameter): array
    {
        return ['type' => 'string', 'enum' => ['asc', 'desc', 'ASC', 'DESC']];
    }

    private function orderNulls(QueryBuilder $queryBuilder, string $alias, string $field, \SortDirection $direction): void
    {
        $key = \SortDirection::Descending === $direction ? OrderFilterInterface::DIRECTION_DESC : OrderFilterInterface::DIRECTION_ASC;
        $nullsDirection = null === $this->nullsComparison ? null : (OrderFilterInterface::NULLS_DIRECTION_MAP[$this->nullsComparison][$key] ?? null);

        if (null === $nullsDirection) {
            return;
        }

        $nullRankHiddenField = \sprintf('_%s_%s_null_rank', $alias, str_replace('.', '_', $field));
        $queryBuilder->addSelect(\sprintf('CASE WHEN %s.%s IS NULL THEN 0 ELSE 1 END AS HIDDEN %s', $alias, $field, $nullRankHiddenField));
        $queryBuilder->addOrderBy($nullRankHiddenField, 'DESC' === $nullsDirection ? \SortDirection::Descending : \SortDirection::Ascending);
    }
}
