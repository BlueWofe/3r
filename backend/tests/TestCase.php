<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $configuration = $app->make('config');
        $safe = $app->environment('testing')
            && $configuration->get('database.default') === 'sqlite'
            && $configuration->get('database.connections.sqlite.database') === ':memory:'
            && empty($configuration->get('database.connections.sqlite.url'))
            && $configuration->get('cache.default') === 'array'
            && $configuration->get('session.driver') === 'array'
            && $configuration->get('queue.default') === 'sync';
        if (! $safe) {
            throw new \RuntimeException('Refusing to run tests outside the isolated SQLite in-memory, array cache and array session environment.');
        }

        return $app;
    }
}
