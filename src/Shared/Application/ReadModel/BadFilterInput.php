<?php

declare(strict_types=1);

namespace App\Shared\Application\ReadModel;

use App\Shared\Domain\Exception\BadRequestThrowable;
use App\Shared\Domain\Exception\DeclaredFailure;
use App\Shared\Domain\Exception\SharedFailure;

/**
 * ADR 004, rule 11: "A failure to convert exposed arguments into filter values or a page request
 * MUST result in a 400 HTTP status or an exit code 22. Never a silent default."
 *
 * Both halves of that sentence are here, and only here. The HTTP half costs nothing: the marker
 * interface is already mapped in the `exception_to_status` block of
 * `config/packages/api_platform.php`, so a provider that lets this bubble gets an RFC 9457 problem
 * document for free. The console half is {@see self::EXIT_CODE}, which a command returns from a
 * `catch`.
 *
 * This lives in Application rather than in a context's Domain because a filter the user typed is
 * not a domain concept (ADR 004, "Architecture"): nothing in `Membership` or `Matching` has an
 * opinion about `?page=0`. What it does implement is a `Shared\Domain` marker, and per ADR 007 a
 * `Shared\Domain` failure code, which is the one direction allowed.
 *
 * Note what does *not* change here under ADR 007, because it is the ADR's own dividing line. The
 * *message* is translated like every other; the `Accept-Language` header that decides which
 * language it comes back in is not one of these parameters and never becomes one. A filter value
 * that will not parse is a 400; a locale that will not parse is negotiated down to
 * `default_locale`, because RFC 9110 §12.5.4 lets a server ignore the header entirely. One of them
 * is a parameter and the other is a preference, and they look identical right up until they fail.
 */
final class BadFilterInput extends \InvalidArgumentException implements BadRequestThrowable
{
    use DeclaredFailure;

    /**
     * 22 is EINVAL, and the ADR picked it so that a script can tell "you asked wrong" apart from
     * the 1 the console returns for every uncaught throwable.
     */
    public const int EXIT_CODE = 22;

    /**
     * @param list<string> $allowed
     */
    public static function unknownValue(string $parameter, string $value, array $allowed): self
    {
        return new self(SharedFailure::FilterUnknownValue, [
            'value' => $value,
            'parameter' => $parameter,
            'allowed' => implode(', ', $allowed),
        ]);
    }

    /**
     * A required parameter that is not there at all, which used to arrive as `notA($key, '', ...)`
     * and read as *"" is not an identifier*.
     */
    public static function missing(string $parameter): self
    {
        return new self(SharedFailure::FilterMissing, ['parameter' => $parameter]);
    }

    /**
     * One constructor per expected type, where there used to be a single `notA()` taking the type
     * as an English string. {@see SharedFailure::FilterNotABoolean} has the reason, and it is a
     * finding ADR 007 made by shipping rather than by reading.
     */
    public static function notABoolean(string $parameter, string $value): self
    {
        return new self(SharedFailure::FilterNotABoolean, ['value' => $value, 'parameter' => $parameter]);
    }

    public static function notAnInteger(string $parameter, string $value): self
    {
        return new self(SharedFailure::FilterNotAnInteger, ['value' => $value, 'parameter' => $parameter]);
    }

    public static function notAnIdentifier(string $parameter, string $value): self
    {
        return new self(SharedFailure::FilterNotAnIdentifier, ['value' => $value, 'parameter' => $parameter]);
    }

    public static function notADate(string $parameter, string $value): self
    {
        return new self(SharedFailure::FilterNotADate, ['value' => $value, 'parameter' => $parameter]);
    }

    /**
     * The three range failures ADR 004's `Page` and `Slice` can raise, likewise split out of one
     * `outOfRange()` that took the expectation as a clause.
     */
    public static function pageBelowOne(string $parameter, string $value): self
    {
        return new self(SharedFailure::PageBelowOne, ['value' => $value, 'parameter' => $parameter]);
    }

    public static function pageSizeTooSmall(string $parameter, string $value): self
    {
        return new self(SharedFailure::PageSizeTooSmall, ['value' => $value, 'parameter' => $parameter]);
    }

    public static function sliceSizeTooSmall(string $parameter, string $value): self
    {
        return new self(SharedFailure::SliceSizeTooSmall, ['value' => $value, 'parameter' => $parameter]);
    }

    /**
     * A cursor is opaque to everyone but the adapter that minted it, so there is nothing useful to
     * say about *why* it is wrong. Saying that much is the point: a caller who edits a cursor by
     * hand gets a 400 rather than a page computed from nonsense.
     */
    public static function malformedCursor(string $parameter): self
    {
        return new self(SharedFailure::FilterMalformedCursor, ['parameter' => $parameter]);
    }
}
