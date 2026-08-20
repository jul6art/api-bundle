<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\State;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\PartialPaginatorInterface;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Abstract State Provider that applies pagination and filters via API Platform extensions.
 *
 * Custom providers should:
 * - Extend this class
 * - Return QueryBuilder from getCollectionQueryBuilder() instead of array
 * - Return entity|null for item operations
 *
 * This ensures API Platform's pagination, filters, and ordering work automatically.
 *
 * ## What the generic parameter covers, and what it deliberately does not
 *
 * A subclass declares what it provides — `@extends AbstractCollectionProvider<Invoice>` — and the
 * item operation is then checked against `Invoice`: a provider that returns the wrong entity is a
 * static error rather than a serialisation surprise further downstream.
 *
 * The **collection** side is typed `iterable<object>` and not `iterable<T>`, because that is the
 * whole of what can honestly be proven here: API Platform's pagination extension returns a bare,
 * unparameterised `iterable`. Claiming `iterable<T>` would mean suppressing an error to state
 * something this class cannot check, which buys a promise nobody verifies. The half that can be
 * verified is kept; the half that cannot is not pretended.
 *
 * @template T of object
 *
 * @implements ProviderInterface<object>
 */
abstract class AbstractCollectionProvider implements ProviderInterface
{
    /**
     * @param iterable<QueryCollectionExtensionInterface> $collectionExtensions
     */
    public function __construct(
        private readonly iterable $collectionExtensions,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return iterable<object>|T|null
     */
    final public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        // Item operation — delegate to concrete provider
        if (isset($uriVariables['id'])) {
            return $this->provideItem($operation, $uriVariables, $context);
        }

        // Collection operation — build QueryBuilder and apply extensions
        $queryBuilder = $this->provideCollection($operation, $uriVariables, $context);

        if (!$queryBuilder instanceof QueryBuilder) {
            return [];
        }

        $queryNameGenerator = new QueryNameGenerator();
        $resourceClass = $operation->getClass();

        if (null === $resourceClass) {
            return [];
        }

        // Apply all registered extensions (pagination, filters, order, etc.)
        foreach ($this->collectionExtensions as $extension) {
            $extension->applyToCollection($queryBuilder, $queryNameGenerator, $resourceClass, $operation, $context);

            // If an extension returns a result (pagination), use it
            if ($extension instanceof QueryResultCollectionExtensionInterface
                && $extension->supportsResult($resourceClass, $operation, $context)) {
                $result = $extension->getResult($queryBuilder, $resourceClass, $operation, $context);

                // ⚠️ Un paginateur est rendu **tel quel**, sans être parcouru, enveloppé ni
                // converti. C'est lui qui porte le total : le sérialiseur Hydra lit
                // `PaginatorInterface` pour écrire `totalItems` et `hydra:view`. L'envelopper —
                // même dans un générateur paresseux — produit une réponse sans total et sans
                // liens de page, et aucun test assertant sur le *contenu* d'une collection ne le
                // voit. Cette branche existe pour ça.
                //
                // `PartialPaginatorInterface` est `Traversable<T of object>` : le contrat
                // `iterable<object>` est donc démontré ici, sans rien affirmer d'invérifiable.
                if ($result instanceof PartialPaginatorInterface) {
                    return $result;
                }

                return self::objectsOnly($result);
            }
        }

        // Fallback: no pagination extension applied → return raw results
        $result = $queryBuilder->getQuery()->getResult();

        return is_iterable($result) ? self::objectsOnly($result) : [];
    }

    /**
     * Le typage que le contrat exige et qu'API Platform ne fournit pas, pour les seuls résultats
     * qui ne sont pas un paginateur (un `getResult()` de requête, ou une extension maison).
     *
     * Une ligne qui ne serait pas un objet est ignorée plutôt que rendue : un scalaire dans une
     * réponse de collection casse la sérialisation plus loin, là où la cause est introuvable.
     * Le générateur reste paresseux — rien n'oblige à matérialiser ce qu'on ne compte pas.
     *
     * @param iterable<mixed> $result
     *
     * @return iterable<object>
     */
    private static function objectsOnly(iterable $result): iterable
    {
        foreach ($result as $item) {
            if (\is_object($item)) {
                yield $item;
            }
        }
    }

    /**
     * Provide the QueryBuilder for collection operations.
     *
     * This method MUST return a QueryBuilder (not an array) so that extensions can be applied.
     *
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    abstract protected function provideCollection(Operation $operation, array $uriVariables, array $context): ?QueryBuilder;

    /**
     * Provide a single item for item operations (GET /resource/{id}).
     *
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return T|null
     */
    abstract protected function provideItem(Operation $operation, array $uriVariables, array $context): ?object;
}
