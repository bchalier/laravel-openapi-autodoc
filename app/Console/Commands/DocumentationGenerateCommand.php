<?php

namespace Bchalier\LaravelOpenapiDoc\App\Console\Commands;

use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\ResponseTypeNotSupported;
use Bchalier\LaravelOpenapiDoc\App\Services\DocGenerator;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;
use ReflectionException;
use function PHPUnit\Framework\directoryExists;

class DocumentationGenerateCommand extends Command
{
    /**
     * The router instance.
     *
     * @var Router
     */
    protected $router;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documentation:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate OpenAPI (v3) JSON documentation from routes, requests and resources';

    /**
     * Create a new documentation command instance.
     *
     * @param Router $router
     * @return void
     */
    public function __construct(Router $router)
    {
        parent::__construct();

        $this->router = $router;
    }

    /**
     * Execute the console command.
     *
     * @throws JsonResourceNoType
     * @throws ResponseTypeNotSupported
     * @throws ReflectionException
     */
    public function handle()
    {
        // Generate a single global OpenAPI document. Tag grouping is handled via x-tagGroups.
        $docJson = (new DocGenerator($this->router))->generate()->toJson();

        if (! directoryExists(config('documentation.destination_dir'))) {
            mkdir(config('documentation.destination_dir'), recursive: true);
        }

        file_put_contents(base_path(config('documentation.destination_dir') . '/openapi.json'), $docJson);
    }
}
