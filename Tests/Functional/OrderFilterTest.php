<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Functional;

use ApiPlatform\Doctrine\Common\Filter\OrderFilterInterface;
use Jul6Art\ApiBundle\Filter\CaseInsensitiveOrderFilter;
use Jul6Art\ApiBundle\Filter\ConcatOrderFilter;
use Jul6Art\ApiBundle\Filter\RankedOrderFilter;
use Jul6Art\ApiBundle\Tests\Fixtures\Entity\Widget;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The three ordering filters, asserted on their DQL.
 *
 * Since API Platform 4.4 a filter is handed the `QueryParameter` it was declared on, with the request
 * value already extracted — which property it orders is the parameter's, never a `properties` map of
 * the filter's own. What is sortable at all is therefore decided by the resource declaration (the
 * `properties` of the `order[:property]` template), and API Platform never calls a filter for a
 * property the resource did not list.
 */
#[CoversNothing]
final class OrderFilterTest extends FilterTestCase
{
    // ── CaseInsensitiveOrderFilter ────────────────────────────────────────

    /**
     * The reason this filter exists: PostgreSQL sorts `Apple, banana, Cherry` by byte value, so an
     * unqualified `ORDER BY name` puts every capital before every lowercase letter and the list
     * looks broken to a user.
     *
     * The reason it is not a plain `ORDER BY LOWER(...)`: API Platform's pagination extension
     * parses ORDER BY clauses by splitting on `.` and treating the first token as an alias, so
     * `LOWER(w.name)` makes it fail with `The alias "LOWER(w" does not exist`. Hence a HIDDEN
     * select with a flat alias — and that indirection is the thing a future refactor would remove
     * without knowing why it is there.
     */
    public function testATextColumnIsOrderedThroughAHiddenLoweredSelect(): void
    {
        $dql = $this->applyFilter(new CaseInsensitiveOrderFilter(), $this->parameter('order[name]', 'asc', 'name'));

        self::assertStringContainsString('LOWER(w.name) AS HIDDEN _ci_order_w_name', $dql);
        self::assertStringContainsString('ORDER BY _ci_order_w_name ASC', $dql);
        self::assertStringNotContainsString('ORDER BY LOWER(', $dql, 'Un LOWER() dans le ORDER BY casse le parseur de pagination.');
    }

    /**
     * A numeric column has no case, so lowering it would be noise — and `LOWER()` on an integer is
     * a cast the database would rather not do on every row.
     */
    public function testANumericColumnIsOrderedDirectly(): void
    {
        $dql = $this->applyFilter(new CaseInsensitiveOrderFilter(), $this->parameter('order[reference]', 'desc', 'reference'));

        self::assertStringContainsString('ORDER BY w.reference DESC', $dql);
        self::assertStringNotContainsString('LOWER', $dql);
    }

    /**
     * A related text column is LEFT-joined — a widget without a category must stay in the list — and
     * lowered like any other text column, because the case problem does not stop at the root entity.
     */
    public function testARelatedTextColumnIsJoinedAndLowered(): void
    {
        $parameter = $this->nested($this->parameter('order[category.label]', 'asc', 'category.label'), ['category'], [Widget::class], 'label');

        $dql = $this->applyFilter(new CaseInsensitiveOrderFilter(), $parameter);

        self::assertStringContainsString('LEFT JOIN w.category', $dql);
        self::assertMatchesRegularExpression('/LOWER\(\w+\.label\) AS HIDDEN/', $dql);
    }

    /**
     * The direction is the request's, and only `asc` / `desc` are directions: anything else orders
     * nothing rather than guessing.
     */
    public function testAnUnknownDirectionOrdersNothing(): void
    {
        self::assertStringNotContainsString('ORDER BY', $this->applyFilter(new CaseInsensitiveOrderFilter(), $this->parameter('order[name]', 'sideways', 'name')));
    }

    public function testAParameterWithoutAPropertyOrdersNothing(): void
    {
        self::assertStringNotContainsString('ORDER BY', $this->applyFilter(new CaseInsensitiveOrderFilter(), $this->parameter('order[name]', 'asc')));
    }

    /**
     * The NULL placement is a constructor option now: it used to sit in the per-property map that
     * `AbstractFilter` resolved, which the 4.4 filters no longer receive.
     */
    public function testNullsCanBePlacedAfterEveryValue(): void
    {
        $dql = $this->applyFilter(new CaseInsensitiveOrderFilter(OrderFilterInterface::NULLS_ALWAYS_LAST), $this->parameter('order[status]', 'asc', 'status'));

        self::assertStringContainsString('CASE WHEN w.status IS NULL THEN 0 ELSE 1 END AS HIDDEN _w_status_null_rank', $dql);
        self::assertStringContainsString('ORDER BY _w_status_null_rank DESC, _ci_order_w_status ASC', $dql);
    }

    // ── RankedOrderFilter ─────────────────────────────────────────────────

    /**
     * A status column sorts alphabetically, which is meaningless: `done` before `todo` tells a user
     * nothing. This filter orders by a declared business rank instead, through a CASE expression.
     */
    public function testABusinessRankOrdersByCaseExpression(): void
    {
        $dql = $this->applyFilter($this->ranked(), $this->parameter('order[status]', 'asc', 'status'));

        self::assertStringContainsString('CASE', $dql);
        self::assertStringContainsString('THEN 0', $dql);
        self::assertStringContainsString('THEN 1', $dql);
        self::assertStringContainsString('ORDER BY _rank_order_w_status ASC', $dql);

        // Les valeurs sont **liées**, pas interpolées : un statut venant de la requête ne se
        // retrouve jamais dans le texte du DQL.
        self::assertMatchesRegularExpression('/WHEN w\.status = :\w+/', $dql);
        self::assertStringNotContainsString("'todo'", $dql);
    }

    /**
     * A value outside the declared ranking must land **last**, not first: a row whose status nobody
     * planned for should not open the list.
     */
    public function testAnUnrankedValueSortsLast(): void
    {
        $dql = $this->applyFilter($this->ranked(), $this->parameter('order[status]', 'asc', 'status'));

        // The ELSE branch carries a rank above every declared one: three ranks, ELSE 3.
        self::assertStringContainsString('ELSE 3 END', $dql);
    }

    public function testARankingWithoutValuesOrdersNothing(): void
    {
        self::assertStringNotContainsString('ORDER BY', $this->applyFilter(new RankedOrderFilter(), $this->parameter('order[status]', 'asc', 'status')));
    }

    // ── ConcatOrderFilter ─────────────────────────────────────────────────

    /**
     * Ordering by a person's name means ordering by two columns in one go; exposing them as one
     * sortable property is the whole point.
     */
    public function testOnePropertyOrdersBySeveralColumns(): void
    {
        $dql = $this->applyFilter(new ConcatOrderFilter(['name', 'status']), $this->parameter('order[fullName]', 'DESC'));

        self::assertStringContainsString('ORDER BY w.name DESC, w.status DESC', $dql);
    }

    public function testAnUnknownDirectionFallsBackToAscending(): void
    {
        self::assertStringContainsString('w.name ASC', $this->applyFilter(new ConcatOrderFilter(['name']), $this->parameter('order[fullName]', 'sideways')));
    }

    // ── the three together ────────────────────────────────────────────────

    /**
     * Several ordering filters on one resource is the normal case — a resource sorts some columns
     * case-insensitively and others by business rank — and each must add its clause without erasing
     * the other's. API Platform applies them one after the other, on the same query.
     */
    public function testTwoOrderingFiltersCoexistOnTheSameQuery(): void
    {
        $queryBuilder = $this->queryBuilder();

        $this->applyFilter(new CaseInsensitiveOrderFilter(), $this->parameter('order[name]', 'asc', 'name'), $queryBuilder);
        $dql = $this->applyFilter($this->ranked(), $this->parameter('order[status]', 'desc', 'status'), $queryBuilder);

        self::assertStringContainsString('ORDER BY _ci_order_w_name ASC, _rank_order_w_status DESC', $dql);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function ranked(): RankedOrderFilter
    {
        return new RankedOrderFilter(['todo', 'doing', 'done']);
    }
}
