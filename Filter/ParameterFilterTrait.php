<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Common\Filter\OpenApiFilterTrait;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Parameter;
use ApiPlatform\State\ParameterNotFound;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;

/**
 * What every filter of this bundle needs since API Platform 4.4 moved filters onto `QueryParameter`.
 *
 * A filter no longer reads the request: API Platform hands it the {@see Parameter} it was declared on,
 * with the value already extracted. The filter's configuration — which fields to search, which ranks
 * to order by — lives on that parameter (`properties`) or in the filter's own constructor, never in a
 * `properties` map resolved by `AbstractFilter`, which is deprecated.
 *
 * ⚠️ **The Doctrine metadata is read from the QueryBuilder's own entity manager.** The legacy filters
 * received a `ManagerRegistry` through `AbstractFilter`; a filter instantiated inline in an attribute
 * (`new OrSearchFilter()`) has no container to be wired from, and the QueryBuilder already knows the
 * one manager that will run the query.
 *
 * ⚠️ **Mapping objects are read through their PROPERTIES, never as arrays.** Doctrine 3 deprecates
 * `ArrayAccess` on `FieldMapping` / `AssociationMapping` and removes it in 4.0; the typed getters of
 * `ClassMetadata` answer every question these filters ask.
 */
trait ParameterFilterTrait
{
    use BackwardCompatibleFilterDescriptionTrait;
    use OpenApiFilterTrait;

    /**
     * The parameter this filter runs for, or null when API Platform did not pass one — a filter of
     * this bundle declared through the removed `#[ApiFilter]` path, for instance.
     *
     * @param array<string, mixed> $context
     */
    private function parameterOf(array $context): ?Parameter
    {
        $parameter = $context['parameter'] ?? null;

        return $parameter instanceof Parameter ? $parameter : null;
    }

    /**
     * The value the request carried for this parameter, or null when it carried none.
     */
    private function valueOf(Parameter $parameter): mixed
    {
        $value = $parameter->getValue();

        return $value instanceof ParameterNotFound ? null : $value;
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>|null
     */
    private function metadataOf(QueryBuilder $queryBuilder, string $class): ?ClassMetadata
    {
        if (!class_exists($class)) {
            return null;
        }

        try {
            return $queryBuilder->getEntityManager()->getClassMetadata($class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The Doctrine type of `$field` on `$class` — an embeddable's dotted column (`address.city`)
     * included, since Doctrine registers it among the holder's own fields.
     *
     * @param class-string $class
     */
    private function fieldTypeOf(QueryBuilder $queryBuilder, string $class, string $field): ?string
    {
        $metadata = $this->metadataOf($queryBuilder, $class);

        if (null === $metadata || !$metadata->hasField($field)) {
            return null;
        }

        return $metadata->getTypeOfField($field);
    }

    /**
     * The class an association of `$class` points to, or null when `$relation` is not one.
     *
     * @param class-string $class
     *
     * @return class-string|null
     */
    private function associationTargetOf(QueryBuilder $queryBuilder, string $class, string $relation): ?string
    {
        $metadata = $this->metadataOf($queryBuilder, $class);

        if (null === $metadata || !$metadata->hasAssociation($relation)) {
            return null;
        }

        $target = $metadata->getAssociationTargetClass($relation);

        return '' === $target ? null : $target;
    }

    /**
     * The class that owns the last segment of `$property`, walking its associations
     * (`contact.company.name` → `Company`). An embeddable's column stays on its holder.
     *
     * @param class-string $resourceClass
     *
     * @return class-string|null
     */
    private function leafClassOf(QueryBuilder $queryBuilder, string $resourceClass, string $property): ?string
    {
        $class = $resourceClass;
        $segments = explode('.', $property);
        array_pop($segments);

        foreach ($segments as $index => $segment) {
            $target = $this->associationTargetOf($queryBuilder, $class, $segment);

            if (null === $target) {
                // Not an association: the rest of the path is an embeddable's column on `$class`.
                return null !== $this->fieldTypeOf($queryBuilder, $class, implode('.', \array_slice(explode('.', $property), $index))) ? $class : null;
            }

            $class = $target;
        }

        return $class;
    }

    /**
     * The request's sort direction, or null when it is neither `asc` nor `desc` (any case).
     */
    private function sortDirectionOf(mixed $value): ?\SortDirection
    {
        if (!\is_string($value)) {
            return null;
        }

        return match (strtoupper($value)) {
            'ASC' => \SortDirection::Ascending,
            'DESC' => \SortDirection::Descending,
            default => null,
        };
    }

    /**
     * The first root alias, or null when the QueryBuilder has none to attach a condition to.
     */
    private function rootAliasOf(QueryBuilder $queryBuilder): ?string
    {
        $alias = $queryBuilder->getRootAliases()[0] ?? null;

        return \is_string($alias) ? $alias : null;
    }
}
