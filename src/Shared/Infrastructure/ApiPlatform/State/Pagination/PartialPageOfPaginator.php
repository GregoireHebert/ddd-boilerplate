<?php

declare(strict_types=1);

namespace ApiPlatform\State\Pagination;

use App\Shared\Application\ReadModel\PageOf;

/**
 * The same {@see PageOf}, rendered for the mode where nobody paid for a count.
 *
 * API Platform supports this natively and cheaply, which is worth knowing because the expensive mode
 * is the one you get by default. Implementing only `PartialPaginatorInterface`:
 *
 *  - `CollectionNormalizer` leaves `hydra:totalItems` out of the response entirely. It adds it for a
 *    `PaginatorInterface`, and for a plain `Countable`, and explicitly not for this;
 *  - `PartialCollectionViewNormalizer` emits a `hydra:view` with `previous` and `next` and without
 *    `first`/`last`, because a last page is exactly what is not known.
 *
 * One honest limitation, and it is the framework's rather than ours. That `next` link is emitted
 * when `count($paginator) >= itemsPerPage`, in other words it is *guessed* from "the page came back
 * full". Our port does not guess: {@see PageOf::$hasNext} was computed by asking the store for one
 * row more than the caller wanted. There is no method on this interface to say so, so a page that
 * is exactly full and also last still advertises a `next` that leads to an empty collection. The
 * console rendering of the same query has no such problem, because nothing there is inferred.
 *
 * @template T of object
 *
 * @implements PartialPaginatorInterface<T>
 * @implements \IteratorAggregate<mixed, T>
 */
final readonly class PartialPageOfPaginator implements PartialPaginatorInterface, \IteratorAggregate
{
    /**
     * @param PageOf<T> $page
     */
    public function __construct(private PageOf $page)
    {
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->page->items);
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->page->items);
    }

    #[\Override]
    public function getCurrentPage(): float
    {
        return (float) $this->page->number;
    }

    #[\Override]
    public function getItemsPerPage(): float
    {
        return (float) $this->page->size;
    }
}
