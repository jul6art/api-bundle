<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Functional;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Jul6Art\ApiBundle\Tests\Fixtures\Entity\Widget;

/**
 * Shared plumbing for the filter tests.
 *
 * The filters are asserted on the **DQL they produce**, against a real entity manager. Mocking the
 * query builder would only restate what the filter was written to do; the DQL is what Doctrine
 * actually receives, and it is where a wrong alias or a non-portable function shows up.
 */
abstract class FilterTestCase extends AbstractFunctionalTestCase
{
    protected ManagerRegistry $registry;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $container = $this->boot('test', [], withOrm: true);

        $registry = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $this->registry = $registry;

        $entityManager = $container->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        // Le schéma est créé même pour les tests qui n'assertent que du DQL : le chemin de repli du
        // provider (aucune extension paginante) exécute réellement la requête, et sans table
        // l'erreur qui remonte parle de SQLite plutôt que du provider.
        new SchemaTool($this->entityManager)->createSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
        );
    }

    final protected function queryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from(Widget::class, 'w');
    }

    final protected function operation(): GetCollection
    {
        return new GetCollection(class: Widget::class);
    }

    final protected function nameGenerator(): QueryNameGenerator
    {
        return new QueryNameGenerator();
    }

    /**
     * The DQL on one line, so an assertion can read as the query does.
     */
    final protected function dql(QueryBuilder $queryBuilder): string
    {
        return (string) preg_replace('/\s+/', ' ', $queryBuilder->getDQL());
    }

    /**
     * A parameter as API Platform hands it to a filter: its value already extracted from the request.
     *
     * @param list<string>|null $properties
     */
    final protected function parameter(string $key, mixed $value, ?string $property = null, ?array $properties = null): QueryParameter
    {
        $parameter = new QueryParameter(key: $key, property: $property, properties: $properties);
        $parameter->setValue($value);

        return $parameter;
    }

    /**
     * The same parameter, carrying the `nested_properties_info` API Platform's parameter factory
     * computes for a dotted property — without it, API Platform's nested-join helper assumes a plain
     * field and joins nothing.
     *
     * @param list<string>       $relations       the relation segments (`['category']`)
     * @param list<class-string> $relationClasses the class each segment is read on
     */
    final protected function nested(QueryParameter $parameter, array $relations, array $relationClasses, string $leafProperty): QueryParameter
    {
        $property = (string) $parameter->getProperty();
        $value = $parameter->getValue();

        // ⚠️ `withExtraProperties()` answers a copy WITHOUT the extracted value: a filter handed that
        // copy sees no value and returns before touching the query, which reads exactly like a
        // filter that forgot to join.
        $nested = $parameter->withExtraProperties([
            'nested_properties_info' => [
                $property => [
                    'relation_segments' => $relations,
                    'converted_relation_segments' => $relations,
                    'relation_classes' => $relationClasses,
                    'leaf_property' => $leafProperty,
                ],
            ],
        ]);
        $nested->setValue($value);

        return $nested;
    }

    /**
     * Applies `$filter` for `$parameter` on a fresh Widget query and returns the DQL.
     */
    final protected function applyFilter(FilterInterface $filter, QueryParameter $parameter, ?QueryBuilder $queryBuilder = null): string
    {
        $queryBuilder ??= $this->queryBuilder();

        $filter->apply($queryBuilder, $this->nameGenerator(), Widget::class, $this->operation(), ['parameter' => $parameter]);

        return $this->dql($queryBuilder);
    }
}
