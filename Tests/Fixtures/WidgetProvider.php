<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Fixtures;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Jul6Art\ApiBundle\State\AbstractCollectionProvider;
use Jul6Art\ApiBundle\Tests\Fixtures\Entity\Widget;

/**
 * Exactly what an application writes: the entity is declared once, on the class, and the item
 * operation is then checked against it.
 *
 * This is a named class rather than an anonymous one on purpose — `@extends` cannot be attached to
 * `new class` without fighting the formatter, and a test that cannot state the generic parameter
 * would not be exercising the thing consumers rely on.
 *
 * @extends AbstractCollectionProvider<Widget>
 */
final class WidgetProvider extends AbstractCollectionProvider
{
    /**
     * @param list<QueryCollectionExtensionInterface> $extensions
     */
    public function __construct(
        array $extensions,
        private readonly ?Widget $item,
        private readonly ?QueryBuilder $collection,
    ) {
        parent::__construct($extensions);
    }

    #[\Override]
    protected function provideCollection(Operation $operation, array $uriVariables, array $context): ?QueryBuilder
    {
        return $this->collection;
    }

    #[\Override]
    protected function provideItem(Operation $operation, array $uriVariables, array $context): ?Widget
    {
        return $this->item;
    }
}
