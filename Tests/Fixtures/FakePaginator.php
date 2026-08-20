<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Fixtures;

use ApiPlatform\State\Pagination\PaginatorInterface;
use Jul6Art\ApiBundle\Tests\Fixtures\Entity\Widget;

/**
 * A paginator, for the one property that matters here: it must reach the serializer **as itself**.
 *
 * `totalItems` and `hydra:view` in a Hydra response come from the serializer recognising a
 * `PaginatorInterface`. Anything that re-wraps the object — even lazily, even preserving every row
 * — produces a collection with no total and no page links, and a test asserting on the rows still
 * passes. Hence a real implementation rather than a mock: the test has to be able to assert
 * identity, not behaviour.
 *
 * `IteratorAggregate` is declared explicitly: `PartialPaginatorInterface` extends `\Traversable`,
 * which PHP refuses to let a class implement on its own.
 *
 * @implements PaginatorInterface<Widget>
 * @implements \IteratorAggregate<mixed, Widget>
 */
final readonly class FakePaginator implements PaginatorInterface, \IteratorAggregate
{
    /**
     * @param list<Widget> $items
     */
    public function __construct(
        private array $items,
        private float $totalItems = 42.0,
    ) {
    }

    /**
     * @return \Traversable<mixed, Widget>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->items);
    }

    #[\Override]
    public function getCurrentPage(): float
    {
        return 1.0;
    }

    #[\Override]
    public function getItemsPerPage(): float
    {
        return 30.0;
    }

    #[\Override]
    public function getLastPage(): float
    {
        return 2.0;
    }

    #[\Override]
    public function getTotalItems(): float
    {
        return $this->totalItems;
    }
}
