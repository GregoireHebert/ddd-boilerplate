<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Where the policy is actually consulted, and the one class a different subsystem replaces.
 *
 * It is a Symfony voter rather than something of ours because that is the component's extension
 * point and because of a property nothing else provides: **both entry points reach the same
 * voter.** {@see Gate::isGranted()} goes through `AccessDecisionManagerInterface`, and an
 * `is_granted("matching.like.cast")` expression generated onto an API Platform operation goes
 * through `AuthorizationCheckerInterface` to the same manager. One policy, two doors. Write the
 * gate over anything else and ADR 008's option A and option B start answering differently, which is
 * two policies with one name.
 *
 * ## `supports()` is where the vocabulary is enforced
 *
 * Two conditions and both of them are rules rather than plumbing.
 *
 * **`null === $subject`** is ADR 008's absent third argument, closed from the other side.
 * {@see Gate} cannot be handed an aggregate because its signature has nowhere to put one, but an
 * expression can (`is_granted("...", object)`), and API Platform's expression language hands the
 * object in. Refusing to support that case means an expression that tries to ask an ownership
 * question through the gate is not voted on at all, and with the affirmative strategy that is a
 * denial rather than a silent success. Ownership answers `404` from the aggregate; there is no way
 * to make it answer from here.
 *
 * **A declared permission** means an attribute nobody declared is not ours. A typo in a generated
 * expression fails closed, a `ROLE_*` string is left to Symfony's own `RoleVoter`, and the two
 * vocabularies never collide because this one only ever recognises what {@see Permissions} found.
 *
 * ## The token is read, never the storage
 *
 * `$token` arrives as an argument, which is the whole reason {@see AccessDecisionGate} can work
 * from a console command. A voter that reached for `TokenStorage` would work in a request and
 * return nothing in the two deliveries that have no request, which is the failure ADR 008 opens on.
 *
 * The `null` below is the same statement as the `supports()` check, made where PHPStan can read
 * it: this voter is about the actor and about nothing else.
 *
 * @extends Voter<string, null>
 */
final class PermissionVoter extends Voter
{
    public function __construct(
        private readonly Permissions $permissions,
        private readonly RoleMap $roles,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return null === $subject && null !== $this->permissions->tryFrom($attribute);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $permission = $this->permissions->tryFrom($attribute);

        if (null === $permission) {
            return false;
        }

        return $this->roles->grants($token->getRoleNames(), $permission);
    }
}
