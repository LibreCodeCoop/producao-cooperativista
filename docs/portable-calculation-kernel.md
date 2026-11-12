# Shared calculation kernel migration

The Symfony app's five existing public calculation classes delegate to the exact same kernel namespace used by the Akaunting module. There is **no second policy implementation**.

Only the compatibility facades remain for Symfony service wiring and existing return types. This includes the same `ProductionDistributionPlan` result shape. The PSR-4 module namespace is in production Composer autoload rather than autoload-dev, so installations using Composer --no-dev continue loading the kernel.

## Verification

Run existing calculation and integration suites, including both golden-master and native compatibility cases. The PHP 8.3 workflow executes the same 21 synthetic cases. Compare all errors and cents; if results diverge, stop instead of adjusting accepted values.

This does not migrate historical records, change financial authorization, or enable publication in the Akaunting module.
