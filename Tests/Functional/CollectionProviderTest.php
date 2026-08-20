<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Functional;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Jul6Art\ApiBundle\State\AbstractCollectionProvider;
use Jul6Art\ApiBundle\Tests\Fixtures\Entity\Widget;
use Jul6Art\ApiBundle\Tests\Fixtures\FakePaginator;
use Jul6Art\ApiBundle\Tests\Fixtures\PaginatingExtension;
use Jul6Art\ApiBundle\Tests\Fixtures\WidgetProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * `AbstractCollectionProvider` exists so a custom provider keeps pagination, filters and ordering.
 *
 * A provider that returns an array gets none of them: API Platform's extensions act on a
 * QueryBuilder, so returning rows means the collection silently ignores every `?page=`, `?order=`
 * and `?search=` a client sends. Nothing errors — the list is simply always the first, unsorted,
 * unfiltered page.
 */
#[CoversNothing]
final class CollectionProviderTest extends FilterTestCase
{
    public function testAnItemOperationDelegatesToTheConcreteProvider(): void
    {
        $widget = new Widget('un widget');
        $provider = $this->provider(item: $widget);

        self::assertSame($widget, $provider->provide(new GetCollection(class: Widget::class), ['id' => 7]));
    }

    /**
     * A provider with nothing to query answers with an empty collection, not with null: a client
     * receiving `null` where it expects a list has to special-case it, and API Platform would
     * serialise it as `null` rather than an empty hydra collection.
     */
    public function testAProviderWithoutAQueryBuilderReturnsAnEmptyCollection(): void
    {
        self::assertSame([], $this->provider()->provide(new GetCollection(class: Widget::class)));
    }

    public function testAnOperationWithoutAClassReturnsAnEmptyCollection(): void
    {
        $provider = $this->provider(collection: $this->queryBuilder());

        self::assertSame([], $provider->provide(new GetCollection()));
    }

    /**
     * Every registered extension must be applied — that is the whole contract. Here one extension
     * adds a WHERE, and the assertion is that it reached the query.
     */
    public function testEveryExtensionIsAppliedToTheQuery(): void
    {
        $queryBuilder = $this->queryBuilder();
        $extension = new class implements QueryCollectionExtensionInterface {
            public bool $applied = false;

            public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
            {
                $this->applied = true;
                $queryBuilder->andWhere('w.name IS NOT NULL');
            }
        };

        $this->provider(collection: $queryBuilder, extensions: [$extension])
            ->provide(new GetCollection(class: Widget::class));

        self::assertTrue($extension->applied);
        self::assertStringContainsString('WHERE w.name IS NOT NULL', $this->dql($queryBuilder));
    }

    /**
     * When a paginating extension answers, its result is returned **as it is** — not materialised.
     * Wrapping a paginator in an array would run the query and lose the total the response carries,
     * which is how a paginated endpoint starts reporting the wrong count.
     */
    public function testAPaginatingExtensionResultIsHandedBackLazily(): void
    {
        $widget = new Widget('paginé');
        $extension = new PaginatingExtension([$widget, 'pas un objet']);

        $result = $this->provider(collection: $this->queryBuilder(), extensions: [$extension])
            ->provide(new GetCollection(class: Widget::class));

        self::assertIsIterable($result);

        // Une ligne non-objet est écartée : un scalaire dans une réponse de collection casse la
        // sérialisation bien plus loin, là où la cause est introuvable.
        self::assertSame([$widget], iterator_to_array($result, false));
    }

    /**
     * Le test qui manquait, et qui a coûté une régression : un paginateur doit revenir **identique**.
     *
     * La version précédente de cette classe enveloppait tout résultat d'extension dans un
     * générateur, en croyant ne perdre que le typage. Elle perdait le paginateur : le sérialiseur
     * Hydra reconnaît `PaginatorInterface` pour écrire `totalItems` et `hydra:view`, un
     * `Generator` ne lui dit rien. Les réponses sont parties sans total, et les tests du bundle
     * n'ont rien vu — parce qu'ils vérifiaient les lignes rendues, jamais leur enveloppe. C'est
     * une suite de tests de trois projets plus loin qui l'a signalé.
     *
     * D'où `assertSame` : l'assertion porte sur l'identité de l'objet, pas sur son contenu.
     */
    public function testAPaginatorIsHandedBackUntouched(): void
    {
        $paginator = new FakePaginator([new Widget('page 1')]);

        $result = $this->provider(collection: $this->queryBuilder(), extensions: [new PaginatingExtension($paginator)])
            ->provide(new GetCollection(class: Widget::class));

        self::assertSame($paginator, $result, 'Envelopper le paginateur fait disparaître totalItems de la réponse.');
    }

    /**
     * @param list<QueryCollectionExtensionInterface> $extensions
     *
     * @return AbstractCollectionProvider<Widget>
     */
    private function provider(?Widget $item = null, ?QueryBuilder $collection = null, array $extensions = []): AbstractCollectionProvider
    {
        return new WidgetProvider($extensions, $item, $collection);
    }
}
