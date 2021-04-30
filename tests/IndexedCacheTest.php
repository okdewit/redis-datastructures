<?php
declare(strict_types=1);

namespace Okdewit\RedisDS\Tests;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Okdewit\RedisDS\IndexedCache;
use Okdewit\RedisDS\Tests\Stubs\Color;
use Okdewit\RedisDS\Tests\Stubs\ColorCache;
use Okdewit\RedisDS\Tests\Stubs\ColorCollection;
use stdClass;

class IndexedCacheTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_it_caches_one_class()
    {
        $colorcache = new ColorCache();
        $original = new Color(1, 'orange');

        $colorcache->put($original);
        $retrieved = $colorcache->find(1);

        $this->assertEquals(Color::class, get_class($retrieved));
        $this->assertEquals($original, $retrieved);

        $colorcache->flush();
    }

    public function test_it_caches_one_object()
    {
        $cache = new IndexedCache('objectcache', fn(object $color) => $color->id);
        $original = (object) ['id' => 12, 'color' => 'yellow'];

        $cache->put($original);
        $retrieved = $cache->find(12);

        $this->assertEquals(stdClass::class, get_class($retrieved));
        $this->assertEquals($original, $retrieved);

        $cache->flush();
    }

    public function test_it_warms_cache()
    {
        $colorcache = new ColorCache();

        $collection = new ColorCollection([
            new Color(1, 'green'),
            new Color(2, 'blue'),
            new Color(3, 'cyan')
        ]);

        $colorcache->warm($collection);

        $retrieved = $colorcache->find(1);

        $this->assertEquals($collection->first(), $retrieved);
        $this->assertEquals($collection, new ColorCollection($colorcache->all()));

        $colorcache->flush();
    }

    public function test_it_finds_by_index()
    {
        $colorcache = new ColorCache();

        $collection = new ColorCollection([
            new Color(1, 'green'),
            new Color(2, 'blue'),
            new Color(3, 'cyan'),
            new Color(4, 'blue'),
            new Color(5, 'blue'),
            new Color(6, 'green')
        ]);

        $colorcache->warm($collection);

        $retrieved = $colorcache->findBy('color', 'blue');

        $this->assertCount(3, $retrieved);
        $this->assertEquals([2,4,5], $retrieved->pluck('id')->toArray());

        $colorcache->flush();
    }

    public function test_it_misses()
    {
        $colorcache = new ColorCache();

        $original = new Color(1, 'orange');

        $colorcache->put($original);
        $retrieved = $colorcache->find(2);

        // The miss handler on ColorCache specifies that a new Color purple should be created on a cache miss.
        $this->assertEquals('purple', $retrieved->color);

        $colorcache->flush();
    }

    public function test_empty()
    {
        $colorcache = new ColorCache();

        $retrieved = $colorcache->all();

        $this->assertEmpty($retrieved);

        $colorcache->flush();
    }
}
