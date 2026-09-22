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
 * Filters a JSON array column (roles stored as JSON, for instance) on one of its values.
 *
 * The column is read through core-bundle's `JSON_TEXT()` DQL function — `field::text` on
 * PostgreSQL, `CAST(field AS CHAR)` elsewhere — because PostgreSQL has no `LIKE` operator for the
 * `json` type: a bare `LIKE` answers « operator does not exist: json ~~ unknown ».
 *
 * Declared on the resource (API Platform ≥ 4.4):
 *
 *   #[QueryParameter(key: 'roles', filter: new JsonContainsFilter())]
 *
 *   GET /api/users?roles=ROLE_SUPER_ADMIN
 */
final class JsonContainsFilter implements FilterInterface, OpenApiParameterFilterInterface, JsonSchemaFilterInterface
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

        if (null === $property || !\is_string($value) || '' === $value || null === $alias) {
            return;
        }

        $paramName = $queryNameGenerator->generateParameterName($property);
        $queryBuilder
            ->andWhere(\sprintf('JSON_TEXT(%s.%s) LIKE :%s', $alias, $property, $paramName))
            ->setParameter($paramName, '%'.$value.'%');
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(Parameter $parameter): array
    {
        return ['type' => 'string'];
    }
}
