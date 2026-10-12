<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ReadModel;

use App\Shared\Application\ReadModel\BadFilterInput;
use App\Shared\Application\ReadModel\Slice;

/**
 * The token format behind {@see Slice::$after}.
 *
 * It lives in Infrastructure, and that placement is the whole design. A cursor is the ordering key
 * of one query, encoded; only the adapter that wrote the `ORDER BY` knows how many columns are in
 * it or what they mean. Everything above (the query object, the handler, the provider, the console
 * command) carries the string and never opens it, which is what lets a feed change its ordering key
 * without touching a single delivery.
 *
 * base64url rather than base64, because this travels in a query string. Each part is percent-encoded
 * first so that a separator inside a value cannot forge a boundary.
 *
 * It is deliberately not signed. A forged cursor can only ever ask for a different window of a list
 * the caller was already allowed to read, and it fails closed: a token this class did not mint gets
 * a 400 rather than a page computed from garbage.
 */
final class Cursor
{
    private const string SEPARATOR = '|';

    private function __construct()
    {
    }

    /**
     * @param list<string> $values the ordering key of the last row on the page, column by column,
     *                             in the same order as the `ORDER BY` that produced it
     */
    public static function encode(array $values): string
    {
        $packed = implode(self::SEPARATOR, array_map(rawurlencode(...), $values));

        return rtrim(strtr(base64_encode($packed), '+/', '-_'), '=');
    }

    /**
     * @param int $arity how many columns the caller's `ORDER BY` has. A cursor minted by a
     *                   different query, or edited by hand, does not have it and is refused here
     *                   rather than silently seeking on the wrong column.
     *
     * @return list<string>
     */
    public static function decode(string $parameter, string $cursor, int $arity): array
    {
        $packed = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (false === $packed) {
            throw BadFilterInput::malformedCursor($parameter);
        }

        $parts = explode(self::SEPARATOR, $packed);

        if (\count($parts) !== $arity) {
            throw BadFilterInput::malformedCursor($parameter);
        }

        return array_map(rawurldecode(...), $parts);
    }
}
