<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Common\Filter\OrderFilterInterface;
use ApiPlatform\Doctrine\Common\Filter\OrderFilterTrait;
use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

/**
 * Order filter that sorts a column by a **business rank** instead of its
 * raw stored value. Built for stringy enum columns (`status`, `priority`,
 * …) where alphabetical order is meaningless: `ORDER BY status` yields
 * `done < in_progress < review < todo`, which puts finished tasks on top.
 *
 * Each configured property declares its **ordered list of values**; the
 * filter emits `ORDER BY CASE WHEN col = :v0 THEN 0 … ELSE <n> END`, so the
 * ascending order follows the declared list (rank = index) and descending
 * reverses it. Unknown values fall to the end (`ELSE <count>`).
 *
 * Usage on entity (associative `properties`, value = ordered list):
 *   #[ApiFilter(RankedOrderFilter::class, properties: [
 *       'status'   => [self::STATUS_TODO, self::STATUS_IN_PROGRESS, self::STATUS_REVIEW, self::STATUS_DONE],
 *       'priority' => [self::PRIORITY_URGENT, self::PRIORITY_HIGH, self::PRIORITY_MEDIUM, self::PRIORITY_LOW],
 *   ])]
 *
 * API call:
 *   GET /api/erp_project_tasks?order[status]=asc  → todo, in_progress, review, done
 *   GET /api/erp_project_tasks?order[priority]=asc → urgent, high, medium, low
 *
 * Coexists with {@see CaseInsensitiveOrderFilter} on the same `order`
 * parameter: each filter only acts on its own enabled properties
 * (`isPropertyEnabled`), so a property must live in exactly one of the two.
 *
 * Why a copy of `apply()` instead of a subclass: API Platform's
 * `OrderFilter` is `final`. The trait + abstract base do the heavy lifting,
 * so the duplication is cheap — same rationale as `CaseInsensitiveOrderFilter`.
 *
 * Note on the HIDDEN select: API Platform's `PaginationExtension` parses
 * ORDER BY clauses naively (split on `.`, take the first token as the root
 * alias). A bare `CASE …` expression in ORDER BY breaks that parser, so the
 * expression is added as a HIDDEN select with a simple alias and we order by
 * that alias instead — same workaround as `CaseInsensitiveOrderFilter:153-159`.
 */
final class RankedOrderFilter extends AbstractFilter implements OrderFilterInterface
{
    use OrderFilterTrait;

    /**
     * @param array<string, mixed>|null $properties
     */
    public function __construct(
        ?ManagerRegistry $managerRegistry = null,
        string $orderParameterName = 'order',
        ?LoggerInterface $logger = null,
        ?array $properties = null,
        ?NameConverterInterface $nameConverter = null,
    ) {
        parent::__construct($managerRegistry, $logger, $properties, $nameConverter);

        $this->orderParameterName = $orderParameterName;
    }

    /**
     * @param class-string $resourceClass
     */
    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        // `$context` arrive non typé d'API Platform : on le réduit une fois à ce qu'on sait en
        // lire, plutôt que d'affirmer sa forme à chaque accès.
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : null;
        $parameter = ($context['parameter'] ?? null) instanceof Parameter ? $context['parameter'] : null;
        $ordering = \is_array($filters[$this->orderParameterName] ?? null) ? $filters[$this->orderParameterName] : null;

        if (null !== $filters && null === $ordering && !$parameter instanceof Parameter) {
            return;
        }

        if ($parameter instanceof Parameter) {
            $property = $parameter->getProperty();

            if (\is_string($property) && null !== ($value = $filters[$property] ?? null)) {
                $this->filterProperty($this->denormalizePropertyName($property), $value, $queryBuilder, $queryNameGenerator, $resourceClass, $operation, $context);

                return;
            }
        }

        foreach ($ordering ?? [] as $property => $value) {
            $this->filterProperty($this->denormalizePropertyName($property), $value, $queryBuilder, $queryNameGenerator, $resourceClass, $operation, $context);
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
        if (!$this->isPropertyEnabled($property, $resourceClass) || !$this->isPropertyMapped($property, $resourceClass)) {
            return;
        }

        $orderedValues = $this->properties[$property] ?? null;
        if (!\is_array($orderedValues) || [] === $orderedValues) {
            return;
        }

        $direction = $this->normalizeValue($value, $property);
        if (null === $direction) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $hiddenAlias = \sprintf('_rank_order_%s_%s', $alias, $property);

        $cases = '';
        foreach (array_values($orderedValues) as $rank => $enumValue) {
            $parameterName = $queryNameGenerator->generateParameterName($property.'_rank_'.$rank);
            $cases .= \sprintf(' WHEN %s.%s = :%s THEN %d', $alias, $property, $parameterName, $rank);
            $queryBuilder->setParameter($parameterName, $enumValue);
        }

        $queryBuilder->addSelect(\sprintf('CASE%s ELSE %d END AS HIDDEN %s', $cases, \count($orderedValues), $hiddenAlias));
        $queryBuilder->addOrderBy($hiddenAlias, $direction);
    }
}
