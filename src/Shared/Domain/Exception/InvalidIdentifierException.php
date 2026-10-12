<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * What {@see \App\Shared\Domain\Models\Id::fromString()} raises for something that is not a ULID.
 *
 * It used to be `Symfony\Component\Uid\Ulid`'s own `\InvalidArgumentException`, which carries no
 * marker, so `exception_to_status` had nothing to say and a request naming `/members/banana`
 * answered **500**. That was the single most reachable defect of its kind in the tree, because every
 * endpoint takes an identifier.
 *
 * The one marker interface in `Shared\Domain\Exception` with an implementation next to it rather
 * than in a context, and ADR 002 is the reason: identity is a shared-kernel concept here, one
 * `Id<A>` class for every aggregate in every context, so "that is not an identifier" cannot belong
 * to `Membership` or to `Matching`. Its code carries `shared.` for the same reason.
 */
final class InvalidIdentifierException extends \InvalidArgumentException implements UnprocessablePayloadThrowable
{
    use DeclaredFailure;

    public static function notAUlid(string $value): self
    {
        return new self(SharedFailure::MalformedIdentifier, ['value' => $value]);
    }
}
