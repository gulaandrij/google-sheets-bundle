# Contributing

Thanks for taking the time to contribute. This document explains the local workflow and the bar your change is expected to meet before review.

## Local setup

```bash
git clone https://github.com/gulaandrij/google-sheets-bundle.git
cd google-sheets-bundle
composer install
```

The package targets PHP 8.3 and 8.4. The CI matrix exercises both, so use the version that matches your project — `phpenv` or `phpbrew` work well if you need to swap.

## Quality gates

Every PR must pass three checks locally before pushing — the same three CI runs:

```bash
composer test     # PHPUnit
composer stan     # PHPStan (level 8)
composer lint     # PHP-CS-Fixer dry run
```

Apply formatting fixes with:

```bash
composer lint:fix
```

If you change a public method's signature or behaviour, the change MUST come with a test that exercises it. Bug fixes need a regression test that fails before the fix and passes after.

## Style conventions

- All classes `final` unless explicitly designed for extension.
- `declare(strict_types=1);` at the top of every PHP file.
- One trait of fluent calls per logical operation; pull common chains into helper methods.
- Tests use the `@internal` docblock and PHPUnit's `#[CoversClass]` attribute.
- Tests live in `Gulaandrij\GoogleSheetsBundle\Tests\` mirroring `src/`.

## Commit messages

Follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/). Prefix subject lines with `feat:`, `fix:`, `refactor:`, `chore:`, `test:`, `docs:`, `ci:` etc. Keep the subject under 70 characters; use the body for the *why*.

## Releases

1. Update `CHANGELOG.md` — move "Unreleased" entries into a new dated version.
2. Tag the commit (`git tag X.Y.Z && git push --tags`).
3. CI will publish the release on Packagist via the configured webhook.

## Reporting issues

When reporting a bug, please include:
- Bundle, PHP, and Symfony versions.
- Minimal reproduction (config snippet + the code that exercises the bug).
- The full exception trace if applicable.
