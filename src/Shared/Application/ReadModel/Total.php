<?php

declare(strict_types=1);

namespace App\Shared\Application\ReadModel;

/**
 * "How many are there" is a question of its own, asked next to the filter rather than implied by
 * it, and it has three answers at three prices (ADR 004, "The total is another thing on its own").
 *
 * A port only takes this when it genuinely offers the choice. A feed does not, so it does not
 * mention it; counting an infinite scroll is a question nobody asked.
 */
enum Total: string
{
    /**
     * A `COUNT` over the *filtered* set, which is the only count that is not simply wrong. On a
     * join-heavy query it can cost more than the page it describes.
     */
    case Exact = 'exact';

    /**
     * Ask the store for `size + 1` rows, hand back `size`, and the row you dropped is "there is
     * more". It costs nothing and it is what a "next" button actually needs.
     */
    case None = 'none';

    /**
     * "About 4,000 results". `reltuples` on PostgreSQL, or a cached count. Good enough for a
     * result-count label, useless for a last-page link.
     */
    case Estimated = 'estimated';

    public static function parse(string $parameter, string $raw): self
    {
        return self::tryFrom($raw) ?? throw BadFilterInput::unknownValue(
            $parameter,
            $raw,
            array_map(static fn (self $case): string => $case->value, self::cases()),
        );
    }
}
