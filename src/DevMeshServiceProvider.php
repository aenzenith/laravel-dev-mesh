<?php

namespace Aenzenith\DevMesh;

use Aenzenith\DevMesh\Console\DevMeshCommand;
use Illuminate\Support\ServiceProvider;

final class DevMeshServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dev-mesh.php', 'dev-mesh');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'dev-mesh');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/dev-mesh.php' => $this->app->configPath('dev-mesh.php'),
        ], 'dev-mesh-config');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/dev-mesh'),
        ], 'dev-mesh-lang');

        $this->commands([DevMeshCommand::class]);
    }
}
