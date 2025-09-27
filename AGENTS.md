# Repository Guidelines

## Project Structure & Module Organization
The package code lives under `app/`, grouped by responsibility: console commands in `app/Console`, reusable contracts in `app/Contracts`, API parsing services in `app/Services`, and exception types under `app/Exceptions`. Shared helpers reside in `app/helpers.php`, while configuration defaults ship in `config/documentation.php`. Keep new feature flags or tunables inside that config file so downstream apps can override them cleanly. Diagnostic tooling such as the PSR-2 ruleset is tracked at the repo root (`phpcs.xml`) alongside Composer metadata.

## Build, Test, and Development Commands
- `composer install`: install package dependencies for local development or CI.
- `composer dump-autoload`: refresh the PSR-4 autoloader after adding classes.
- `php artisan documentation:generate`: run from a host Laravel app to emit `storage/app/public/documentation/openapi.json`.
- `vendor/bin/phpcs`: lint code against the PSR-2 standard before opening a pull request.

## Coding Style & Naming Conventions
Follow PSR-12 semantics while matching the enforced PSR-2 formatting (four-space indentation, braces on new lines). Class names must follow StudlyCase and stay within the `Bchalier\LaravelOpenapiDoc\App\` namespace declared in `composer.json`. Favor descriptive method names such as `parseResponses` or `guessFactoryClass`, mirroring existing patterns. Document non-trivial branches with concise docblocks, especially where reflection or annotations are involved.

## Testing Guidelines
Add PHPUnit test cases under a new `tests/` directory, mirroring package namespaces. Name test classes with the `Test` suffix, and group behavior-focused checks by service (for example, `DocGeneratorTest`). When adding fixtures, keep them under `tests/Fixtures` and reference Laravel’s `Orchestra Testbench` if integration scaffolding is required. Run `vendor/bin/phpunit --testsuite=unit` (define suites in `phpunit.xml.dist`) and target meaningful coverage of request parsing, response inference, and tag handling.

## Commit & Pull Request Guidelines
Write commit subjects in imperative mood (e.g., `Add response schema extractor`) and keep them under 72 characters, reflecting the existing history. Each pull request should describe the motivation, summarize the implementation, and link any related issues. Include reproduction steps or artisan commands that reviewers can run, and attach sample OpenAPI output when behavior changes the generated specification. Confirm that linting and tests pass before requesting review.

## Security & Configuration Notes
Avoid checking secrets or app-specific credentials into the repository—downstream apps will supply their own `.env`. When a change needs new configuration keys, document defaults in `config/documentation.php` and note them in the README so integrators can update their deployments.
