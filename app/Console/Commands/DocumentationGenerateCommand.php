<?php

namespace Bchalier\LaravelOpenapiDoc\App\Console\Commands;

use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\ResponseTypeNotSupported;
use Bchalier\LaravelOpenapiDoc\App\Services\DocGenerator;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;

class DocumentationGenerateCommand extends Command
{
    const FILE_PATH = 'public/documentation/openapi.json';

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
    protected $description = 'Command description';

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
     * @throws \ReflectionException
     */
    public function handle()
    {
        $docJson = (new DocGenerator($this->router))->generate()->toJson();
        Storage::put(self::FILE_PATH, $docJson);
    }
}
