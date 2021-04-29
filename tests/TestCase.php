<?php

namespace Okdewit\RedisDS\Tests;

use Config;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public function setUp(): void
    {
        parent::setUp();

        Config::set('database.redis', [
            'cluster' => false,
            'default' => [
                'host' => env('REDIS_HOST', 'redis'),
                'port' => env('REDIS_PORT', 6379),
                'password' => env('REDIS_PASSWORD', null),
                'database' => 0,
            ],
            'client' => env('REDIS_CLIENT', 'predis')
        ]);
    }

}
