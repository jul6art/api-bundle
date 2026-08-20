<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Fixtures;

use ApiPlatform\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Jul6Art\ApiBundle\Tests\Fixtures\Entity\Widget;

/**
 * Stands in for API Platform's pagination extension: it answers with whatever the test hands it.
 *
 * A named class rather than an anonymous one because `@implements` cannot be attached to
 * `new class` without a `@var` the analyser then rejects — and a test unable to state the generic
 * parameter would not be exercising what a real application wires.
 *
 * @implements QueryResultCollectionExtensionInterface<Widget>
 */
final readonly class PaginatingExtension implements QueryResultCollectionExtensionInterface
{
    /**
     * @param iterable<mixed> $result
     */
    public function __construct(private iterable $result)
    {
    }

    #[\Override]
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
    }

    #[\Override]
    public function supportsResult(string $resourceClass, ?Operation $operation = null, array $context = []): bool
    {
        return true;
    }

    /**
     * @return iterable<mixed>
     */
    #[\Override]
    public function getResult(QueryBuilder $queryBuilder, ?string $resourceClass = null, ?Operation $operation = null, array $context = []): iterable
    {
        return $this->result;
    }
}
