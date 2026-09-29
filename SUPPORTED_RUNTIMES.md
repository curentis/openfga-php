# PHP support policy for curentis/openfga-php

The OpenFGA **server** is written in Go and does not define a PHP version. This SDK follows the same approach as the [OpenFGA JavaScript SDK](https://github.com/openfga/js-sdk/blob/main/SUPPORTED_RUNTIMES.md): we test every PHP release branch that is on **active** support at [php.net](https://www.php.net/supported-versions.php) and that works with our toolchain (PHPUnit 12, PHPStan 2, and so on).

The archived [evansims/openfga-php](https://github.com/evansims/openfga-php) package also required **PHP ^8.3**.

## Currently supported versions

| PHP version | php.net status | Tested in CI on every PR |
|-------------|----------------|--------------------------|
| **8.3**     | Active         | Yes                      |
| **8.4**     | Active         | Yes                      |
| **8.5**     | Active         | Yes                      |

The canonical list is in `tools/supported-php-versions.php` and must match the `php` matrix in `.github/workflows/ci.yml`.

## PHP 8.2

PHP 8.2 is in **security-fixes-only** mode. We do not run CI on 8.2 because **PHPUnit 12** (our test runner) requires PHP ≥ 8.3. The SDK may still run on 8.2 in applications that only use runtime dependencies (PSR-18, and so on), but that is **not** supported or tested.

## Composer requirement

```json
"php": "^8.3"
```

## Static analysis

**PHPStan** and **Psalm** run on **8.3**, **8.4**, and **8.5** in CI (same `composer check` on every matrix cell). Dev dependencies require **Psalm ^6.19** (PHP 8.5–compatible). Contributors should use a current patch release of their PHP branch (see Psalm’s `php` constraint in `composer.lock`).

## Developing locally

Use PHP **8.3.16+**, **8.4.3+**, or **8.5.0+** so `composer install` resolves dev tools. The library runtime still targets `^8.3` for applications.

## OpenFGA server

Integration tests run against **v1.10.0** (oldest supported) and the latest release in CI, on **each supported PHP version**. See [docs/compatibility.md](docs/compatibility.md).

## When php.net ends support

When a PHP branch leaves active support, we remove it from the CI matrix in a minor SDK release and update this file.
