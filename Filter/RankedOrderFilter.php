<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\JsonSchemaFilterInterface;
use ApiPlatform\Metadata\OpenApiParameterFilterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use ApiPlatform\Metadata\SortFilterInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Sorts a column by a **business rank** instead of its raw stored value. Built for stringy enum
 * columns (`status`, `priority`…) where alphabetical order means nothing: `ORDER BY status` yields
 * `done < in_progress < review < todo`, which puts finished tasks on top.
 *
 * The filter receives the **ordered list of values**; it emits
 * `ORDER BY CASE WHEN col = :v0 THEN 0 … ELSE <n> END`, so ascending follows the list (rank = index)
 * and descending reverses it. Unknown values fall to the end (`ELSE <count>`).
 *
 * Declared on the resource (API Platform ≥ 4.4), one explicit key per ranked property:
 *
 *   #[QueryParameter(key: 'order[status]', property: 'status', filter: new RankedOrderFilter([self::STATUS_TODO, self::STATUS_DOING, self::STATUS_DONE]))]
 *
 *   GET /api/tasks?order[status]=asc  → todo, doing, done
 *
 * ⚠️ The key is explicit and not the `order[:property]` template: a resource holds ONE such template
 * (usually the case-insensitive sort), and a second one would replace it.
 *
 * Note on the HIDDEN select: API Platform's `PaginationExtension` parses ORDER BY clauses naively
 * (split on `.`, first token = root alias). A bare `CASE …` expression breaks that parser, so it goes
 * into a HIDDEN select with a plain alias and the query orders by that alias.
 */
final readonly class RankedOrderFilter implements FilterInterface, OpenApiParameterFilterInterface, JsonSchemaFilterInterface, SortFilterInterface
{
    use ParameterFilterTrait;

    /**
     * @param list<mixed> $ranks the values of the column, in ascending business order
     */
    public function __construct(
        private array $ranks = [],
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
        $alias = $this->rootAliasOf($queryBuilder);

        if (!\is_string($property) || !$direction instanceof \SortDirection) {
            return;
        }

        if (!\is_string($alias) || [] === $this->ranks) {
            return;
        }

        $hiddenAlias = \sprintf('_rank_order_%s_%s', $alias, str_replace('.', '_', $property));
        $cases = '';

        foreach (array_values($this->ranks) as $rank => $value) {
            $parameterName = $queryNameGenerator->generateParameterName($property.'_rank_'.$rank);
            $cases .= \sprintf(' WHEN %s.%s = :%s THEN %d', $alias, $property, $parameterName, $rank);
            $queryBuilder->setParameter($parameterName, $value);
        }

        $queryBuilder->addSelect(\sprintf('CASE%s ELSE %d END AS HIDDEN %s', $cases, \count($this->ranks), $hiddenAlias));
        $queryBuilder->addOrderBy($hiddenAlias, $direction);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(Parameter $parameter): array
    {
        return ['type' => 'string', 'enum' => ['asc', 'desc', 'ASC', 'DESC']];
    }
}
