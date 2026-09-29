# Compatibility

## PHP

See [SUPPORTED_RUNTIMES.md](../SUPPORTED_RUNTIMES.md). Minimum **PHP 8.3** (same as the community OpenFGA PHP SDK); CI tests **8.3**, **8.4**, and **8.5** on every pull request.

## OpenFGA server

| Server version | Support |
|----------------|---------|
| ≥ v1.10.0      | Supported (write conflict options, baseline for CI) |
| Latest release | Tested in CI; see `OPENFGA_VERSION` in `.github/workflows/ci.yml` |

## HTTP clients

Any PSR-18 implementation. Integration tests run with dependencies that include Guzzle and Symfony HttpClient.
