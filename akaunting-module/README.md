# Cooperative Production — native Akaunting module (in progress)

This is the first isolated port of the cooperative economic calculation kernel.
It is **not** a production-ready module or a publication interface.

The module requires PHP 8.3+ and BCMath. The economic kernel is under
`Domain/Calculation` and intentionally does not depend on Symfony, Doctrine,
Laravel, Akaunting database state or HTTP.

The current legacy application continues to provide all production flows.
To validate the port, the repository test harness loads the module namespace
and evaluates the same 21 versioned synthetic financial cases twice, once
against the legacy classes and once against this port:

```sh
composer dump-autoload
vendor-bin/unit/vendor/bin/phpunit -c tests/php/phpunit.xml --filter 'Portability(GoldenMaster|NativeKernel)Test'
```

This port copies the financial calculation behavior **without** inventing new
policy decisions, and replaces the PHP 8.4+ `bcround` call with equivalent
BCMath cent rounding for the target PHP 8.3 runtime. Revalidation at each
monetary boundary is mandatory before use.

Next: replace duplication with a shared released package and proven adapters,
then add snapshot/ledger migrations, company-scoped permissions, preview UI,
approval, publication and the individual member portal. Do not use this module
to write financial data before those gates have passed.

License: AGPL-3.0-or-later.
