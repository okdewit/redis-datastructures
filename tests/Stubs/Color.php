<?php declare(strict_types=1);

namespace Okdewit\RedisDS\Tests\Stubs;

class Color
{
    public int $id;
    public string $color;

    public function __construct(int $id, string $color)
    {
        $this->id = $id;
        $this->color = $color;
    }
}
