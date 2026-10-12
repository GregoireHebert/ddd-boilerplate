<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Application\Security\Forbidden;
use App\Shared\Application\Security\NotAuthenticated;
use App\Shared\Domain\Models\Id;
use App\Shared\Domain\Models\PrincipalAggregate;

/**
 * Who is asking, and how we know. ADR 008's second rule in one class:
 *
 * > **The actor is an argument, never ambient.** Four deliveries, two of which have no request, no
 * > session and no token storage. Anything that reads the current user from the air works in one of
 * > them and lies in the other three.
 *
 * ## What it does not carry
 *
 * No roles, no grants, no token, no credential. That is the decision that makes revocation
 * possible at all: grants held on the actor are a cache with no invalidation, and an actor that
 * knows what it may do is one somebody will serialize into a message, where it becomes a
 * fifteen-minute-old opinion about a permission that was withdrawn ten minutes ago.
 *
 * {@see Gate} looks the grants up instead, per call, which is also what Keycloak and Casbin do.
 *
 * ## Why this is a `Ui` type
 *
 * Knowing who is asking is a delivery concern: the four deliveries answer it four ways and the
 * Application layer receives an `Id<PrincipalAggregate>` and cannot tell which. Putting it in
 * `Shared\Application` would let a handler ask, and a handler that *can* ask will, on the far side
 * of the async channel, where there is no actor.
 *
 * The practical consequence is a rule rather than a preference: **an `Actor` never enters a command
 * payload.** It would compile, because `Ui` may name every context's `Application`; it would also
 * make every context depend on the delivery layer the first time a handler touched the field, and
 * phpat would catch it one commit too late.
 */
final readonly class Actor
{
    /**
     * @param Id<PrincipalAggregate>|null $principalId
     */
    private function __construct(
        public PrincipalKind $kind,
        public ?Id $principalId,
        public Authority $via,
    ) {
    }

    /**
     * @param Id<PrincipalAggregate> $memberId
     */
    public static function member(Id $memberId, Authority $via): self
    {
        return new self(PrincipalKind::Member, $memberId, $via);
    }

    /**
     * An operator saying who this is for, and the only constructor an HTTP delivery must never
     * call. It is a convention rather than something phpat can see: phpat reasons about class
     * dependencies and every delivery already depends on this class. The one call site is under
     * `Ui/Console/`, and {@see Authority::Assertion} carries the reason it is worth marking at all.
     *
     * @param Id<PrincipalAggregate> $memberId
     */
    public static function asserted(Id $memberId): self
    {
        return new self(PrincipalKind::Member, $memberId, Authority::Assertion);
    }

    /**
     * There is no `moderator()` constructor yet, and the absence is a decision rather than an
     * oversight.
     *
     * {@see PrincipalKind::Moderator} is declared, and {@see self::subject()} already refuses it,
     * because those two are what would be expensive to retrofit: a refusal added after every call
     * site was written against a single kind is a refusal somebody has to go and audit. The
     * constructor is not, because it needs a phantom tag for a moderator's identifier and there is
     * no `Trust` context to hold one. Inventing the tag here would put a domain concept in a
     * delivery, which is the mistake this whole directory exists to avoid.
     *
     * So the case arrives with the context that needs it. ADR 002 is the reason it cannot simply
     * reuse `Id<PrincipalAggregate>`: the phantom type is the entire point, and an `Id<PrincipalAggregate>` holding a
     * moderator would typecheck everywhere and be wrong everywhere.
     */

    /**
     * Nobody is asking. Not a principal with no rights: the absence of one.
     */
    public static function anonymous(): self
    {
        return new self(PrincipalKind::Nobody, null, Authority::None);
    }

    public function isAnonymous(): bool
    {
        return PrincipalKind::Nobody === $this->kind;
    }

    /**
     * The member this request is *about*, which under ADR 008 is always the principal and never the
     * payload or a URI segment.
     *
     * **It throws rather than returning null, and that is the point of the class.** "The subject of
     * a write is the principal" stops being a convention somebody remembers and becomes a refusal
     * raised at the one place where the absence is knowable. The alternative is a null travelling
     * into a command as somebody's identifier, which fails much later and somewhere less useful.
     * It is ADR 005's "one home per predicate", applied to identity instead of to a handle.
     *
     * Two refusals, because there are two ways to not be a member, and they are different questions
     * with different statuses:
     *
     * - nobody is asking, so authenticating is the next move. `401`;
     * - a moderator is asking, and this operation is for members. `403`, because that is a fact
     *   about the caller and tells them nothing about our data.
     *
     * ## Why it takes the aggregate it is about to return an identifier for
     *
     * `Shared` may not name a bounded context, so the principal is held as an
     * `Id<PrincipalAggregate>`. A command does not want that: `AcquireBadge`
     * wants an `Id<Member>`, and widening it to the marker would push the weaker type into every
     * handler and aggregate downstream, which is the leak this whole arrangement exists to stop,
     * arriving from the other side.
     *
     * So the narrowing happens **here, once, named**. `$principal` is not read and cannot be: it is
     * a `class-string<T>` whose only job is to let PHPStan resolve `T`, so a caller writes
     * `$actor->subject(Member::class)` and gets an `Id<Member>` back. The alternative was
     * `@var Id<Member>` at twenty three call sites, which is the same unchecked assertion made
     * twenty three times instead of declared once.
     *
     * **Reusing this tree means passing your own principal aggregate here**, and nothing under
     * `Shared` changes.
     *
     * The assertion is still an assertion rather than a proof, and ADR 002 already says why: `Id`
     * is one Ulid-wrapping class regardless of its phantom `@template`, so PHPStan believes this
     * and PHP cannot check it. `InspectPairCommand` is kept broken in the tree to demonstrate
     * exactly that limit.
     *
     * @template T of PrincipalAggregate
     *
     * @param class-string<T> $principal the aggregate this application authenticates as
     *
     * @return Id<T>
     */
    public function subject(string $principal): Id
    {
        if (null === $this->principalId) {
            throw NotAuthenticated::required();
        }

        if (PrincipalKind::Member !== $this->kind) {
            throw Forbidden::refused();
        }

        /** @var Id<T> */
        return $this->principalId;
    }

    /**
     * One fragment of the audit line ADR 008 asks for, written at the edge by whatever took the
     * decision, before the command goes on the bus.
     *
     * `via` is the reason {@see Authority} is a field rather than a comment: an operator assertion
     * and a login produce identical effects everywhere downstream and must not produce identical
     * log lines. This is the only place in the design where the provenance is load-bearing.
     */
    public function describe(): string
    {
        return \sprintf(
            'actor=%s kind=%s via=%s',
            $this->principalId?->getValue()->toBase32() ?? '-',
            $this->kind->value,
            $this->via->value,
        );
    }
}
