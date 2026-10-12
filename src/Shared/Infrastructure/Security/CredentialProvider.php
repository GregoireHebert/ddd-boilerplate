<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Exception\InvalidIdentifierException;
use App\Shared\Domain\Models\Id;
use App\Shared\Domain\Models\PrincipalAggregate;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Where the credential is stored is the provider's problem, and `UserProviderInterface` has been the
 * seam for that since 2011. ADR 008 is explicit that nothing here improves on it: credentials live
 * in memory, in a table or in an identity server, and this interface is the seam for all three. This
 * implementation is the middle one.
 *
 * ## Two ways in, and they accept different things on purpose
 *
 * {@see self::loadUserByIdentifier()} takes **the handle a human typed**, which ADR 009 rule 10
 * makes a username and never a ULID. It is what the sign-in form submits and what the framework
 * contract means by an identifier.
 *
 * {@see self::loadUserByMemberId()} takes a principal identifier, and it exists because two callers
 * have one and neither of them has a login: {@see JwtTokenHandler}, whose `sub` claim carries the
 * member so that renaming a login does not invalidate a live token, and
 * {@see \App\Ui\Console\Security\AssertedActor}, whose `--as` names a member because it is an
 * operator assertion rather than a sign-in.
 *
 * The first implementation had one method doing both jobs, keyed on the member's ULID, which is the
 * mistake ADR 009 rule 10 names: it made the login form ask for a value nobody can read off a
 * screen or out of a password manager. Splitting it is what lets the form ask for a name while the
 * token keeps carrying the principal.
 *
 * ## The `Authority` it stamps, and why it is not the real one
 *
 * {@see SecurityUser} holds an {@see Actor}, and an `Actor` carries an {@see Authority} saying how we
 * know who is asking. This provider cannot know: it is shared by the stateful firewall and the
 * stateless one, and the same row answers a session login and a bearer token. So it stamps
 * {@see Authority::None} and **the provenance is stamped by the `CurrentActor` for the delivery**,
 * which is precisely what ADR 008's per-delivery table says each implementation is for.
 *
 * Nothing downstream reads the field on this object: {@see ActorTokens} already compares kind and
 * principal only, deliberately, and every audit line is written from the `Actor` a `CurrentActor`
 * returned rather than from the one inside a `SecurityUser`.
 *
 * ## Adding Keycloak
 *
 * This class is one of the two that move, and it is the cheap one. An OIDC deployment keeps the
 * firewall, the token, the roles, the voters and every call site, and replaces this with a provider
 * mapping a `sub` claim onto an `Id<PrincipalAggregate>`, which is the method below that already does exactly
 * that. No call site moves, because no call site ever named a credential: they name a permission and
 * they are handed an `Actor`.
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class CredentialProvider implements UserProviderInterface
{
    public function __construct(private Credentials $credentials)
    {
    }

    /**
     * The return type is narrowed from `UserInterface` to {@see SecurityUser}, which PHP allows and
     * which is what makes the `@implements` above true for a reader as well as for PHPStan.
     */
    #[\Override]
    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        $credential = $this->credentials->forUsername($identifier);

        if (null === $credential) {
            throw $this->notFound($identifier);
        }

        return $this->securityUser($credential);
    }

    /**
     * The principal's own identifier, for the two callers that hold one instead of a login.
     *
     * A string rather than an `Id<PrincipalAggregate>`, because both callers are handed a string by somebody
     * outside the application (a `sub` claim, a `--as` option) and this is the class whose job is
     * turning a presented value into a user. A value that is not a ULID and a value naming a member
     * with no credential leave the same way, for the reason {@see self::notFound()} gives.
     */
    public function loadUserByMemberId(string $memberId): SecurityUser
    {
        try {
            /** @var Id<PrincipalAggregate> $principalId */
            $principalId = Id::fromString($memberId);
        } catch (InvalidIdentifierException $notAUlid) {
            throw $this->notFound($memberId, $notAUlid);
        }

        $credential = $this->credentials->forMember($principalId);

        if (null === $credential) {
            throw $this->notFound($memberId);
        }

        return $this->securityUser($credential);
    }

    /**
     * Re-read from the table on every request the session is resumed on, rather than trusting what
     * was serialized into it. That is what makes revoking a credential take effect: ADR 008's rule 9
     * says a handler re-reads the **state** a decision depended on, and this is the same sentence
     * one layer down, where the state is the row itself.
     *
     * It goes back through the username, because that is what {@see SecurityUser::getUserIdentifier()}
     * answers and what Symfony serialized into the session.
     */
    #[\Override]
    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(\sprintf('Expected an instance of %s, got "%s".', SecurityUser::class, $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    #[\Override]
    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }

    public function securityUser(Credential $credential): SecurityUser
    {
        return new SecurityUser(
            Actor::member($credential->memberId(), Authority::None),
            $credential->username(),
            $credential->roles(),
            $credential->passwordHash(),
        );
    }

    /**
     * One failure for every arm, with no detail in it. A provider that answered "no such username"
     * differently from "that is not a ULID" would be an oracle, and ADR 008 rule 14 is that a
     * refusal says nothing. Symfony renders the sentence from its own `security` catalogue, which is
     * ADR 007's debt paid: we do not re-render an authentication failure.
     */
    private function notFound(string $identifier, ?\Throwable $previous = null): UserNotFoundException
    {
        $failure = new UserNotFoundException(previous: $previous);
        $failure->setUserIdentifier($identifier);

        return $failure;
    }
}
