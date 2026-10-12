<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * The bearer half of authentication: a token arrives on the `Authorization` header, this says who
 * it is or refuses.
 *
 * ## What it replaces, and why the replacement is not a regression
 *
 * `OpaqueTokenHandler` stood here and looked the presented string up as the credential row's
 * primary key. ADR 008 argued for that shape and ADR 009 rule 9 corrects it, so the argument is
 * worth keeping in one piece rather than deleting with the file:
 *
 * > **an opaque token in our table is revocable and a JWT is not.**
 *
 * That sentence is true of a bare JWT and it is the reason this one carries a `jti`. What the
 * opaque design actually bought was revocation; what it cost was storing the bearer token in clear
 * text in a primary key, and making revocation a `DELETE` that destroyed the password login sharing
 * the row. A `jti` plus {@see RevokedTokens} buys the same revocation without either cost, at the
 * price of one indexed lookup per request. The trade the ADR was afraid of, a cached decision with
 * a TTL and no recall, is exactly the one this handler refuses to make.
 *
 * ## Three questions, in this order, and one answer to all of them
 *
 * Is it one of ours and still inside its window ({@see AccessTokens::verify()}); has it been
 * withdrawn ({@see RevokedTokens::isRevoked()}); and is there still a credential behind it
 * ({@see CredentialProvider::loadUserByMemberId()}). A malformed string, a forged signature, an
 * expired token, a revoked one and a deleted credential all leave here the same way.
 *
 * ADR 008 rule 14: a refusal says nothing. Telling them apart would turn this endpoint into an
 * oracle for which tokens have been issued and which members exist, to a caller who has just
 * demonstrated they hold neither. `BadCredentialsException` is Symfony's, rendered from Symfony's
 * `security` catalogue, which is the ADR 007 debt ADR 008 pays: we do not re-render an
 * authentication failure. The shape of the `401` is {@see SecurityEntryPoint}'s job and it is the
 * only thing about that answer we own.
 *
 * ## The badge carries the member, and the loader is why
 *
 * `sub` is an `Id<Member>` and not the login handle, for the reason {@see AccessTokens} gives: a
 * token names the principal, so renaming a login does not invalidate a live token, and ADR 008's
 * Keycloak paragraph describes an identity server arriving as "a provider that maps a `sub` claim
 * onto an `Id<Member>`", which is this line with somebody else's issuer behind it.
 *
 * `UserBadge`'s default behaviour would hand that string to `loadUserByIdentifier()`, which under
 * ADR 009 rule 10 takes **something a human typed** and would therefore refuse a ULID. So the badge
 * carries an explicit loader instead, and the two entry points into the provider stay honest about
 * what each of them accepts.
 */
final readonly class JwtTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private AccessTokens $tokens,
        private RevokedTokens $revoked,
        private CredentialProvider $credentials,
    ) {
    }

    #[\Override]
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->tokens->verify($accessToken);

        if (null === $token) {
            throw new BadCredentialsException();
        }

        if ($this->revoked->isRevoked($token->jti)) {
            throw new BadCredentialsException();
        }

        // The same string twice on purpose: it is what the badge reports for throttling and for the
        // log, and it is what the loader is handed. A `UserBadge` whose identifier and whose loader
        // disagree is a log line naming somebody other than the caller.
        $memberId = $token->subject->toString();

        return new UserBadge(
            $memberId,
            fn (): SecurityUser => $this->credentials->loadUserByMemberId($memberId),
        );
    }
}
