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
        $this->loadConfig();
        
        $this->publishConfig();
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

    /**
     * Load all package's config files.
     */
    protected function loadConfig()
    {
        /** @var \Symfony\Component\Finder\SplFileInfo $configFile */
        foreach ($this->app['files']->files($this->getConfigPath()) as $configFile) {
            $this->mergeConfigFrom(
                $configFile->getRealPath(), $configFile->getFilenameWithoutExtension()
            );
        }
    }

    /**
     * Get the config directory path.
     *
     * @return string
     */
    protected function getConfigPath()
    {
        return __DIR__ . '/../../config';
    }

    /**
     * Register som config files for future publish.
     */
    protected function publishConfig()
    {
        $this->publishes([
            $this->getConfigPath() . '/documentation.php' => config_path('documentation.php')
        ], 'config');
    }
}
