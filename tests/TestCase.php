<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected $dropViews = true;

    // Conservar los ENUM al reconstruir la base, como en migrate:fresh sin opciones.

    public function createApplication()
    {
        $app = parent::createApplication();

        // RefreshDatabase borra todo: nunca sobre una base de verdad.
        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Las pruebas solo corren sobre una base que termine en «_test», no «{$database}».");
        }

        return $app;
    }
}
