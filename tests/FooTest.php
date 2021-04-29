<?php
declare(strict_types=1);

namespace Okdewit\RedisDS\Tests;

use Illuminate\Support\Facades\Redis;

class FooTest extends TestCase
{
    public function test_it_works()
    {
        $this->assertEquals('PONG', (string) Redis::ping());
    }
}
