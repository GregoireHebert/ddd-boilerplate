<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Turns an {@see Actor} into the thing `AccessDecisionManagerInterface` votes on.
 *
 * This class is the price of the design and it is worth stating rather than hiding: ADR 008 says
 * the actor is an argument, the decision manager wants a `TokenInterface`, and something has to
 * bridge the two. It is also the seam the authentication commit fills, which is why it is one
 * concrete class and not an interface: there are no two implementations yet, and ADR 004's refusal
 * to add abstractions before there is a second caller applies to this repository's own code too.
 *
 * ## Two arms, and the first one avoids a round trip
 *
 * In a request, the token already exists: something authenticated the caller, the
 * {@see CurrentActor} for that delivery read an `Actor` off it, and rebuilding a token from that
 * `Actor` would be a round trip through our own vocabulary that also re-resolves roles the real
 * token already carries. So if the token in storage is already about this actor, it *is* the
 * answer.
 *
 * Outside a request there is nothing in storage, which is the case that made the port necessary at
 * all: `bin/console app:likes:cast --as X` has an actor and no session. The second arm builds a
 * `PreAuthenticatedToken`, and note what it does **not** do: it never writes to `TokenStorage`.
 * Pushing a fabricated token into storage is how an operator assertion becomes indistinguishable
 * from a login for everything downstream, and `Authority::Assertion` exists precisely so that it
 * stays distinguishable.
 *
 * ## The placeholder that was here, and what replaced it
 *
 * {@see self::credentialFor()} used to answer roles from the principal kind, which was the demo's
 * equivalent of the in-memory user provider `security.php` shipped with: every member is a member.
 * The authentication commit replaced that body with a lookup against the credential table, and, as
 * predicted, nothing else in this class moved. The only visible change is one more constructor
 * argument.
 *
 * It stays a lookup rather than a field on the `Actor`, and ADR 008 is emphatic about why: grants
 * held on the actor are a cache with no invalidation, which is what makes "the user lost the
 * permission in between" unfixable rather than merely awkward. Revoking a role is now an `UPDATE` on
 * one row, and the next call sees it.
 *
 * The lookup is per call and it is not free. It is also not in a loop: {@see Gate} asks a question
 * per operation, not per record, and the first arm below skips the lookup entirely for the common
 * case of a request that already has a real token carrying real roles.
 */
final readonly class ActorTokens
{
    /**
     * The firewall name a fabricated token reports. It is not a real firewall and nothing matches
     * on it; it is here so that a token built outside a request is recognisable as one in a
     * profiler or a log.
     */
    private const string FABRICATED_FIREWALL = 'gate';

    public function __construct(
        private TokenStorageInterface $tokens,
        private Credentials $credentials,
    ) {
    }

    public function for(Actor $actor): TokenInterface
    {
        if ($actor->isAnonymous()) {
            return new NullToken();
        }

        $current = $this->tokens->getToken();

        if ($current instanceof TokenInterface) {
            $user = $current->getUser();

            if ($user instanceof SecurityUser && $this->sameActor($user->actor(), $actor)) {
                return $current;
            }
        }

        $credential = $this->credentialFor($actor);
        $roles = $credential?->roles() ?? [];

        return new PreAuthenticatedToken(
            new SecurityUser($actor, $credential?->username() ?? '', $roles),
            self::FABRICATED_FIREWALL,
            $roles,
        );
    }

    private function sameActor(Actor $a, Actor $b): bool
    {
        return $a->kind === $b->kind
            && null !== $a->principalId
            && null !== $b->principalId
            && $a->principalId->equals($b->principalId);
    }

    /**
     * The credential table, which is where a role is a fact somebody can revoke.
     *
     * No `match` on the principal kind any more, and the loss is the improvement: a kind is what
     * somebody *is* and a role is what somebody *holds*, and deriving the second from the first made
     * every member identical forever. ADR 008 rule 10 draws the same line one level up, for the case
     * that will come first: **a role is a fact about the person, a suspension is a fact about the
     * account.** They look alike and only one of them is transactional with anything we own.
     *
     * A principal with no row gets no roles rather than an exception. Two ways that happens and both
     * are states rather than errors: an anonymous actor, whose `principalId` is null, and an actor
     * whose credential was deleted between the token being issued and this call, which is exactly
     * the revocation this lookup exists to make possible. An empty role list grants nothing, because
     * {@see RoleMap::grants()} loops over zero roles, so the failure mode is closed.
     *
     * It answers the row rather than the roles, because the caller needs two things off it and one
     * lookup is better than two: the roles, and the username that
     * {@see SecurityUser::getUserIdentifier()} answers with since ADR 009 rule 10 moved the login
     * handle off the ULID.
     */
    private function credentialFor(Actor $actor): ?Credential
    {
        if (null === $actor->principalId) {
            return null;
        }

        return $this->credentials->forMember($actor->principalId);
    }
}
