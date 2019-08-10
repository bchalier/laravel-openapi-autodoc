<?php

namespace Bchalier\LaravelOpenapiDoc\App\Providers;

use Bchalier\LaravelOpenapiDoc\App\Console\Commands\DocumentationGenerateCommand;
use Illuminate\Support\ServiceProvider;

class LoadingServiceProvider extends ServiceProvider
{
    /**
     * Load all laravel components.
     */
    public function boot()
    {
        $this->loadConsole();
    }

    /**
     * Load commands.
     */
    protected function loadConsole()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DocumentationGenerateCommand::class,
            ]);
        }
    }
}
