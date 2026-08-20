<?php

declare(strict_types=1);

namespace Jul6Art\ApiBundle\Tests\Functional;

use Jul6Art\ApiBundle\Filter\CaseInsensitiveOrderFilter;
use Jul6Art\ApiBundle\Filter\ConcatOrderFilter;
use Jul6Art\ApiBundle\Filter\RankedOrderFilter;
use Jul6Art\ApiBundle\Tests\Fixtures\Entity\Widget;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The three ordering filters, asserted on their DQL.
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
        $queryBuilder = $this->queryBuilder();

        $this->caseInsensitive(['name' => null])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['order' => ['name' => 'asc']]],
        );

        $dql = $this->dql($queryBuilder);

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
        $queryBuilder = $this->queryBuilder();

        $this->caseInsensitive(['reference' => null])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['order' => ['reference' => 'desc']]],
        );

        $dql = $this->dql($queryBuilder);

        self::assertStringContainsString('ORDER BY w.reference DESC', $dql);
        self::assertStringNotContainsString('LOWER', $dql);
    }

    public function testAPropertyThatWasNotEnabledIsIgnored(): void
    {
        $queryBuilder = $this->queryBuilder();

        $this->caseInsensitive(['name' => null])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['order' => ['status' => 'asc']]],
        );

        self::assertStringNotContainsString('ORDER BY', $this->dql($queryBuilder));
    }

    public function testAnEmptyOrderingIsIgnored(): void
    {
        $queryBuilder = $this->queryBuilder();

        $this->caseInsensitive(['name' => null])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => []],
        );

        self::assertStringNotContainsString('ORDER BY', $this->dql($queryBuilder));
    }

    // ── RankedOrderFilter ─────────────────────────────────────────────────

    /**
     * A status column sorts alphabetically, which is meaningless: `done` before `todo` tells a user
     * nothing. This filter orders by a declared business rank instead, through a CASE expression.
     */
    public function testABusinessRankOrdersByCaseExpression(): void
    {
        $queryBuilder = $this->queryBuilder();

        $this->ranked()->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['order' => ['status' => 'asc']]],
        );

        $dql = $this->dql($queryBuilder);

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
        $queryBuilder = $this->queryBuilder();

        $this->ranked()->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['order' => ['status' => 'asc']]],
        );

        // The ELSE branch carries a rank above every declared one.
        self::assertMatchesRegularExpression('/ELSE (\d+) END/', $this->dql($queryBuilder));
    }

    // ── ConcatOrderFilter ─────────────────────────────────────────────────

    /**
     * Ordering by a person's name means ordering by two columns in one go; exposing them as one
     * sortable property is the whole point.
     */
    public function testOnePropertyOrdersBySeveralColumns(): void
    {
        $queryBuilder = $this->queryBuilder();

        new ConcatOrderFilter($this->registry, null, ['fullName' => ['name', 'status']])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['order' => ['fullName' => 'DESC']]],
        );

        $dql = $this->dql($queryBuilder);

        self::assertStringContainsString('w.name DESC', $dql);
        self::assertStringContainsString('w.status DESC', $dql);
    }

    public function testAnUnknownDirectionFallsBackToAscending(): void
    {
        $queryBuilder = $this->queryBuilder();

        new ConcatOrderFilter($this->registry, null, ['fullName' => ['name']])->apply(
            $queryBuilder,
            $this->nameGenerator(),
            Widget::class,
            $this->operation(),
            ['filters' => ['order' => ['fullName' => 'sideways']]],
        );

        self::assertStringContainsString('w.name ASC', $this->dql($queryBuilder));
    }

    /**
     * Two ordering filters on the same `order` parameter is the normal case — a resource sorts some
     * columns case-insensitively and others by business rank — and they must not erase each other.
     */
    public function testTwoOrderingFiltersCoexistOnTheSameParameter(): void
    {
        $queryBuilder = $this->queryBuilder();
        $context = ['filters' => ['order' => ['name' => 'asc', 'status' => 'desc']]];

        $this->caseInsensitive(['name' => null])->apply($queryBuilder, $this->nameGenerator(), Widget::class, $this->operation(), $context);
        $this->ranked()->apply($queryBuilder, $this->nameGenerator(), Widget::class, $this->operation(), $context);

        $dql = $this->dql($queryBuilder);

        self::assertStringContainsString('_ci_order_w_name', $dql);
        self::assertStringContainsString('CASE', $dql);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $properties
     */
    private function caseInsensitive(array $properties): CaseInsensitiveOrderFilter
    {
        return new CaseInsensitiveOrderFilter($this->registry, 'order', null, $properties);
    }

    private function ranked(): RankedOrderFilter
    {
        return new RankedOrderFilter($this->registry, 'order', null, [
            'status' => ['todo', 'doing', 'done'],
        ]);
    }
}
