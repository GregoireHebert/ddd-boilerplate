<?php

declare(strict_types=1);

namespace ApiPlatform\State\Pagination;

use App\Shared\Application\ReadModel\PageOf;

/**
 * Our {@see PageOf} rendered as API Platform's paginator, and the direction of that sentence is the
 * point.
 *
 * ADR 004 refuses to copy `PaginatorInterface` inward, because it is shaped by what a JSON-LD
 * `hydra:view` needs to emit rather than by what a store can answer, and its own types say so:
 * `getTotalItems(): float` exists to allow `INF` for "I do not know". Adopt that inward and every
 * read model in the project inherits an obligation to produce a total.
 *
 * So it stays out there, and this class is the adapter at the boundary. The application says "page
 * 3 of 42 over these twenty rows" in its own words, and one class in the delivery layer translates.
 * That is the opposite of the deleted `PaginationContextProvider`, which took the framework's
 * pagination and pushed it inward through a context key.
 *
 * It is the exact-total half of rule 8. A `PageOf` with no total cannot be rendered here, by
 * construction, because a `PaginatorInterface` that invents a last page is worse than one that does
 * not exist: use {@see PartialPageOfPaginator}.
 *
 * @template T of object
 *
 * @implements PaginatorInterface<T>
 * @implements \IteratorAggregate<mixed, T>
 */
final readonly class PageOfPaginator implements PaginatorInterface, \IteratorAggregate
{
    /**
     * @param PageOf<T> $page
     */
    public function __construct(private PageOf $page)
    {
        if (null === $page->total) {
            throw new \LogicException(\sprintf('%s renders a counted page. A page whose total is null has no last page to link to; use %s.', self::class, PartialPageOfPaginator::class));
        }
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->page->items);
    }

    /**
     * The number of items on *this* page, which is what API Platform's collection normalizer
     * documents and not what `hydra:totalItems` gets.
     */
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

    #[\Override]
    public function getLastPage(): float
    {
        return (float) ($this->page->lastNumber() ?? 1);
    }

    #[\Override]
    public function getTotalItems(): float
    {
        return (float) ($this->page->total ?? 0);
    }
}
