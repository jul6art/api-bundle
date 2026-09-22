<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Filter;

use ApiPlatform\Doctrine\Orm\NestedPropertyHelperTrait;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Parameter;
use Doctrine\ORM\QueryBuilder;

/**
 * API Platform's nested-join helper, with its answer checked.
 *
 * `addNestedParameterJoins()` joins the relation segments of a dotted property (`site.customer`)
 * and answers the alias and field to compare — as an untyped array. Nothing guarantees its shape, so
 * it is narrowed here once: a shape that turns out different stops the filter, instead of building a
 * DQL expression out of whatever the array held.
 */
trait NestedJoinTrait
{
    use NestedPropertyHelperTrait;

    /**
     * @return array{string, string}|null the alias and the field to compare, or null when the join
     *                                    could not be resolved
     */
    private function joinedPathOf(
        string $property,
        string $rootAlias,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        Parameter $parameter,
        ?string $joinType = null,
    ): ?array {
        $joined = $this->addNestedParameterJoins($property, $rootAlias, $queryBuilder, $queryNameGenerator, $parameter, $joinType);
        $alias = $joined[0] ?? null;
        $field = $joined[1] ?? null;

        return \is_string($alias) && \is_string($field) ? [$alias, $field] : null;
    }
}
