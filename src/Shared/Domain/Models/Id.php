<?php

declare(strict_types=1);

namespace App\Shared\Domain\Models;

use App\Shared\Domain\Exception\InvalidIdentifierException;
use Symfony\Component\Uid\Ulid;

/**
 * `A` is **covariant**, and that is a claim about this class rather than a convenience. `A` appears
 * in no parameter position anywhere below; it is a phantom, so an `Id<Member>` is usable wherever
 * an `Id<PrincipalAggregate>` is asked for, exactly as a `Member` is usable wherever a
 * `PrincipalAggregate` is. Without the annotation PHPStan treats the parameter as invariant and
 * refuses the widening, which is how {@see \App\Shared\Infrastructure\Security\Actor} ended up
 * naming a bounded context: widening was not available, so the shared type was narrowed instead.
 *
 * The narrowing direction stays unavailable, deliberately. Going from `Id<PrincipalAggregate>` back
 * to `Id<Member>` is an assertion only a delivery can make, and
 * {@see \App\Shared\Infrastructure\Security\Actor::subject()} is where it is made, once, with
 * the aggregate named as an argument.
 *
 * @template-covariant A
 */
final readonly class Id implements Equality
{
    private function __construct(
        private(set) Ulid $value
    )
    {
    }

    /**
     * The front door, in both directions: an edge parsing what a caller typed, and a Doctrine type
     * rehydrating a column. Only the first can fail, because the second reads back what this method
     * wrote.
     *
     * The `catch` is ADR 005. `Ulid::fromString()` throws a bare `\InvalidArgumentException`, which
     * implements no marker, so `exception_to_status` ignored it and `/members/banana` answered 500.
     * Now it is a violation like any other, collectable alongside the rest of the payload.
     *
     * @return self<A>
     */
    public static function fromString(string $id): self
    {
        try {
            return new self(Ulid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw InvalidIdentifierException::notAUlid($id);
        }
    }

    /**
     * @return self<A>
     */
    public static function generate(): self
    {
        return new Id(new Ulid());
    }

    public function getValue(): Ulid
    {
        return $this->value;
    }

    public static function isValid(mixed $uid): bool
    {
        if (!\is_string($uid)) {
            throw new \InvalidArgumentException('Identifier must be a valid string that represents a UUID');
        }

        return Ulid::isValid($uid);
    }

    /**
     * @param self<A> $identifierToCompare
     */
    /**
     * `self<*>` rather than `self`, because `A` is covariant and a bare `self` here would be
     * `self<A>`, which is a contravariant use of a covariant parameter and PHPStan refuses it.
     * The wildcard is honest: this method reads the wrapped `Ulid` and the phantom type has no
     * bearing on an ordering.
     *
     * @param self<*> $identifierToCompare
     */
    public function sort(self $identifierToCompare): int
    {
        return $this->value->compare($identifierToCompare->value);
    }

    #[\Override]
    public function equals($that): bool
    {
        // Ulid::equals() only ever returns true for another Ulid, so it has to be handed the
        // wrapped value and not the wrapper; passing $that straight through made every Id
        // different from every other Id, silently.
        return $that instanceof self && $this->value->equals($that->value);
    }

    public function __toString(): string
    {
        return $this->value->__toString();
    }

    public function toString(): string
    {
        return $this->value->__toString();
    }

    public function toBinary(): string
    {
        return $this->value->toBinary();
    }

    public function toRfc4122(): string
    {
        return $this->value->toRfc4122();
    }
}
