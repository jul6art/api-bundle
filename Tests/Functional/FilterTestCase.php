<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Functional;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\GetCollection;
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
}
