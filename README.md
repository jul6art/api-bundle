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

<!--
    Write what a reader has to DO, not what the bundle contains. A line in a table saying a class
    exists has never helped anyone: someone must be able to use a brick without opening its code.

    For a parent class, show the contract — the methods to implement, one complete example, what
    the class gives back.

    For anything driven by an attribute, four things are needed, because the attribute does not
    say what it triggers:

      1. the annotation in situ, on a realistic class;
      2. WHAT EXECUTES IT, and when — a command? a Doctrine listener? a request listener? Without
         this, a reader assumes an attribute acts on its own;
      3. what to wire on the application side to get anything out of it — the event to subscribe
         to, the service to implement, the table to create, the configuration to set;
      4. THE TRAP: what is irreversible, what must be measured first, what silently does nothing
         when an optional package is missing.
-->

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
