# Contributing

Thanks for considering a contribution.

## Getting set up

```bash
composer install
composer test
```

`composer test` runs PHPUnit directly when your PHP has the `dom`, `mbstring`,
`xmlwriter` and `tokenizer` extensions. If it does not, the runner
(`tools/test.sh`) transparently re-runs the suite inside the official
`composer:2` Docker image, which ships all of them — so the command works
either way. It forwards arguments, including filters:

```bash
composer test -- --filter ReferenceVectorTest
```

## Before you open a pull request

```bash
composer test      # full suite, must be green with pristine output
composer analyse   # PHPStan, level max, must report no errors
composer cs        # coding standards check (composer cs-fix applies them)
```

The suite runs with `failOnWarning`, `failOnRisky`, `failOnNotice` and
`failOnDeprecation` enabled — a PHP warning fails the build. That is
deliberate: an unvalidated Global Vendor List parser went unnoticed for a
release precisely because its warnings were being ignored.

## How we work

- **Tests first.** Write the failing test, watch it fail for the right reason,
  then make it pass.
- **Conventional Commits.** Breaking changes get a `!` and a `BREAKING CHANGE:`
  footer.
- **Changes to spec conformance need a reference vector.** Every other test in
  this suite round-trips through this package, so it would still pass if we
  misread the specification. If you change how a field is encoded or decoded,
  add a vector to `tests/ReferenceVectorTest.php` whose expected values come
  from another implementation or from the specification itself — never from
  running this package.

## Things worth knowing

- The package has **no runtime dependencies**. `require` may contain only `php`
  and `ext-json`; anything else belongs in `require-dev` and `suggest`.
- The minimum PHP version is **8.1**, and CI tests through 8.5.
- `resources/vendor-list.json` is generated. Do not hand-edit it; it is
  refreshed by `.github/workflows/update-gvl.yml`.
- Field bounds live in `Flenczewski\IabTcf\Spec`. Refer to those constants
  rather than repeating numeric literals.
