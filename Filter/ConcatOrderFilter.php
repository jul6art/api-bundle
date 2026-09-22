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
 * Orders by a **virtual** field made of several real ones — typically a `fullName` computed in PHP,
 * which no `ORDER BY` can reach as such.
 *
 * Declared on the resource (API Platform ≥ 4.4), one explicit key per virtual field, the real
 * columns given in the order they sort by:
 *
 *   #[QueryParameter(key: 'order[fullName]', filter: new ConcatOrderFilter(['lastName', 'firstName']))]
 *
 *   GET /api/users?order[fullName]=asc
 *   → ORDER BY lastName ASC, firstName ASC
 */
final readonly class ConcatOrderFilter implements FilterInterface, OpenApiParameterFilterInterface, JsonSchemaFilterInterface, SortFilterInterface
{
    use ParameterFilterTrait;

    /**
     * @param list<string> $fields the real fields, in the order they sort by
     */
    public function __construct(
        private array $fields = [],
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
        $value = $parameter instanceof Parameter ? $this->valueOf($parameter) : null;
        // Any direction that is not `desc` sorts ascending, as it always has: a mistyped direction
        // still orders the list rather than leaving it in insertion order.
        $direction = \is_string($value) ? ($this->sortDirectionOf($value) ?? \SortDirection::Ascending) : null;
        $alias = $this->rootAliasOf($queryBuilder);

        if (!$direction instanceof \SortDirection || null === $alias) {
            return;
        }

        foreach ($this->fields as $field) {
            // A field that is not a string would build invalid DQL: skip it rather than let Doctrine
            // fail on a half-built expression.
            if (\is_string($field) && '' !== $field) {
                $queryBuilder->addOrderBy(\sprintf('%s.%s', $alias, $field), $direction);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(Parameter $parameter): array
    {
        return ['type' => 'string', 'enum' => ['asc', 'desc', 'ASC', 'DESC']];
    }
}
