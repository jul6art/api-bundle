<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Functional;

use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use Jul6Art\ApiBundle\Filter\JsonContainsFilter;
use Jul6Art\ApiBundle\Filter\OrSearchFilter;
use Jul6Art\ApiBundle\Filter\RelationFilter;
use Jul6Art\ApiBundle\Filter\YearFilter;
use Jul6Art\ApiBundle\State\RelationParameterProvider;
use Jul6Art\ApiBundle\Tests\Fixtures\Entity\Widget;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The filters that narrow a collection rather than order it.
 */
#[CoversNothing]
final class SearchFilterTest extends FilterTestCase
{
    // ── OrSearchFilter ────────────────────────────────────────────────────

    /**
     * One search box over several columns: the parameter is a single `search=…` and the filter
     * turns it into one OR group. Anything else — a WHERE per column — would AND them together and
     * a search would only ever match a row where every column contains the term.
     */
    public function testOneTermSearchesEveryDeclaredColumnWithOr(): void
    {
        $dql = $this->applySearch(['name', 'status'], 'tourne');

        self::assertStringContainsString('LIKE', $dql);
        self::assertStringContainsString(' OR ', $dql);
        self::assertStringContainsString('LOWER(w.name)', $dql);
        self::assertStringContainsString('LOWER(w.status)', $dql);
    }

    /**
     * A numeric column cannot take a `LIKE` directly on every platform, so it is cast first. Without
     * this, searching a reference number either fails or silently matches nothing.
     */
    public function testANumericColumnIsCastBeforeBeingMatched(): void
    {
        self::assertStringContainsString('CONCAT(w.reference', $this->applySearch(['reference'], '42'));
    }

    /**
     * A column one hop away is reached by a LEFT JOIN — not an inner one: a widget without a
     * category must still appear when the term matches its own name.
     */
    public function testARelationIsReachedWithALeftJoin(): void
    {
        $dql = $this->applySearch(['name', 'category.label'], 'outil');

        self::assertStringContainsString('LEFT JOIN', $dql);
        self::assertStringNotContainsString('INNER JOIN', $dql);
    }

    /**
     * An EMBEDDABLE is addressed directly — `w.address.city` — and must NOT be joined.
     *
     * ⚠️ A dotted path is not proof of a relation. Doctrine maps an embeddable's fields into the
     * holder's own table, so `LEFT JOIN w.address` raises `Association name expected, 'address' is
     * not an association.` — an uncaught 500 on a collection that answered perfectly until someone
     * typed in the search box. Found on wovex the 2026-08-25: `/api/sites?search=…` had been
     * broken since the day the filter named `address.city`, and no screen had used it yet.
     */
    public function testAnEmbeddedFieldIsReadDirectlyRatherThanJoined(): void
    {
        $dql = $this->applySearch(['name', 'address.city'], 'luxembourg');

        self::assertStringContainsString('LOWER(w.address.city)', $dql);
        self::assertStringNotContainsString('JOIN', $dql);
    }

    /** And an embedded NUMBER is cast like any other, since the path is what changed, not the type. */
    public function testAnEmbeddedNumericFieldIsCastBeforeBeingMatched(): void
    {
        $dql = $this->applySearch(['address.floor'], '3');

        self::assertStringContainsString('CONCAT(w.address.floor', $dql);
        self::assertStringNotContainsString('JOIN', $dql);
    }

    public function testAnEmptyTermFiltersNothing(): void
    {
        self::assertStringNotContainsString('WHERE', $this->applySearch(['name'], ''));
    }

    public function testANonScalarTermIsIgnored(): void
    {
        $dql = $this->applyFilter(new OrSearchFilter(), $this->parameter(OrSearchFilter::PARAMETER_NAME, ['not', 'a', 'string'], properties: ['name']));

        self::assertStringNotContainsString('WHERE', $dql);
    }

    public function testAColumnThatWasNotDeclaredIsNeverSearched(): void
    {
        self::assertStringNotContainsString('w.status', $this->applySearch(['name'], 'tourne'));
    }

    /**
     * A path through something that is neither a field nor a relation is skipped, not joined: a
     * mistyped property would otherwise turn every search into a 500.
     */
    public function testAnUnknownRelationIsSkipped(): void
    {
        $dql = $this->applySearch(['name', 'nowhere.label'], 'tourne');

        self::assertStringNotContainsString('nowhere', $dql);
        self::assertStringContainsString('LOWER(w.name)', $dql);
    }

    // ── RelationFilter ────────────────────────────────────────────────────

    /**
     * The shape every datatable of this ecosystem sends: a plain identifier. API Platform 4.4 maps a
     * relation `SearchFilter` to `IriFilter`, which would log this value as an error and filter
     * NOTHING — the collection would answer with every row.
     */
    public function testAPlainIdentifierFiltersTheRelation(): void
    {
        $dql = $this->applyFilter(new RelationFilter(), $this->parameter('category', '12', 'category'));

        self::assertMatchesRegularExpression('/WHERE w\.category = :\w+/', $dql);
    }

    public function testSeveralIdentifiersBecomeAnInList(): void
    {
        $dql = $this->applyFilter(new RelationFilter(), $this->parameter('category', ['12', '14'], 'category'));

        self::assertMatchesRegularExpression('/WHERE w\.category IN \(:\w+\)/', $dql);
    }

    /**
     * A relation of a relation (`site.customer`) is joined first, then compared — the path is what a
     * screen filtering equipment by customer sends.
     */
    public function testANestedRelationIsJoinedBeforeBeingCompared(): void
    {
        $parameter = $this->nested($this->parameter('category.parent', '3', 'category.parent'), ['category'], [Widget::class], 'parent');

        $dql = $this->applyFilter(new RelationFilter(), $parameter);

        self::assertStringContainsString('JOIN w.category', $dql);
        self::assertMatchesRegularExpression('/\w+\.parent = :\w+/', $dql);
    }

    public function testAnOperatorMapIsNotARelationLookup(): void
    {
        self::assertStringNotContainsString('WHERE', $this->applyFilter(new RelationFilter(), $this->parameter('category', ['gt' => '3'], 'category')));
    }

    /**
     * The IRIs are resolved by the provider, BEFORE the filter runs; the filter is told which one it
     * needs, so API Platform calls it.
     */
    public function testTheFilterDeclaresTheProviderThatResolvesIris(): void
    {
        self::assertSame(RelationParameterProvider::class, RelationFilter::getParameterProvider());
    }

    // ── YearFilter ────────────────────────────────────────────────────────

    /**
     * A year is expressed as a **half-open range**, not `YEAR(col) = …`.
     *
     * Two reasons, and both matter: `YEAR()` is not portable across platforms without a DQL
     * extension, and wrapping the column in a function makes any index on it useless — which turns
     * a filtered accounting journal from an index scan into a full table scan.
     */
    public function testAYearBecomesAHalfOpenRange(): void
    {
        $dql = $this->applyFilter(new YearFilter(), $this->parameter('issuedAt', '2026', 'issuedAt'));

        self::assertStringContainsString('w.issuedAt >=', $dql);
        self::assertStringContainsString('w.issuedAt <', $dql);
        self::assertStringNotContainsString('YEAR(', $dql, 'YEAR() ne serait ni portable ni indexable.');
    }

    public function testANonYearValueIsIgnored(): void
    {
        self::assertStringNotContainsString('WHERE', $this->applyFilter(new YearFilter(), $this->parameter('issuedAt', 'l\'an dernier', 'issuedAt')));
    }

    /**
     * The `[after]` / `[before]` map belongs to a `DateFilter` chained on the same key: this filter
     * must leave it alone rather than read it as a year.
     */
    public function testADateRangeIsLeftToTheDateFilter(): void
    {
        self::assertStringNotContainsString('WHERE', $this->applyFilter(new YearFilter(), $this->parameter('issuedAt', ['after' => '2026-01-01'], 'issuedAt')));
    }

    // ── JsonContainsFilter ────────────────────────────────────────────────

    /**
     * Searching inside a JSON column without a platform-specific operator: the column is cast to
     * text by `JSON_TEXT()` — the DQL function jul6art/core-bundle registers — and matched with a
     * portable LIKE.
     *
     * > That dependency is the trap: without the function registered in `doctrine.orm.dql`, this
     * > filter fails at query time with "Expected known function", far from here.
     */
    public function testAJsonArrayIsSearchedThroughJsonText(): void
    {
        $dql = $this->applyFilter(new JsonContainsFilter(), $this->parameter('roles', 'ROLE_ADMIN', 'roles'));

        self::assertStringContainsString('JSON_TEXT(w.roles)', $dql);
        self::assertStringContainsString('LIKE', $dql);
    }

    public function testAnEmptyJsonTermIsIgnored(): void
    {
        self::assertStringNotContainsString('WHERE', $this->applyFilter(new JsonContainsFilter(), $this->parameter('roles', '', 'roles')));
    }

    /**
     * The OpenAPI document is built from the parameter now — `getDescription()` answers an empty
     * array by contract — and a filter nobody can discover is a filter nobody uses.
     */
    public function testTheFilterDescribesItsParameterForOpenApi(): void
    {
        $openApi = new JsonContainsFilter()->getOpenApiParameters($this->parameter('roles', null, 'roles'));
        // Without `castToArray`, API Platform documents the scalar AND the array form of the key.
        $names = [];
        foreach (\is_array($openApi) ? $openApi : [$openApi] as $parameter) {
            if ($parameter instanceof OpenApiParameter) {
                $names[] = $parameter->getName();
            }
        }

        self::assertContains('roles', $names);
        self::assertSame([], new JsonContainsFilter()->getDescription(Widget::class));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * @param list<string> $properties
     */
    private function applySearch(array $properties, string $term): string
    {
        return $this->applyFilter(new OrSearchFilter(), $this->parameter(OrSearchFilter::PARAMETER_NAME, $term, properties: $properties));
    }
}
