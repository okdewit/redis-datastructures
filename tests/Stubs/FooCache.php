<?php

namespace Okdewit\RedisDS\Tests\Stubs;

use Carbon\CarbonInterval;
use Okdewit\RedisDS\IndexedCache;

class FooCache extends IndexedCache
{
    public function __construct()
    {
        parent::__construct(
            'foocache',
            fn(Foo $foo) => $foo->id,
            ['color' => fn(Foo $foo) => $foo->color]
        );

        $this->setTimeToLive(CarbonInterval::day())
            ->setOnMiss(fn(int $id) => new Foo($id, 'purple'));
    }
}
