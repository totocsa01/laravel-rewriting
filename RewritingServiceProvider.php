<?php

namespace Totocsa01\Rewriting;

use Illuminate\Support\ServiceProvider;

class RewritingServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/rewriting.php',
            'rewriting'
        );
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Totocsa01\Rewriting\app\Console\Commands\Modification::class,
                \Totocsa01\Rewriting\app\Console\Commands\ReplaceInFile::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/config/rewriting.php' => config_path('rewriting.php'),
        ], 'totocsa01-rewriting-config');
    }
}
