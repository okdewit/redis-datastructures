<?php

namespace Okdewit\RedisDS\Tests\Stubs;

use Carbon\CarbonInterval;
use Okdewit\RedisDS\IndexedCache;

class ColorCache extends IndexedCache
{
    public function __construct()
    {
        parent::__construct(
            'colorcache',
            fn(Color $color) => $color->id,
            ['color' => fn(Color $color) => $color->color]
        );

        $this->setTimeToLive(CarbonInterval::day())
            ->setOnMiss(fn(int $id) => new Color($id, 'purple'));
    }
}
