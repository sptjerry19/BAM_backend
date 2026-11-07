<?php

namespace App\Services\ContentStudio\State;

use RuntimeException;

/** Another process changed the item between our read and our write. */
final class StaleContentItem extends RuntimeException
{
    public static function forItem(int $id): self
    {
        return new self("Content item {$id} was modified concurrently; reload and retry.");
    }
}
