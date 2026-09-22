<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\JsonSchemaFilterInterface;
use ApiPlatform\Metadata\OpenApiParameterFilterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use Doctrine\ORM\QueryBuilder;

/**
 * `?<property>=YYYY` → the rows whose date falls in that year.
 *
 * Used by the datatables that offer a year select (journal, invoices…) rather than an explicit range.
 *
 * Declared on the resource (API Platform ≥ 4.4):
 *
 *   #[QueryParameter(key: 'issuedAt', filter: new YearFilter())]
 *
 * ⚠️ A key already used by a `DateFilter` (`issuedAt[after]`) cannot be declared twice: compose the
 * two with API Platform's `ChainFilter` on one parameter. This filter ignores the `[after]` / `[before]`
 * operator map, and `DateFilter` ignores a bare year.
 */
final class YearFilter implements FilterInterface, OpenApiParameterFilterInterface, JsonSchemaFilterInterface
{
    use ParameterFilterTrait;

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
        $value = $parameter instanceof Parameter ? $this->valueOf($parameter) : null;
        $alias = $this->rootAliasOf($queryBuilder);

        if (null === $property || null === $alias || (!\is_string($value) && !\is_int($value))) {
            return;
        }

        $year = (int) $value;
        if ($year < 1900 || $year > 9999) {
            return;
        }

        $fromParam = $queryNameGenerator->generateParameterName($property.'_year_from');
        $toParam = $queryNameGenerator->generateParameterName($property.'_year_to');

        // A half-open range `[YYYY-01-01, YYYY+1-01-01)` rather than `YEAR()`, which is not portable
        // (MySQL only — PostgreSQL raises SQLSTATE[42883]). The range stays indexable and works for
        // `date` as for `datetime_immutable`.
        $queryBuilder
            ->andWhere(\sprintf('%1$s.%2$s >= :%3$s AND %1$s.%2$s < :%4$s', $alias, $property, $fromParam, $toParam))
            ->setParameter($fromParam, new \DateTimeImmutable($year.'-01-01 00:00:00'))
            ->setParameter($toParam, new \DateTimeImmutable(($year + 1).'-01-01 00:00:00'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(Parameter $parameter): array
    {
        return ['type' => 'integer'];
    }
}
