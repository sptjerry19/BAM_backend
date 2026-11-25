<?php

namespace App\Console\Commands\ContentStudio\Concerns;

use App\Models\ContentStudio\ContentItem;

trait FindsContentItem
{
    /** Reads --content-id and prints a clear error when it is missing or unknown. */
    protected function contentItem(): ?ContentItem
    {
        $id = $this->option('content-id');
        if (! is_numeric($id)) {
            $this->error('Pass --content-id=<number>.');

            return null;
        }

        $item = ContentItem::find((int) $id);
        if ($item === null) {
            $this->error("No content item with id {$id}.");
        }

        return $item;
    }
}
