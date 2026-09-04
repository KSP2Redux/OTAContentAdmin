<?php

use App\Http\Middleware\EnsurePublisherGroup;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias(['publisher' => EnsurePublisherGroup::class]);
        $middleware->redirectGuestsTo(fn () => route('auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
