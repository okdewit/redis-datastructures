<?php declare(strict_types=1);

namespace Okdewit\RedisDS;

use Carbon\CarbonInterval;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Redis;
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

    public function __construct(string $name, callable $primaryIndex, array $secondaryIndexes = [])
    {
        $this->name = $name;
        $this->primaryIndex = $primaryIndex;
        $this->secondaryIndexes = $secondaryIndexes;
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
        if ($flush) $this->flush();
        $collection->each(fn(object $item) => $this->put($item));

        return $this;
    }

    public function put(object $object): self
    {
        Redis::transaction(function($redis) use ($object) {
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
        $hit = unserialize(Redis::get("$this->name:$id"));
        if (is_object($hit)) return $hit;

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

    public function all(): Collection
    {
        return $this->hydrate(
            Redis::eval("
                local allkeys = redis.call('KEYS','$this->name:*');
                table.sort(allkeys);
                return redis.call('MGET',unpack(allkeys));
            ", 0)
        );
    }

    public function flush(): void
    {
        Redis::transaction(function() {
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
        return collect($items)->map(fn(string $object) => unserialize($object));
    }
}
