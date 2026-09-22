<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryBuilderHelper;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\JsonSchemaFilterInterface;
use ApiPlatform\Metadata\OpenApiParameterFilterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use ApiPlatform\Metadata\ParameterProviderFilterInterface;
use Doctrine\ORM\QueryBuilder;
use Jul6Art\ApiBundle\State\RelationParameterProvider;

/**
 * Filters a collection on a relation, by **identifier or IRI**, one value or several.
 *
 *   GET /api/sites?customer=12
 *   GET /api/sites?customer=/api/customers/12
 *   GET /api/sites?customer[]=12&customer[]=14
 *
 * It replaces the legacy `SearchFilter` declared on a relation (`'customer' => 'exact'`), which
 * accepted both shapes. API Platform 4.4 maps that declaration to `IriFilter`, which accepts IRIs
 * only: a plain identifier — what the datatables of this ecosystem send — would be logged as an
 * error and the filter IGNORED, so the collection would answer with every row. See
 * {@see RelationParameterProvider}.
 *
 * Declared on the resource (API Platform ≥ 4.4):
 *
 *   #[QueryParameter(key: 'customer', filter: new RelationFilter())]
 *   #[QueryParameter(key: 'site.customer', filter: new RelationFilter(), property: 'site.customer')]
 */
final class RelationFilter implements FilterInterface, OpenApiParameterFilterInterface, JsonSchemaFilterInterface, ParameterProviderFilterInterface
{
    use NestedJoinTrait;
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
        $rootAlias = $this->rootAliasOf($queryBuilder);

        if (!$parameter instanceof Parameter || !\is_string($property) || !\is_string($rootAlias)) {
            return;
        }

        // Nothing to look up, or an operator map (`customer[gt]=…`), which is not a relation lookup.
        if (null === $value || '' === $value || [] === $value || (\is_array($value) && !array_is_list($value))) {
            return;
        }

        $joined = $this->joinedPathOf($property, $rootAlias, $queryBuilder, $queryNameGenerator, $parameter);

        if (null === $joined) {
            return;
        }

        [$alias, $field] = $joined;
        $parameterName = $queryNameGenerator->generateParameterName($field);
        $compared = $alias.'.'.$field;

        // ⚠️ A collection (`quote.defects`) cannot be compared to a value — `q.defects = :id` is not
        // DQL. It is joined, and the joined entity is what gets compared, as the legacy SearchFilter
        // did.
        $owner = $this->leafClassOf($queryBuilder, $resourceClass, $property);

        if (null !== $owner && ($this->metadataOf($queryBuilder, $owner)?->isCollectionValuedAssociation($field) ?? false)) {
            $compared = QueryBuilderHelper::addJoinOnce($queryBuilder, $queryNameGenerator, $alias, $field);
        }

        $condition = \is_array($value)
            ? \sprintf('%s IN (:%s)', $compared, $parameterName)
            : \sprintf('%s = :%s', $compared, $parameterName);

        // `orWhere` when API Platform's `OrFilter` composes this filter with others, `andWhere`
        // otherwise — the only two clauses a filter is asked to use.
        if ('orWhere' === ($context['whereClause'] ?? null)) {
            $queryBuilder->orWhere($condition);
        } else {
            $queryBuilder->andWhere($condition);
        }

        $queryBuilder->setParameter($parameterName, $value);
    }

    public static function getParameterProvider(): string
    {
        return RelationParameterProvider::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(Parameter $parameter): array
    {
        return ['type' => 'string'];
    }
}
