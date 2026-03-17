# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Inventorix** is a Laravel package (`aldeebhasan/inventorix`) that provides modern inventory control — stock tracking, movement history, and low stock alerts — for Laravel 11/12 applications. It is currently in early development (skeleton stage).

## Commands

```bash
composer test              # Run all tests
composer test-coverage     # Run tests with coverage report
composer analyse           # Run PHPStan static analysis (level 5)
composer format            # Fix code style with Laravel Pint
```

Run a single test file:
```bash
vendor/bin/pest tests/ExampleTest.php
```

Run PHPStan with GitHub error format (as CI does):
```bash
vendor/bin/phpstan --error-format=github
```

## Architecture

This is a standard **Laravel package** using the service provider pattern:

- **`src/InventorixServiceProvider.php`** — Registers and boots the package. Publishes config (`config/inventorix.php`) and migration stubs, merges package config, loads views under the `inventorix` namespace.
- **`src/Inventorix.php`** — Core service class, bound to the container by the service provider.
- **`src/Facades/Inventorix.php`** — Laravel facade for static-style access to the core service.
- **`src/Commands/InventorixCommand.php`** — Artisan command registered in the service provider.
- **`config/inventorix.php`** — Package configuration, published to the host app.
- **`database/migrations/*.stub`** — Migration stubs; published with a timestamp prefix to `database/migrations/` in the host app.

## Testing

Tests use **Pest** (not PHPUnit directly) with the Orchestra Testbench for bootstrapping a minimal Laravel app.

- **`tests/TestCase.php`** — Base test case; registers `InventorixServiceProvider` via `getPackageProviders()`.
- **`tests/Pest.php`** — Applies the base `TestCase` to all tests in `tests/`.
- **`tests/ArchTest.php`** — Architecture test; enforces that `dd()`, `dump()`, and `ray()` are not used in source code.

Tests run in **random order** (configured in `phpunit.xml.dist`).

## CI Matrix

GitHub Actions test against:
- PHP: 8.3, 8.4
- Laravel: 11.*, 12.*
- Stability: `prefer-lowest`, `prefer-stable`

Code style is auto-fixed and committed by the `fix-php-code-style-issues` workflow on push.

## PHPStan

Level 5, applied to `src/`, `config/`, and `database/`. Baseline overrides are in `phpstan-baseline.neon`.
