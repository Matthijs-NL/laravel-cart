<?php

namespace DigitalSelf\LaravelCart\Manager;

use DigitalSelf\LaravelCart\Drivers\LaravelCartDatabase;
use DigitalSelf\LaravelCart\Drivers\LaravelCartSession;
use Illuminate\Support\Manager;

class LaravelCartManager extends Manager
{
    /**
     * Get the default driver.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('laravel-cart.driver.default');
    }

    /**
     * The database driver of laravel cart.
     */
    public function createDatabaseDriver(): LaravelCartDatabase
    {
        return new LaravelCartDatabase;
    }

    /**
     * The session driver of laravel cart.
     */
    public function createSessionDriver(): LaravelCartSession
    {
        return new LaravelCartSession;
    }
}
