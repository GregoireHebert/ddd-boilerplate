<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * The `If-Match` the caller sent is not the validator we hold. `412`.
 *
 * `\RuntimeException` rather than `\DomainException`, and {@see DeclaredFailure} explains why the
 * choice is visible at the class declaration: this is a failure of the *conversation*, not of the
 * domain's state. The domain is in a perfectly legal state; it is simply not the one the caller was
 * looking at.
 *
 * ## It takes no argument, and that is ADR 007's ninth rule rather than laziness
 *
 * The first draft was `::expected(string $held, string $sent)`, because handing a caller both
 * validators looks helpful. It is the same mistake ADR 007 found thirteen times: **a failure can
 * only leak what it hands over**, and an ETag is an opaque token the reader cannot act on. Quoting
 * `"a3f91c2d4b78"` back at somebody tells them nothing they can type, nothing they can recognise,
 * and nothing they could not get by reading the resource again, which is the one thing they have to
 * do anyway.
 *
 * The validator that *is* useful goes where a validator goes, which is a header on the next read.
 *
 * So this joins the five not-founds that are `::unknown()` and the three conflicts that are
 * `::again()`: a named constructor with nothing to take, because there was no argument worth taking.
 */
final class StalePreconditionException extends \RuntimeException implements PreconditionFailedThrowable
{
    use DeclaredFailure;

    public static function ifMatch(): self
    {
        return new self(SharedFailure::PreconditionFailed);
    }
}
