<?php

namespace App\Providers;

use App\Services\Content\ContentHandlerRegistry;
use App\Services\Content\MissionContentHandler;
use App\Services\Content\VesselContentHandler;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ContentHandlerRegistry::class, fn ($app) => new ContentHandlerRegistry([
            'main-menu-vessels' => $app->make(VesselContentHandler::class),
            'missions' => $app->make(MissionContentHandler::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
