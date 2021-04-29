<?php
declare(strict_types=1);

namespace Okdewit\RedisDS\Tests;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Okdewit\RedisDS\IndexedCache;
use Okdewit\RedisDS\Tests\Stubs\Foo;
use Okdewit\RedisDS\Tests\Stubs\FooCache;
use Okdewit\RedisDS\Tests\Stubs\FooCollection;
use stdClass;

class IndexedCacheTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_it_caches_one_class()
    {
        $foocache = new FooCache();
        $original = new Foo(1, 'orange');

        $foocache->put($original);
        $retrieved = $foocache->find(1);

        $this->assertEquals(Foo::class, get_class($retrieved));
        $this->assertEquals($original, $retrieved);

        $foocache->flush();
    }

    public function test_it_caches_one_object()
    {
        $cache = new IndexedCache('objectcache', fn(object $foo) => $foo->id);
        $original = (object) ['id' => 12, 'color' => 'yellow'];

        $cache->put($original);
        $retrieved = $cache->find(12);

        $this->assertEquals(stdClass::class, get_class($retrieved));
        $this->assertEquals($original, $retrieved);

        $cache->flush();
    }

    public function test_it_warms_cache()
    {
        $foocache = new FooCache();

        $collection = new FooCollection([
            new Foo(1, 'green'),
            new Foo(2, 'blue'),
            new Foo(3, 'cyan')
        ]);

        $foocache->warm($collection);

        $retrieved = $foocache->find(1);

        $this->assertEquals($collection->first(), $retrieved);
        $this->assertEquals($collection, new FooCollection($foocache->all()));

        $foocache->flush();
    }
}
