<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

/**
 * ADR 008: **ask for a permission, never for a role.**
 *
 * `is_granted('ROLE_MODERATOR')` at a call site is not a question, it is an answer somebody already
 * committed to. It hard-codes *who* may do the thing into the place that does the thing, and every
 * subsystem worth swapping to (Keycloak, Casbin, OPA) exists precisely to move that decision
 * elsewhere. A role map with six entries is a perfectly good policy; the mistake is that a call
 * site can see it.
 *
 * ## Why an enum, and why `cases()` is the point
 *
 * The same argument {@see \App\Shared\Domain\Exception\FailureCode} makes, one seam over. Declared
 * rather than derived, so a rename is not a breaking change for a policy server that has our
 * vocabulary in its own store. Stable and published, so it can be. And above all **enumerable**,
 * which is what makes "every write is guarded" a checkable property rather than a hope: a scanner
 * that found nine operations out of ten reports success, and `cases()` against the declared
 * operations reports the gap and names it.
 *
 * ## Why the enum is the vocabulary and the string is the wire
 *
 * {@see self::wire()} exists for the same reason `FailureCode::code()` does, and this time there is
 * a concrete constraint behind it rather than only a narrowing. Symfony's abstract
 * {@see \Symfony\Component\Security\Core\Authorization\Voter\Voter::supports()} is typed `string`,
 * Casbin's `enforce()` takes strings, and Keycloak's scopes are strings. So the adapter converts
 * before it calls anything: a typed enum on our side of the seam, a documented string on theirs.
 *
 * ## Why these live in `Ui`
 *
 * A failure code lives in a context's `Domain` because a domain raises it. A permission is never
 * raised by a domain and never read by one: the set of permissions is the set of operations the
 * deliveries expose, which is a delivery fact. Putting them here also pays for itself immediately,
 * because it is what lets one command walk the declarations and the operations together.
 *
 * ## Why they are dot-separated
 *
 * Because everything published by this application is. It is the fourth such vocabulary after
 * routing keys, failure codes and RPC method names, and ADR 008 is explicit that it must *not*
 * reuse any of them: a routing key names a handler, a permission names a capability, and they
 * diverge wherever one command backs two operations with different audiences, or wherever an
 * operation exposes no command at all, which is every read.
 *
 * @see \App\Shared\Infrastructure\Security\Permission\MembershipPermission
 * @see \App\Shared\Infrastructure\Security\Permission\MatchingPermission
 */
interface Permission extends \BackedEnum
{
    /**
     * The published name, which is the enum's own backing value. The method exists so a caller can
     * hold the interface and still get a `non-empty-string` without every implementation restating
     * the narrowing.
     *
     * @return non-empty-string
     */
    public function wire(): string;
}
