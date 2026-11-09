<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $config = $app['config'];
        if ($config->get('database.default') !== 'sqlite'
            || $config->get('database.connections.sqlite.database') !== ':memory:'
            || $config->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Tests require an in-memory SQLite database. Clear cached configuration before running tests.');
        }

        return $app;
    }
}
