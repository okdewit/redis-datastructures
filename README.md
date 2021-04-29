# Redis-datastructures
A Laravel + Redis library to create more advanced caching datastructures

This package is tested with PHP 7.4 & PHP 8.0,  
with Laravel 6 and above,  
and with both the `predis/predis` package & with the native `phpredis` ext.

# Usage

Current datastructures in this package:

## IndexedCache

IndexedCache caches objects, and optionally maintains one or more secondary indexes for those objects.

```php
$cache = new IndexedCache(
    // Name, prefix where the cached objects are stored
    'colorcache',

    // A callback used to determine where the (integer) primary key can be found on the object
    fn(Color $color) => $color->id,

    // An array with secondary index definitions.
    ['color' => fn(Color $color) => $color->color]
);
```

`Color` is just a simple DTO class in this example, but it could be any serializable object or Model.

```php
class Color
{
    public int $id;
    public string $color;
}
```

Expiration for the objects can be set, as well as a handler for cache misses:

```php
$cache->setTimeToLive(CarbonInterval::day())
    ->setOnMiss(fn(int $id) => new Color($id, 'purple'));
```

The cache can be warmed all at once, or populated with individual items:

```php
$collection = new ColorCollection([
    new Color(1, 'green'),
    new Color(2, 'blue'),
    new Color(3, 'green')
]);

$cache->warm($collection);              // Flush & populate the cache
$cache->warm($collection, false);       // Do not flush, just extend the cache with missing items

$purple = new Color(4, 'purple');
$cache->put($purple); 
```

Because we defined which properties on the object represent the "primary" and (optionally) "secondary" keys, we can efficiently retrieve items from the cache:

```php
// Find by primary index (unique),
// O(1)
$color = $cache->find(4);
$this->assertEquals($purple, $color);

// Find by secondary index (non unique, collection),
// O(log(N)+M), equal time complexity to an SQL BTREE index.
$colorCollection = $cache->findBy('color', $color);
$this->assertEquals(2, $colorCollection->count());
```

`findBy` returns a plain `Illuminate\Support\Collection` with all the objects matching the indexed constraint.  
This can of course be converted into a custom collection using something like `->pipeInto(ColorCollection::class)`.

Finally, the whole cache can also be retrieved, or emptied:
```php
$colorCollection = $cache->all();
$cache->flush();
```



# Testing
Run `composer update`, and then `composer test`

By default, it will use the predis client, and assume connection details as defined in `phpunit.xml.dist`.
This can be customized by copying and editing the dist file to `phpunit.xml` before running the tests.

On Github Actions, the package is tested against both predis & phpredis. 
