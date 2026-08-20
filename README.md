Symfony API Platform bundle
===========================

Symfony API Platform bundle

Requirements
------------

- PHP ^8.5
- Symfony ^7.4 || ^8.0

Installation
------------

```shell
composer require jul6art/api-bundle
```

Then register it in `config/bundles.php` (Flex does this for you):

```php
Jul6Art\ApiBundle\ApiBundle::class => ['all' => true],
```

Configuration
-------------

```yaml
# config/packages/api.yaml
api:
    # Leaves the bundle installed and inert when false.
    enabled: true
```

`api.enabled` is also exposed as a container parameter.

Usage
-----

Six Doctrine ORM filters and one state provider, extracted from an application that runs them.
Everything here is registered the ordinary API Platform way — as a service, referenced from an
`#[ApiFilter]` — so there is nothing to configure beyond the tenant header below.

### Ordering a text column the way a human reads it

```php
#[ApiFilter(CaseInsensitiveOrderFilter::class, properties: ['name', 'email'])]
class Contact { … }
```

`ORDER BY name` sorts by byte value on PostgreSQL, so `Apple, banana, Cherry` comes back as
`Apple, Cherry, banana` and the list looks broken. This filter lowers the column first — and only
for text columns, since lowering an integer is a cast the database does per row for nothing.

> ⚠️ **It does not emit `ORDER BY LOWER(...)`, and that is deliberate.** API Platform's pagination
> extension parses ORDER BY by splitting on `.` and taking the first token as an alias, so
> `LOWER(c.name)` makes it fail with `The alias "LOWER(c" does not exist`. The filter adds a HIDDEN
> select with a flat alias and orders by that instead. Removing the indirection brings the bug
> back.

### Ordering by business rank rather than alphabet

```php
#[ApiFilter(RankedOrderFilter::class, properties: ['status' => ['todo', 'doing', 'done']])]
```

`ORDER BY status` gives `doing, done, todo` — alphabetical, and meaningless to a user. This orders
by the rank you declare, through a CASE expression with **bound** values. A value outside the list
sorts last, never first: a status nobody planned for should not open the list.

### One sortable property over several columns

```php
#[ApiFilter(ConcatOrderFilter::class, properties: ['fullName' => ['lastName', 'firstName']])]
```

### One search box over several columns

```php
#[ApiFilter(OrSearchFilter::class, properties: ['name', 'reference', 'category.label'])]
```

`?search=term` becomes a single OR group. A filter per column would AND them together and only
match rows where *every* column contains the term.

Three things it handles that a naive implementation does not: a numeric column is cast
(`CONCAT(col, '')`) before the LIKE, a relation one hop away is reached with a **LEFT** JOIN — so a
row without a category still matches on its own name — and a non-scalar or empty term filters
nothing rather than producing `LIKE '%%'`.

### Filtering by year

```php
#[ApiFilter(YearFilter::class, properties: ['issuedAt'])]
```

`?issuedAt=2026` becomes a half-open range, `>= 2026-01-01 AND < 2027-01-01` — not `YEAR(col) =
2026`. Two reasons: `YEAR()` needs a DQL extension to be portable, and wrapping the column in a
function makes any index on it useless, which turns a filtered accounting journal from an index
scan into a full table scan.

### Searching inside a JSON column

```php
#[ApiFilter(JsonContainsFilter::class, properties: ['roles'])]
```

`?roles=ROLE_ADMIN` casts the column to text and matches it with a portable LIKE.

> ⚠️ **This one needs a DQL function registered.** `JSON_TEXT()` comes from
> jul6art/core-bundle, and without the registration below the filter fails at query time with
> "Expected known function", far from the entity that declared it:
>
> ```yaml
> # config/packages/doctrine.yaml
> doctrine:
>     orm:
>         dql:
>             string_functions:
>                 JSON_TEXT: Jul6Art\CoreBundle\Doctrine\DQL\JsonTextFunction
> ```

### A custom provider that keeps pagination, filters and ordering

```php
final class OpenInvoiceProvider extends AbstractCollectionProvider
{
    protected function provideCollection(Operation $operation, array $uriVariables, array $context): ?QueryBuilder
    {
        return $this->invoices->createQueryBuilder('i')->andWhere('i.paidAt IS NULL');
    }

    protected function provideItem(Operation $operation, array $uriVariables, array $context): ?Invoice
    {
        return $this->invoices->find($uriVariables['id']);
    }
}
```

**Return a QueryBuilder, never an array.** API Platform's extensions act on a query builder, so a
provider that returns rows silently ignores every `?page=`, `?order=` and `?search=` a client
sends. Nothing errors — the collection is simply always the first page, unsorted and unfiltered,
which is the kind of bug that reaches production because every test asserts on content rather than
on order.

The class returns an empty collection rather than `null` when there is nothing to query, hands a
paginator back untouched — materialising it would run the query and lose the total the response
carries — and drops any row that is not an object, because a scalar in a collection response breaks
serialisation somewhere it cannot be traced back from.

### The tenant header

```yaml
# config/packages/api.yaml
api:
    tenant_header: X-ORGANIZATION
```

`Api\ApiHeaders` holds the two rate-limit header names, which are conventional. The
tenant-scoping header is **not** a constant here: its name belongs to the application, renaming it
breaks every client, and it therefore has to be visible in the application's own configuration
rather than buried in a vendor class. Read it from the `api.tenant_header` parameter — a request
subscriber, an OpenAPI factory and a CORS rule all need the same value, and a literal copied into
one of the three is how they drift.

### A note on two of these filters

`CaseInsensitiveOrderFilter` and `RankedOrderFilter` restate a good deal of API Platform's own
`OrderFilter`, which is `final` and cannot be extended. That is a debt, and the point of this
bundle is to carry it **once** instead of in every application. The test suite asserts the DQL
those filters produce, so an upstream change that alters the contract shows up here rather than in
a sorted list nobody checks.

Quality assurance
-----------------

```shell
composer qa            # cs-check + rector-check + phpstan (level max) + phpunit
```

Run `composer qa`, not the single tool you have in mind: the CI's "Coding standards" job runs
Rector too, and its `lowest deps` job installs the minimum of every constraint — which is where
this ecosystem has repeatedly found what a local run could not.

`extra.symfony.require` states which Symfony line this bundle targets; the CI enforces it with
`SYMFONY_REQUIRE` on both the highest and the lowest job. A local `composer install` may still
resolve a newer Symfony, which broadens what you exercise rather than narrowing it — but it means
the toolchain can propose something that only makes sense on one branch. `rector.php` skips one
such rule already, with the reason written next to it.

Whatever you do, keep the code free of classes that exist on only one of the declared branches.
A bundle promising `^7.4 || ^8.0` has to hold both.

License
-------

Symfony API Platform bundle is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

&copy; 2026 [Jul6Art](https://devinthehood.com/)
