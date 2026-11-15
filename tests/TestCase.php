<?php

namespace AljazRomih\GmailTransport\Tests;

use AljazRomih\GmailTransport\GmailTransportServiceProvider;
use AljazRomih\GmailTransport\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [GmailTransportServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        // Testbench 8 (Laravel 10) defaults to MySQL; 9 and later to this.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mail.mailers.gmail', ['transport' => 'gmail']);
        $app['config']->set('gmail-transport.client_id', 'test-client-id');
        $app['config']->set('gmail-transport.client_secret', 'test-client-secret');
        $app['config']->set('gmail-transport.redirect_after', '/settings');
    }

    /**
     * The fixtures bring their own users table: how Laravel's default migrations are loaded differs
     * between Testbench 8 and 11.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
