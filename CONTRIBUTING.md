# Contributing

Thanks for considering a contribution!

## Getting started

```bash
git clone https://github.com/jay123anta/laravel-api-versionist.git
cd laravel-api-versionist
composer install
```

## Before you submit

All four quality gates must pass — CI enforces them:

```bash
composer test       # PHPUnit (205+ tests)
composer lint       # Pint, Laravel preset
composer analyse    # PHPStan level 8
vendor/bin/rector process --dry-run
```

## Guidelines

- **Every PR needs tests.** Bug fixes need a regression test; features need behavioral coverage.
- Target the lowest supported Laravel (10) — CI runs the full 10–13 matrix plus `--prefer-lowest`.
- Follow the existing code style; `composer format` applies Pint automatically.
- One logical change per PR. Update `CHANGELOG.md` under an `Unreleased` heading.
- Breaking changes need a strong justification — this package versions APIs; it should not break yours.

## Reporting bugs

Use the bug report issue template. Include the Laravel/PHP versions, your
`config/api-versionist.php` (redacted), and a failing test case if possible.

## Security issues

Do **not** open a public issue — see [SECURITY.md](SECURITY.md).
