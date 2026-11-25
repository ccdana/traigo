<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    // La base crea tipos y vistas propios: hay que borrarlos antes de migrar.
    protected $dropViews = true;

    protected $dropTypes = true;

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
