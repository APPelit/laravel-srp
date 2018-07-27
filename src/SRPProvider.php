<?php

namespace APPelit\SRP;

use APPelit\SRP\Commands\GenerateConfig;
use Thinbus\ThinbusSrp;

class SRPProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/srp.php' => config_path('srp.php'),
            ]);

            $this->registerCommands();
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/srp.php', 'srp');

        $this->registerBindings();
        $this->registerAliases();
    }

    public function provides()
    {
        return [
            'srp',
        ];
    }

    protected function registerAliases()
    {
        $this->app->alias(SrpService::class, 'srp');
    }

    protected function registerBindings()
    {
        $config = $this->app->make('config');

        $this->app->singleton(ThinbusSrp::class, function () use ($config) {
            return new ThinbusSrp(
                $config->get('srp.N'),
                $config->get('srp.g'),
                $config->get('srp.k'),
                $config->get('srp.H')
            );
        });
    }

    protected function registerCommands()
    {
        $this->commands([
            GenerateConfig::class,
        ]);
    }
}
