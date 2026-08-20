<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Common\Filter\OrderFilterInterface;
use ApiPlatform\Doctrine\Common\Filter\OrderFilterTrait;
use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

/**
 * Drop-in replacement for `ApiPlatform\Doctrine\Orm\Filter\OrderFilter` that
 * sorts text columns case-insensitively (e.g. `Apple, banana, cherry`
 * instead of `Apple, Banana, apple, banana`).
 *
 * Behavior:
 *   - Text-typed columns (`string`, `text`, `ascii_string`, `citext`,
 *     `guid`) are wrapped in `LOWER(...)` in the ORDER BY clause.
 *   - Non-text columns (int, datetime, enum, boolean, …) sort as before.
 *   - Nested properties (e.g. `?order[contact.firstName]=asc`) are
 *     handled the same way the upstream filter handles them, with
 *     `LOWER()` applied to the joined column when it's text-typed.
 *
 * Why a copy instead of a subclass: `OrderFilter` is `final`. The trait +
 * abstract base do the heavy lifting, so the duplication is cheap.
 *
 * Tradeoffs:
 *   - `LOWER()` defeats functional indexes that aren't `LOWER()`-aware.
 *     For tables where sort speed matters (e.g. millions of rows), add a
 *     `CREATE INDEX … (LOWER(name))` migration alongside the existing
 *     index.
 *   - Postgres `LOWER()` is locale-sensitive only when the database
 *     collation is. We rely on the project default `en_US.UTF-8` which
 *     handles Latin diacritics correctly.
 */
final class CaseInsensitiveOrderFilter extends AbstractFilter implements OrderFilterInterface
{
    use OrderFilterTrait;

    /** @var array<string, true> Doctrine field types we wrap in LOWER(). */
    private const array TEXT_TYPES = [
        Types::STRING => true,
        Types::TEXT => true,
        Types::ASCII_STRING => true,
        Types::GUID => true,
    ];

    /**
     * @param array<string, mixed>|null $properties
     */
    public function __construct(
        ?ManagerRegistry $managerRegistry = null,
        string $orderParameterName = 'order',
        ?LoggerInterface $logger = null,
        ?array $properties = null,
        ?NameConverterInterface $nameConverter = null,
        private readonly ?string $orderNullsComparison = null,
    ) {
        if (null !== $properties) {
            $properties = array_map(static function ($propertyOptions) {
                if (\is_string($propertyOptions)) {
                    return ['default_direction' => $propertyOptions];
                }

                return $propertyOptions;
            }, $properties);
        }

        parent::__construct($managerRegistry, $logger, $properties, $nameConverter);

        $this->orderParameterName = $orderParameterName;
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
        // `$context` arrive non typé d'API Platform : on le réduit à ce qu'on sait en lire, une
        // fois, plutôt que d'affirmer sa forme à chaque accès.
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : null;
        $parameter = ($context['parameter'] ?? null) instanceof Parameter ? $context['parameter'] : null;

        $ordering = \is_array($filters[$this->orderParameterName] ?? null) ? $filters[$this->orderParameterName] : null;

        if (null !== $filters && null === $ordering && !$parameter instanceof Parameter) {
            return;
        }

        // Un paramètre nommé (API Platform 4) désigne une propriété unique : il gagne sur la
        // lecture du paramètre `order`.
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

        $direction = $this->normalizeValue($value, $property);
        if (null === $direction) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0] ?? null;

        if (!\is_string($rootAlias)) {
            return;
        }

        $alias = $rootAlias;
        $field = $property;
        $targetClass = $resourceClass;

        if ($this->isPropertyNested($property, $resourceClass)) {
            // The third element of `addJoinsForNestedProperty()` is the *associations chain* (an
            // array of association names), not a class-string. Walk the metadata to resolve the
            // leaf entity class so `isTextField()` can read the field type.
            //
            // Nothing about that return is typed, so it is narrowed here rather than asserted at
            // each use: a shape that turns out different should stop the filter, not order by a
            // field name built out of an array.
            [$joinAlias, $joinField, $associations] = $this->addJoinsForNestedProperty($property, $alias, $queryBuilder, $queryNameGenerator, $resourceClass, Join::LEFT_JOIN);

            if (!\is_string($joinAlias) || !\is_string($joinField) || !\is_array($associations)) {
                return;
            }

            $alias = $joinAlias;
            $field = $joinField;
            $targetClass = $this->resolveAssociationsTarget(
                $resourceClass,
                array_values(array_filter($associations, \is_string(...))),
            );
        }

        // `$this->properties` vient d'`AbstractFilter` et n'est pas typé : on le lit en une fois.
        $propertyConfig = \is_array($this->properties[$property] ?? null) ? $this->properties[$property] : [];
        $nullsComparison = $propertyConfig['nulls_comparison'] ?? $this->orderNullsComparison;

        if (\is_string($nullsComparison) && isset(self::NULLS_DIRECTION_MAP[$nullsComparison][$direction])) {
            $nullsDirection = self::NULLS_DIRECTION_MAP[$nullsComparison][$direction];

            $nullRankHiddenField = \sprintf('_%s_%s_null_rank', $alias, str_replace('.', '_', $field));

            $queryBuilder->addSelect(\sprintf('CASE WHEN %s.%s IS NULL THEN 0 ELSE 1 END AS HIDDEN %s', $alias, $field, $nullRankHiddenField));
            $queryBuilder->addOrderBy($nullRankHiddenField, $nullsDirection);
        }

        if ($this->isTextField($targetClass, $field)) {
            // API Platform's `PaginationExtension` parses ORDER BY clauses
            // naively (split on `.`, take the first token as the root
            // alias). A function call like `LOWER(c.lastName)` breaks that
            // parser with `The alias "LOWER(c" does not exist`. Workaround
            // — add the lowered expression as a HIDDEN select with a
            // simple alias and order by that alias instead.
            $hiddenAlias = \sprintf('_ci_order_%s_%s', $alias, str_replace('.', '_', $field));
            $queryBuilder->addSelect(\sprintf('LOWER(%s.%s) AS HIDDEN %s', $alias, $field, $hiddenAlias));
            $queryBuilder->addOrderBy($hiddenAlias, $direction);
        } else {
            $queryBuilder->addOrderBy(\sprintf('%s.%s', $alias, $field), $direction);
        }
    }

    private function isTextField(string $resourceClass, string $field): bool
    {
        try {
            $metadata = $this->getClassMetadata($resourceClass);
        } catch (\Throwable) {
            return false;
        }

        if (!$metadata->hasField($field)) {
            return false;
        }

        $type = $metadata->getTypeOfField($field);

        return null !== $type && isset(self::TEXT_TYPES[$type]);
    }

    /**
     * Walks the associations chain returned by `addJoinsForNestedProperty()`
     * to resolve the class-string of the leaf entity (e.g. for the path
     * `Page::translations.title` with associations `['translations']`,
     * returns `PageTranslation::class`). Falls back to the root resource
     * class if the chain can't be resolved (defensive — caller will treat
     * the field as non-text and skip the LOWER wrap).
     *
     * @param class-string       $resourceClass
     * @param array<int, string> $associations
     *
     * @return class-string
     */
    private function resolveAssociationsTarget(string $resourceClass, array $associations): string
    {
        $current = $resourceClass;
        foreach ($associations as $assoc) {
            try {
                $metadata = $this->getClassMetadata($current);
            } catch (\Throwable) {
                return $resourceClass;
            }
            if (!$metadata->hasAssociation($assoc)) {
                return $resourceClass;
            }
            $target = $metadata->getAssociationTargetClass($assoc);
            if (null === $target || '' === $target) {
                return $resourceClass;
            }
            $current = $target;
        }

        /* @var class-string $current */
        return $current;
    }
}
