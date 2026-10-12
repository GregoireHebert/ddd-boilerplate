<?php

declare(strict_types=1);

namespace App\Shared\Application\ReadModel;

/**
 * A page request: "rows 40 to 59 of the ordered set", expressed the way a user expresses it.
 *
 * It travels *inside* the query object, next to the filter values, because ADR 004 says a page
 * request is an exposed parameter like any other: same query string, same `InputOption` list, same
 * parse, same failure. There is no second pipeline for it and nothing puts it in a context key.
 *
 * Note what is missing: there is no `offset()` here. `(number - 1) * size` is how *one* family of
 * stores implements this ask, and the adapter that writes `setFirstResult()` is the only thing
 * entitled to say so. A store that paginates by page token would still be handed this object, and
 * an `offset()` on it would have been a lie.
 *
 * @see Slice for the other mechanism, and ADR 004's "Two mechanisms, and the port says which"
 */
final readonly class Page
{
    public function __construct(
        public int $number,
        public int $size,
    ) {
        // Rule 11, applied at the type instead of at each of the three deliveries. `page=0` cannot
        // reach a read model as a silent page 1, because there is no way to build the object.
        if ($number < 1) {
            throw BadFilterInput::pageBelowOne('page', (string) $number);
        }

        if ($size < 1) {
            throw BadFilterInput::pageSizeTooSmall('itemsPerPage', (string) $size);
        }
    }
}
