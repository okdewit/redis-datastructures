<?php
declare(strict_types=1);

namespace Okdewit\RedisDS\Tests;

use Illuminate\Support\Facades\Redis;

class ConnectionTest extends TestCase
{
    public function test_it_works()
    {
        $this->assertEquals('HELLO', (string) Redis::ping("HELLO"));
    }
}
