# Examples

Runnable PHP scripts for **curentis/openfga-php**. Install dependencies from the repository root first:

```bash
composer install
```

Point at a running OpenFGA instance (local Docker or hosted):

```bash
export FGA_API_URL=http://localhost:8080
```

Optional environment variables are documented in each script.

| Script | Description |
|--------|-------------|
| [quickstart.php](quickstart.php) | Create store, model, tuple, and check |
| [authentication_api_token.php](authentication_api_token.php) | Client with `ApiToken` credentials |
| [authentication_client_credentials.php](authentication_client_credentials.php) | OAuth client credentials flow |
| [batch_check.php](batch_check.php) | `batchCheck` and `listRelations` |
| [custom_components.php](custom_components.php) | Custom `ClientComponentFactoryInterface` |

Run:

```bash
php examples/quickstart.php
```

These examples are for learning and integration testing; they are not published as separate Composer packages.

More detail: [docs/README.md](../docs/README.md).
