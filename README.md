<p align="center">
    <a href="https://devinthehood.com"><img src="https://github.com/jul6art/symfony-skeleton-generator/blob/master/public/img/logo.png?raw=true" alt="logo dev in the hood" width="400"></a>
</p>

Symfony API Platform bundle
===========================

<p align="left">
    <a href="https://opensource.org/licenses/MIT" target="_blank"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License"></a>
    <img src="https://img.shields.io/static/v1?label=stable&message=v2&color=0ea5e9" alt="Version">
</p>

Symfony API Platform bundle

Requirements
------------

- PHP ^8.5
- Symfony ^7.4 || ^8.0
- API Platform ^4.4
- Doctrine ORM ^3.7

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

Seven Doctrine ORM filters and one state provider, extracted from the applications that run them.

Since **2.0** they follow API Platform **4.4**'s filter model: a filter is declared through a
`#[QueryParameter]` on the resource, receives that parameter with the request value already
extracted, and reads its configuration from the parameter (`property`, `properties`) or from its own
constructor. `#[ApiFilter]` and `AbstractFilter` are deprecated upstream and removed in 6.0 — none
of these filters extends it any more. Upgrading from 1.x: see [Upgrading to 2.0](#upgrading-to-20).

### Ordering a text column the way a human reads it

```php
#[QueryParameter(key: 'order[:property]', filter: new CaseInsensitiveOrderFilter(), properties: ['name', 'email', 'createdAt'])]
class Contact { … }
```

`ORDER BY name` sorts by byte value on PostgreSQL, so `Apple, banana, Cherry` comes back as
`Apple, Cherry, banana` and the list looks broken. This filter lowers the column first — and only
for text columns, since lowering an integer is a cast the database does per row for nothing. Nested
properties (`order[company.name]`) are LEFT-joined. NULL placement is a constructor option:
`new CaseInsensitiveOrderFilter(OrderFilterInterface::NULLS_ALWAYS_LAST)`.

> ⚠️ **List the `properties`.** A `:property` template without them expands to EVERY property of
> the resource — columns become sortable that nobody meant to expose, which is not what 1.x did.

> ⚠️ **One `order[:property]` template per resource.** Parameters are keyed by their key: a second
> template on the same class replaces the first. Give the other ordering filters explicit keys.

> ⚠️ **It does not emit `ORDER BY LOWER(...)`, and that is deliberate.** API Platform's pagination
> extension parses ORDER BY by splitting on `.` and taking the first token as an alias, so
> `LOWER(c.name)` makes it fail with `The alias "LOWER(c" does not exist`. The filter adds a HIDDEN
> select with a flat alias and orders by that instead. Removing the indirection brings the bug
> back.

### Ordering by business rank rather than alphabet

```php
#[QueryParameter(key: 'order[status]', property: 'status', filter: new RankedOrderFilter(['todo', 'doing', 'done']))]
```

`ORDER BY status` gives `doing, done, todo` — alphabetical, and meaningless to a user. This orders
by the rank you declare, through a CASE expression with **bound** values. A value outside the list
sorts last, never first: a status nobody planned for should not open the list.

### One sortable property over several columns

```php
#[QueryParameter(key: 'order[fullName]', filter: new ConcatOrderFilter(['lastName', 'firstName']))]
```

### One search box over several columns

```php
#[QueryParameter(key: 'search', filter: new OrSearchFilter(), properties: ['name', 'reference', 'category.label', 'address.city'])]
```

`?search=term` becomes a single OR group. A filter per column would AND them together and only
match rows where *every* column contains the term.

What it handles that a naive implementation does not: a numeric column is cast (`CONCAT(col, '')`)
before the LIKE, a relation one hop away is reached with a **LEFT** JOIN — so a row without a
category still matches on its own name —, an embeddable's column (`address.city`) is read on the
holder rather than joined, and a non-scalar or empty term filters nothing rather than producing
`LIKE '%%'`.

### Filtering on a relation — by identifier OR by IRI

```php
#[QueryParameter(key: 'customer', filter: new RelationFilter())]
#[QueryParameter(key: 'site.customer', filter: new RelationFilter(), property: 'site.customer')]
```

`?customer=12`, `?customer=/api/customers/12` and `?customer[]=12&customer[]=14` all work, as they
did with the legacy `SearchFilter`. A collection (`#[QueryParameter(key: 'defects', filter: new
RelationFilter(), property: 'defects')]`) is joined, and its members compared — `q.defects = :id`
is not DQL.

> ⚠️ **Why not API Platform's `IriFilter`**, which the 4.4 upgrade command maps a relation
> `SearchFilter` to: it accepts IRIs only. A plain identifier — what a datatable filter sends — is
> logged as an error and the filter is IGNORED: the collection answers with every row, silently.
> `RelationParameterProvider` resolves IRIs (never by cutting the last segment: an IRI may carry a
> public uuid while Doctrine joins on the integer id) and leaves identifiers alone; an IRI that
> designates nothing matches no row.

### Filtering by year

```php
#[QueryParameter(key: 'issuedAt', filter: new YearFilter())]
```

`?issuedAt=2026` becomes a half-open range, `>= 2026-01-01 AND < 2027-01-01` — not `YEAR(col) =
2026`. Two reasons: `YEAR()` needs a DQL extension to be portable, and wrapping the column in a
function makes any index on it useless, which turns a filtered accounting journal from an index
scan into a full table scan. On a key a `DateFilter` also uses, compose both with API Platform's
`ChainFilter`.

### Searching inside a JSON column

```php
#[QueryParameter(key: 'roles', filter: new JsonContainsFilter())]
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

Upgrading to 2.0
----------------

2.0 requires **API Platform ^4.4** and **Doctrine ORM ^3.7** (the sort directions are
`\SortDirection` enums). Every `#[ApiFilter]` of a resource becomes a class-level `#[QueryParameter]`,
placed where the `#[ApiFilter]` was so the comments that explain it stay next to it:

| 1.x | 2.0 |
|---|---|
| `#[ApiFilter(CaseInsensitiveOrderFilter::class, properties: ['a', 'b'])]` | `#[QueryParameter(key: 'order[:property]', filter: new CaseInsensitiveOrderFilter(), properties: ['a', 'b'])]` |
| `#[ApiFilter(RankedOrderFilter::class, properties: ['status' => [...]])]` | `#[QueryParameter(key: 'order[status]', property: 'status', filter: new RankedOrderFilter([...]))]` |
| `#[ApiFilter(ConcatOrderFilter::class, properties: ['fullName' => [...]])]` | `#[QueryParameter(key: 'order[fullName]', filter: new ConcatOrderFilter([...]))]` |
| `#[ApiFilter(OrSearchFilter::class, properties: [...])]` | `#[QueryParameter(key: 'search', filter: new OrSearchFilter(), properties: [...])]` |
| `#[ApiFilter(JsonContainsFilter::class, properties: ['roles'])]` | `#[QueryParameter(key: 'roles', filter: new JsonContainsFilter())]` |
| `#[ApiFilter(YearFilter::class, properties: ['issuedAt'])]` | `#[QueryParameter(key: 'issuedAt', filter: new YearFilter())]` |
| API Platform `SearchFilter` on a **relation** | `#[QueryParameter(key: '<relation>', filter: new RelationFilter())]` |

For API Platform's own filters, follow its mapping (`OrderFilter` → `SortFilter`, `SearchFilter`
exact → `ExactFilter`, partial → `PartialSearchFilter`, `BooleanFilter` → `ExactFilter` with a `bool`
native type) — **but list the `properties` of every `:property` template yourself**: the upstream
`api:upgrade-filter` command writes `new SortFilter()` without them, which opens sorting on every
property of the resource, and it rewrites the whole `#[ApiResource]` attribute without its comments.

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

The API bundle is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

&copy; 2026 [jul6art](https://devinthehood.com)
