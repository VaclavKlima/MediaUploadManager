<?php

namespace Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        $configuration = $application->make(Repository::class);
        $connection = $configuration->get('database.default');
        $database = is_string($connection)
            ? $configuration->get("database.connections.{$connection}.database")
            : null;

        $usesSafeMySqlDatabase = $connection === 'mysql'
            && $database === 'media_upload_manager_testing';
        $usesInMemorySqlite = $connection === 'sqlite'
            && $database === ':memory:';

        if (! $usesSafeMySqlDatabase && ! $usesInMemorySqlite) {
            throw new LogicException(sprintf(
                'Tests may only use the [media_upload_manager_testing] MySQL database or in-memory SQLite; [%s] is not safe.',
                is_string($database) ? $database : 'unknown',
            ));
        }

        return $application;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
