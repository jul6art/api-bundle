<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Functional;

use Jul6Art\ApiBundle\Filter\JsonContainsFilter;
use Jul6Art\ApiBundle\Filter\OrSearchFilter;
use Jul6Art\ApiBundle\Filter\YearFilter;
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
        $dql = $this->applySearch(['reference'], '42');

        self::assertStringContainsString('CONCAT(w.reference', $dql);
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

    public function testAnEmptyTermFiltersNothing(): void
    {
        self::assertStringNotContainsString('WHERE', $this->applySearch(['name'], ''));
    }

    public function testANonScalarTermIsIgnored(): void
    {
        $queryBuilder = $this->queryBuilder();

        new OrSearchFilter($this->registry, null, ['name' => null])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => [OrSearchFilter::PARAMETER_NAME => ['not', 'a', 'string']]],
        );

        self::assertStringNotContainsString('WHERE', $this->dql($queryBuilder));
    }

    public function testAColumnThatWasNotDeclaredIsNeverSearched(): void
    {
        self::assertStringNotContainsString('w.status', $this->applySearch(['name'], 'tourne'));
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
        $queryBuilder = $this->queryBuilder();

        new YearFilter($this->registry, null, ['issuedAt' => null])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['issuedAt' => '2026']],
        );

        $dql = $this->dql($queryBuilder);

        self::assertStringContainsString('w.issuedAt >=', $dql);
        self::assertStringContainsString('w.issuedAt <', $dql);
        self::assertStringNotContainsString('YEAR(', $dql, 'YEAR() ne serait ni portable ni indexable.');
    }

    public function testANonYearValueIsIgnored(): void
    {
        $queryBuilder = $this->queryBuilder();

        new YearFilter($this->registry, null, ['issuedAt' => null])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['issuedAt' => 'l\'an dernier']],
        );

        self::assertStringNotContainsString('WHERE', $this->dql($queryBuilder));
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
        $queryBuilder = $this->queryBuilder();

        new JsonContainsFilter($this->registry, null, ['roles' => null])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['roles' => 'ROLE_ADMIN']],
        );

        $dql = $this->dql($queryBuilder);

        self::assertStringContainsString('JSON_TEXT(w.roles)', $dql);
        self::assertStringContainsString('LIKE', $dql);
    }

    public function testAnUndeclaredJsonPropertyIsIgnored(): void
    {
        $queryBuilder = $this->queryBuilder();

        new JsonContainsFilter($this->registry, null, ['roles' => null])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['name' => 'tourne']],
        );

        self::assertStringNotContainsString('WHERE', $this->dql($queryBuilder));
    }

    /**
     * The description feeds the OpenAPI document; an empty one means the filter exists and nobody
     * can discover it.
     */
    public function testTheFilterDescribesItselfForOpenApi(): void
    {
        $description = new JsonContainsFilter($this->registry, null, ['roles' => null])->getDescription(Widget::class);

        self::assertArrayHasKey('roles', $description);
        self::assertSame('roles', $description['roles']['property'] ?? null);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * @param list<string> $properties
     */
    private function applySearch(array $properties, string $term): string
    {
        $queryBuilder = $this->queryBuilder();

        new OrSearchFilter($this->registry, null, array_fill_keys($properties, null))->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => [OrSearchFilter::PARAMETER_NAME => $term]],
        );

        return $this->dql($queryBuilder);
    }
}
