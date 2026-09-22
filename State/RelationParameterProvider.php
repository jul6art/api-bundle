<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\State;

use ApiPlatform\Metadata\Exception\InvalidArgumentException;
use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use ApiPlatform\State\ParameterNotFound;
use ApiPlatform\State\ParameterProviderInterface;

/**
 * Turns the IRIs a {@see \Jul6Art\ApiBundle\Filter\RelationFilter} receives into entities, and leaves
 * a plain identifier alone.
 *
 * ⚠️ **Why not API Platform's own `IriConverterParameterProvider`**: it accepts IRIs only. A plain
 * identifier (`?site=12`, which is what every datatable filter of this ecosystem sends) fails the
 * conversion, the failure is only LOGGED, and the filter then runs on nothing — the collection
 * answers with every row, silently. The legacy `SearchFilter` accepted both shapes, and so must its
 * replacement.
 *
 * ⚠️ **An IRI is resolved, never cut at its last segment.** The IRI of a resource may carry a public
 * identifier (an uuid) while Doctrine joins on the sequential one: only the converter knows which
 * entity `/api/items/0199…` designates.
 *
 * An IRI that designates nothing is replaced by a value no row can match, so the filter still
 * narrows — to nothing — instead of disappearing.
 */
final readonly class RelationParameterProvider implements ParameterProviderInterface
{
    /**
     * The value an unresolvable IRI is replaced with: an identifier no row carries.
     */
    public const int NO_MATCH = -1;

    public function __construct(
        private IriConverterInterface $iriConverter,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    public function provide(Parameter $parameter, array $parameters = [], array $context = []): ?Operation
    {
        $operation = $context['operation'] ?? null;
        $value = $parameter->getValue();

        if (!$value instanceof ParameterNotFound && null !== $value && '' !== $value) {
            $parameter->setValue(\is_array($value) ? array_map($this->resolve(...), array_values($value)) : $this->resolve($value));
        }

        return $operation instanceof Operation ? $operation : null;
    }

    private function resolve(mixed $value): mixed
    {
        if (!\is_string($value) || !str_starts_with($value, '/')) {
            return $value;
        }

        try {
            return $this->iriConverter->getResourceFromIri($value, ['fetch_data' => false]);
        } catch (InvalidArgumentException|ItemNotFoundException) {
            return self::NO_MATCH;
        }
    }
}
