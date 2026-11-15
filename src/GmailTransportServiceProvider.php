<?php

namespace AljazRomih\GmailTransport;

use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class GmailTransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gmail-transport.php', 'gmail-transport');
    }

    public function boot(): void
    {
        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('gmail', fn (): GmailTransport => new GmailTransport);
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/gmail-transport.php' => $this->app->configPath('gmail-transport.php'),
        ], 'gmail-transport-config');

        if (config('gmail-transport.routes.enabled')) {
            Route::middleware(config('gmail-transport.routes.middleware'))
                ->prefix(config('gmail-transport.routes.prefix'))
                ->name('gmail-transport.')
                ->group(__DIR__.'/../routes/web.php');
        }
    }
}
