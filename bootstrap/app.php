<?php

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
        // Behind Railway's (or any) TLS-terminating proxy, trust the forwarded
        // headers so Laravel/Filament generate correct https URLs and cookies
        // are marked secure — otherwise assets, redirects and CSRF break.
        $middleware->trustProxies(at: '*');

        // The app has no plain "login" route — authentication is handled by the
        // Filament panel, so guests hitting auth-protected routes (the PDF
        // document routes) are sent to the panel's login page.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
