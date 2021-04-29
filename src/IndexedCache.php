<?php declare(strict_types=1);

namespace Okdewit\RedisDS;

use Carbon\CarbonInterval;
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

    /**
     * @param string $name Unique name for the cache
     * @param callable $primaryIndex A primary index resolver
     * @param array $secondaryIndexes Optional resolvers for secondary indexes
     *
     * @example
     *     $userCache = new IndexedCache('my-user-cache', fn($user) => $user->id, [
     *         'name' => fn($user) => $user->firstname,
     *         'mobile' => fn($user) => $user->mobile
     *     ])
     */
    public function __construct(string $name, callable $primaryIndex, array $secondaryIndexes = [])
    {
        $this->name = $name;
        $this->primaryIndex = $primaryIndex;
        $this->secondaryIndexes = $secondaryIndexes;
    }

    public function flush()
    {
        $this->delete("$this->name:*");
        $this->delete("$this->name-index:*");
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

    /**
     * @param Enumerable $collection
     * @param bool $flush
     * @return $this
     *
     * @example $userCache->warm($userCollection)
     */
    public function warm(Enumerable $collection, bool $flush = true): self
    {
        if ($flush) $this->flush();

        $collection->each(function(object $item) {
            $this->put($item);
            foreach ($this->secondaryIndexes as $indexName => $value) {
                $this->index($item, $indexName);
            }
        });

        return $this;
    }

    /**
     * @param object $object
     * @return $this
     *
     * @example $userCache->put($user)
     */
    public function put(object $object): self
    {
        $id = ($this->primaryIndex)($object);
        Redis::set("$this->name:$id", serialize($object));

        if (is_int($this->ttl)) {
            Redis::expire("$this->name:$id", $this->ttl);
        }

        return $this;
    }

    public function index(object $object, string $indexName)
    {
        $id = ($this->primaryIndex)($object);
        $value = ($this->secondaryIndexes[$indexName])($object);
        Redis::zAdd("$this->name-index:$indexName", 0, "$value\x00$id");
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

    public function findBy(string $indexName, string $id): Collection
    {
        $keys = [];

        foreach (Redis::zRangeByLex("$this->name-index:$indexName", "[$id\x00", "[$id\x00\xff") as $indexValue) {
            $keys[] = "$this->name:" . Str::after($indexValue, "$id\x00");
        }

        return $this->hydrate(count($keys) > 0 ? Redis::mget($keys) : []);
    }

    public function all(): Collection
    {
        return $this->hydrate(
            Redis::eval("
                local arg = '$this->name:*'
                local keys = redis.call('KEYS',arg);
                table.sort(keys);
                return redis.call('MGET',unpack(keys));
            ", 0));
    }

    private function delete(string $pattern): void
    {
        Redis::eval("for _,k in ipairs(redis.call('keys','$pattern')) do redis.call('del',k) end", 0);
    }

    private function hydrate(array $items): Collection
    {
        return collect($items)->map(fn(string $object) => unserialize($object));
    }
}
