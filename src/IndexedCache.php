<?php declare(strict_types=1);

namespace Okdewit\RedisDS;

use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

class IndexedCache
{
    protected string $name;
    protected ?int $ttl = null;

    /** @var callable */
    protected $primaryIndex;
    protected array $secondaryIndexes = [];

    /** @var callable */
    protected $onMiss;

    protected string $redisClientType;

    public function __construct(string $name, callable $primaryIndex, array $secondaryIndexes = [])
    {
        $this->name = $name;
        $this->primaryIndex = $primaryIndex;
        $this->secondaryIndexes = $secondaryIndexes;
        $this->redisClientType = (get_class(Redis::connection()->client()) === 'Predis\Client') ? 'Predis' : 'PhpRedis';
    }

    public function setTimeToLive(CarbonInterval $ttl): self
    {
        $this->ttl = (int) $ttl->totalSeconds;
        return $this;
    }

    public function setOnMiss(callable $onMiss): self
    {
        $this->onMiss = $onMiss;
        return $this;
    }

    public function warm(Enumerable $collection, bool $flush = true): self
    {
        if ($flush) {
            $this->flush();
        }
        $collection->each(fn (object $item) => $this->put($item));

        return $this;
    }

    public function put(object $object): self
    {
        Redis::transaction(function ($redis) use ($object) {
            $id = ($this->primaryIndex)($object);
            $redis->set("$this->name:$id", serialize($object));

            if (is_int($this->ttl)) {
                $redis->expire("$this->name:$id", $this->ttl);
            }

            foreach ($this->secondaryIndexes as $indexName => $value) {
                $id = ($this->primaryIndex)($object);
                $value = ($this->secondaryIndexes[$indexName])($object);
                $redis->zAdd("$this->name-index:$indexName", 0, "$value\x00$id");
            }
        });

        return $this;
    }

    public function find(int $id): ?object
    {
        $hit = Redis::get("$this->name:$id");
        if (is_string($hit)) {
            return unserialize($hit);
        }

        if (is_callable($this->onMiss) && is_object($hit = ($this->onMiss)($id))) {
            $this->put($hit);
            return $hit;
        }

        return null;
    }

    public function findBy(string $indexName, string $indexValue)
    {
        $keys = [];

        foreach (Redis::zRangeByLex("$this->name-index:$indexName", "[$indexValue\x00", "[$indexValue\x00\xff") as $indexRecord) {
            $keys[] = "$this->name:" . Str::after($indexRecord, "$indexValue\x00");
        }

        return $this->hydrate(count($keys) > 0 ? Redis::mget($keys) : []);
    }

    public function groupBy(string $indexName): LazyCollection
    {
        return new LazyCollection(function () use ($indexName) {
            $cursor = "";
            while (($index = $this->nextIndex($indexName, $cursor)) !== null) {
                $cursor = Str::before($index, "\x00");
                yield $cursor => $this->findBy($indexName, $cursor);
            }
        });
    }

    private function nextIndex(string $indexName, string $cursor): ?string
    {
        $index = $this->redisClientType === 'PhpRedis'
            ? Redis::zRangeByLex("$this->name-index:$indexName", "[$cursor\x00\xFF", "+", '0', '1')
            : Redis::zRangeByLex("$this->name-index:$indexName", "[$cursor\x00\xFF", "+", 'LIMIT', '0', '1');

        return (isset($index[0])) ? $index[0] : null;
    }

    public function all(): Collection
    {
        return $this->hydrate(
            Redis::eval("
                local keys = redis.call('KEYS','$this->name:*');
                if (table.getn(keys) == 0) then return {} end;
                table.sort(keys);
                return redis.call('MGET',unpack(keys));
            ", 0)
        );
    }

    public function flush(): void
    {
        Redis::transaction(function () {
            Redis::eval($this->deleteString("$this->name:*"), 0);
            Redis::eval($this->deleteString("$this->name-index:*"), 0);
        });
    }

    private function deleteString(string $pattern): string
    {
        return "for _,k in ipairs(redis.call('keys','$pattern')) do redis.call('del',k) end";
    }

    private function hydrate(array $items): Collection
    {
        return collect($items)->map(fn (string $object) => unserialize($object));
    }
}
