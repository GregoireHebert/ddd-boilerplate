<?php

declare(strict_types=1);

namespace App\Shared\Application\ReadModel;

/**
 * What a port that paginates by page number hands back: the items and the position, together.
 *
 * Nothing implements both this and {@see SliceOf}, and that is deliberate (ADR 004, rule 8). There
 * is no interface above them promising "pagination"; a port returns one of the two and its
 * signature is where the choice is stated and enforced.
 *
 * `$total` is nullable because counting is a separate question, and usually the expensive half of
 * the request. Null does not mean "zero" and it does not mean "unknown by accident": it means this
 * port was not asked to count. `$hasNext` answers "is there more" without one, which the adapter
 * gets for free by asking the store for `size + 1` rows and returning `size` of them.
 *
 * @see Total for the three answers to "how many"
 *
 * @template T
 */
final readonly class PageOf
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $number,
        public int $size,
        public ?int $total,
        public bool $hasNext,
    ) {
    }

    /**
     * Only ever known when a total was paid for, which is why it is nullable rather than a
     * `getLastPage(): float` returning `INF` when it is not.
     */
    public function lastNumber(): ?int
    {
        if (null === $this->total) {
            return null;
        }

        return max(1, (int) ceil($this->total / $this->size));
    }
}
