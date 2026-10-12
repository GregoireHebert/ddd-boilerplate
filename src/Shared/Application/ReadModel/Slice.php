<?php

declare(strict_types=1);

namespace App\Shared\Application\ReadModel;

/**
 * A keyset request: "the 20 rows after this one".
 *
 * `$after` is opaque here and stays opaque all the way to the delivery layer. Only the adapter that
 * minted it knows which columns it encodes, which is what lets a feed change its ordering key
 * without changing a single query string, an `InputOption` or a port signature.
 *
 * What this shape *cannot* express is the point of having two: there is no page number to ask for,
 * so nobody can ask a feed for its page 7, and there is no last page to jump to.
 *
 * @see Page for the other mechanism
 */
final readonly class Slice
{
    public function __construct(
        public ?string $after,
        public int $size,
    ) {
        if ($size < 1) {
            throw BadFilterInput::sliceSizeTooSmall('size', (string) $size);
        }
    }
}
