# Laravel OpenAPI Autodoc

Generates OpenAPI v3 documentation from your Laravel routes, FormRequests and API Resources.

Compatible with Laravel 10–12 and PHP 8.1–8.4.

## Install

1) Require the package in your app (Composer path repo supported):

2) Publish the config if you need to customize:

`php artisan vendor:publish --tag=config --provider="Bchalier\LaravelOpenapiDoc\App\Providers\LoadingServiceProvider"`

## Usage

- Generate the JSON file:
  - `php artisan documentation:generate`
- Output file:
  - Stored on the `public` disk at `storage/app/public/documentation/openapi.json`.
  - If you’ve run `php artisan storage:link`, it’s available at `/storage/documentation/openapi.json`.

## How it works

- Parses route list and filters via `config/documentation.php`.
- Infers request schemas from FormRequest validation rules.
- Infers response schemas from return types: `JsonResource`, `ResourceCollection`, `JsonResponse`.
- Uses docblocks for operation summary/description and `@throws` to add error responses.

## Notes

- Models instantiated for resources must use `Illuminate\Database\Eloquent\Factories\HasFactory`.
- When route names are missing, operationId falls back to `<method>_<uri>`.
