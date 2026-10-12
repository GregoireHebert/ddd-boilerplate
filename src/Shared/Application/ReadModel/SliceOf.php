<?php

declare(strict_types=1);

namespace App\Shared\Application\ReadModel;

/**
 * What a port that paginates by keyset hands back.
 *
 * `$next` is the cursor to pass back as {@see Slice::$after}, or null when the store had nothing
 * after the last row returned. There is no total and no last page, not because we skipped them but
 * because this mechanism cannot produce them: a set that moves while you walk it has no stable
 * count of pages.
 *
 * @template T
 */
final readonly class SliceOf
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $size,
        public ?string $next,
    ) {
    }

    public function hasNext(): bool
    {
        return null !== $this->next;
    }
}
