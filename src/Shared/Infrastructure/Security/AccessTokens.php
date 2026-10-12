<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Exception\InvalidIdentifierException;
use App\Shared\Domain\Models\Id;
use App\Shared\Domain\Models\PrincipalAggregate;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mints and verifies the bearer token. The only class in the tree that knows what a claim is called.
 *
 * ## A bundle, and the half of it that is deliberately not installed
 *
 * `lexik/jwt-authentication-bundle` ships a firewall authenticator, a user provider, a
 * `/api/login_check` endpoint and an API Platform integration. **This class uses its encoder and
 * nothing else**, and `config/packages/lexik_jwt_authentication.php` is where the omission is
 * argued. The short version: ADR 008 chose Symfony's native `access_token` authenticator plus our
 * own {@see JwtTokenHandler}, so that an identity server arrives as a configuration change and no
 * call site moves. Adopting Lexik's authenticator would replace that seam, and it would also
 * replace the three-question order {@see JwtTokenHandler} documents, whose second question is
 * "has this `jti` been withdrawn" and belongs to nobody else.
 *
 * What stood here before was `firebase/php-jwt`, three classes called directly, and the argument
 * for it was that the decisions this repository exists to teach stayed readable in this file rather
 * than inferable from a configuration block. That argument has not gone away; it has been **paid**.
 * The claims, the window and the one answer every failure gets are still the four paragraphs below.
 * What moved into configuration is the signature algorithm and the key material, which are the two
 * things an identity server would take over anyway, and which now live in one file that says so.
 *
 * ## Four claims, and the two that are not there
 *
 * `sub` is the **member**, not the credential and not the username. ADR 008's Keycloak section says
 * an identity server arrives as "a user provider that maps a `sub` claim onto an `Id<PrincipalAggregate>`", so
 * the claim carries the principal and a rename of the login handle does not invalidate a live
 * token. `jti` is what ADR 009 makes the revocation row. `iat` and `exp` are the window.
 *
 * All four are registered claims, which matters now that a library is assembling the token:
 * `LcobucciJWSProvider::create()` routes `sub`, `jti`, `iat` and `exp` through lcobucci's own
 * builder methods rather than through `withClaim()`, so what comes out is the same document
 * `firebase/php-jwt` produced from the same array. A token minted before this change still verifies
 * after it.
 *
 * No `iss` and no `aud`: there is one issuer and one audience, so both claims would be constants
 * the verifier compares against itself. They arrive the day a second service verifies our tokens,
 * which is the day this becomes an identity server's problem anyway.
 *
 * **No roles in the token.** {@see Actor} does not carry grants either, and for the same reason
 * ADR 008 gives: grants held on the bearer are a cache with no invalidation, so a fifteen-minute
 * token would be a fifteen-minute-old opinion about a permission withdrawn ten minutes ago.
 * {@see ActorTokens} looks them up per call, from the row.
 *
 * ## Fifteen minutes, and why revocation still matters
 *
 * ADR 008 names a fifteen-minute window twice, as the example of a decision outliving its decider,
 * so that is what {@see self::LIFETIME} is. A short window is not a substitute for revocation and a
 * revocation list is not a substitute for a short window: the list answers "this one, now", the
 * expiry bounds how long the list has to remember anything. ADR 009's table says the row lives
 * "until the token would have expired anyway", which is only a sentence anybody can act on because
 * this constant exists.
 *
 * The constant stays the one place that number is written. The bundle has a `token_ttl` of its own,
 * and the configuration file reads this constant instead of repeating it, for exactly the reason
 * ADR 005 gives for option A's constraint arguments: a configured copy of a domain constant is a
 * second answer waiting to disagree with the first.
 *
 * ## The key material, and the loud failure
 *
 * An RSA keypair under `config/jwt/`, generated per installation by the bundle's own
 * `php bin/console lexik:jwt:generate-keypair`, never committed, and pointed at by `.env`. The
 * algorithm is `RS256` and the configuration file argues it; the previous design signed with
 * `%kernel.secret%` under `HS256` and the reasoning for that is preserved there rather than deleted,
 * because it was correct and is simply no longer what the installed path does.
 *
 * ADR 009 copies a warning from `SwaggerUiProcessor` worth copying again: **a constructor that
 * throws is better than a null dereference in a request.** The constructor below still throws, and
 * the failure it catches moved rather than vanished. `issue()` is loud by itself, because
 * `JWTEncodeFailureException` names the private key and the passphrase. `verify()` is not: a
 * missing or unreadable key file makes every signature check fail, and `LcobucciJWTEncoder::decode()`
 * answers that with the same `JWTDecodeFailureException` a forged token gets. An installation with
 * no keypair would therefore answer `401` to every valid token it ever issued, silently, forever.
 * So the one thing the verifier cannot tell apart is asserted here, where it can still be named.
 */
final readonly class AccessTokens
{
    /**
     * ADR 008's own example of a window in which a decision outlives its decider. The one place to
     * change it, read by `config/packages/lexik_jwt_authentication.php` and by
     * {@see \App\Ui\Console\Security\RevokeTokenCommand}, and the reason {@see RevokedTokens} can
     * say how long it has to remember a `jti`.
     */
    public const string LIFETIME = 'PT15M';

    public function __construct(
        private JWTEncoderInterface $jwt,
        private ClockInterface $clock,
        /*
         * The same value the bundle is configured with, resolved the same way, and held here for
         * one assertion rather than for any use. It is not the key: it is the path, and this class
         * never reads the file. `public.pem` is deliberately not checked, because the bundle
         * computes a public key from the private one when the file is absent, so the private half
         * is the whole requirement.
         */
        #[Autowire('%env(resolve:JWT_SECRET_KEY)%')]
        string $signingKeyPath,
    ) {
        if (!\is_file($signingKeyPath) || !\is_readable($signingKeyPath)) {
            throw new \LogicException(\sprintf('No readable signing key at "%s", so no bearer token can be verified and every one of them would be refused as a forgery. Generate one with bin/console lexik:jwt:generate-keypair, or point JWT_SECRET_KEY at an existing key.', $signingKeyPath));
        }
    }

    /**
     * @param Id<PrincipalAggregate> $memberId
     */
    public function issue(Id $memberId): AccessToken
    {
        $issuedAt = $this->clock->now();
        $expiresAt = $issuedAt->add(new \DateInterval(self::LIFETIME));

        /** @var Id<AccessToken> $jti */
        $jti = Id::generate();

        // `JWTEncodeFailureException` on an unusable key or passphrase, and it is deliberately not
        // caught: a token that cannot be signed is a misconfigured installation, not a refusal, and
        // the operator reading the trace is the only person who can act on it.
        $encoded = $this->jwt->encode([
            'sub' => $memberId->toString(),
            'jti' => $jti->toString(),
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
        ]);

        return AccessToken::of($jti, $memberId, $expiresAt, $encoded);
    }

    /**
     * `null` for every way a presented string can fail to be one of our live tokens: not three
     * segments, not JSON, not signed by us, signed with an algorithm we did not ask for, expired,
     * or carrying claims that are not the two identifiers we put there.
     *
     * **One answer for all of them, deliberately.** ADR 008 rule 14: a refusal says nothing, and a
     * verifier that distinguished "expired" from "forged" would be telling somebody who forged a
     * token how close they got. The library hands over exactly that distinction, as
     * `JWTDecodeFailureException::getReason()`, and this is the line that throws it away.
     * Revocation is not checked here, because it needs the store and this class needs nothing;
     * {@see JwtTokenHandler} asks that question with the `jti` this returns.
     *
     * The decoder checks the signature and the `exp` itself and throws. The algorithm is pinned by
     * the configured one: `Lcobucci\JWT\Validation\Constraint\SignedWith` compares the `alg` header
     * against the signer before it verifies anything, so a token presenting `alg: none` is a
     * signature failure rather than a trusted document.
     */
    public function verify(#[\SensitiveParameter] string $presented): ?AccessToken
    {
        try {
            $claims = $this->jwt->decode($presented);
        } catch (JWTDecodeFailureException) {
            return null;
        }

        $subject = $claims['sub'] ?? null;
        $identifier = $claims['jti'] ?? null;
        $expiry = $claims['exp'] ?? null;

        if (!\is_string($subject) || !\is_string($identifier) || !\is_int($expiry)) {
            return null;
        }

        try {
            /** @var Id<PrincipalAggregate> $memberId */
            $memberId = Id::fromString($subject);
            /** @var Id<AccessToken> $jti */
            $jti = Id::fromString($identifier);
        } catch (InvalidIdentifierException) {
            return null;
        }

        return AccessToken::of($jti, $memberId, new \DateTimeImmutable('@'.$expiry), $presented);
    }
}
