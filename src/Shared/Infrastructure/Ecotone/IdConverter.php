<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ecotone;

use App\Shared\Domain\Models\Id;
use Ecotone\Messaging\Attribute\Converter;

/**
 * Round-trips {@see Id} across a serializing channel (the `async` transport, ADR 003 path 4/5's
 * cross-store hops, anything Ecotone hands to Symfony Messenger). `IdType` already does this job for
 * a Doctrine column; this is the same conversion for a message payload, because CLAUDE.md's rule is
 * literal: sync versus async is the transport's concern, so a channel that serializes needs a
 * converter rather than a primitive command, and a command still carries value objects (ADR 005).
 *
 * The wire shape is a bare ULID string, both ways: `toString()` out, `Id::fromString()` back in.
 * That is the same pair `Id` already exposes for a caller-typed identifier (ADR 002), so the
 * converter adds no vocabulary of its own; it agrees with the rest of the tree by reuse, not by a
 * second implementation that could drift from it.
 *
 * **The generic problem is real and this does not solve it.** `Id` carries a phantom `@template A`
 * (ADR 002) that exists only in PHPDoc; there is nothing on the wire to restore it from, so a value
 * this converter produces is an `Id<mixed>` as far as PHPStan's generics checking is concerned, same
 * as the deliberately-broken `Id<Member>`-into-`Id<Pair>` call in
 * `InspectPairCommand`. Adding a type parameter to the wire format
 * would let this one call site fake it back, but every other deserialization site would still be
 * blind, so it is not done here; PHPStan flags what it can flag and the rest stays an honest limit
 * of the convention rather than a papered-over one.
 */
final class IdConverter
{
    /**
     * @param Id<mixed> $id
     */
    #[Converter]
    public function idToString(Id $id): string
    {
        return $id->toString();
    }

    /**
     * @return Id<mixed>
     */
    #[Converter]
    public function stringToId(string $value): Id
    {
        return Id::fromString($value);
    }
}
