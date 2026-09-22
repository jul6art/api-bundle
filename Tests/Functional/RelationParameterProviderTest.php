<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Functional;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use Jul6Art\ApiBundle\State\RelationParameterProvider;
use Jul6Art\ApiBundle\Tests\Fixtures\Entity\Category;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * What the relation filter compares is decided here, before it runs.
 *
 * The three shapes that matter are the three a client sends: a plain identifier (every datatable
 * filter of this ecosystem), an IRI (an API client, a Select2 bound to `@id`), and an IRI that
 * designates nothing — which must narrow the collection to nothing, never widen it to everything.
 */
#[CoversNothing]
final class RelationParameterProviderTest extends TestCase
{
    public function testAPlainIdentifierIsLeftForTheFilterToCompare(): void
    {
        self::assertSame('12', $this->provided('12'));
    }

    /**
     * An IRI is RESOLVED to the entity it designates — never cut at its last segment, which would be
     * wrong for any resource whose IRI carries a public identifier rather than the database one.
     */
    public function testAnIriBecomesTheEntityItDesignates(): void
    {
        self::assertInstanceOf(Category::class, $this->provided('/api/categories/12'));
    }

    /**
     * ⚠️ The failure this provider exists to avoid: API Platform's own IRI provider only LOGS a value
     * it cannot convert, and the filter then compares nothing — the collection answers with every
     * row. An unknown IRI must instead match no row at all.
     */
    public function testAnIriThatDesignatesNothingMatchesNoRow(): void
    {
        self::assertSame(RelationParameterProvider::NO_MATCH, $this->provided('/api/categories/404'));
    }

    public function testAListIsResolvedValueByValue(): void
    {
        $values = $this->provided(['12', '/api/categories/12']);

        self::assertIsArray($values);
        self::assertSame('12', $values[0]);
        self::assertInstanceOf(Category::class, $values[1]);
    }

    private function provided(mixed $value): mixed
    {
        $parameter = new QueryParameter(key: 'category', property: 'category');
        $parameter->setValue($value);

        new RelationParameterProvider($this->converter())->provide($parameter);

        return $parameter->getValue();
    }

    private function converter(): IriConverterInterface
    {
        return new class implements IriConverterInterface {
            public function getResourceFromIri(string $iri, array $context = [], ?Operation $operation = null): Category
            {
                if ('/api/categories/12' !== $iri) {
                    throw new ItemNotFoundException(\sprintf('No resource at "%s".', $iri));
                }

                return new \ReflectionClass(Category::class)->newInstanceWithoutConstructor();
            }

            public function getIriFromResource(object|string $resource, int $referenceType = UrlGeneratorInterface::ABS_PATH, ?Operation $operation = null, array $context = []): ?string
            {
                return null;
            }
        };
    }
}
