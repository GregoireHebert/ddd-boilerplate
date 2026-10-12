<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use App\Shared\Infrastructure\Security\AccessTokens;

/*
 * The signing half of ADR 009 rule 9's bearer token, and nothing else.
 *
 * ## What this bundle is here for, and what it is deliberately not here for
 *
 * `lexik/jwt-authentication-bundle` ships a firewall authenticator, a user provider, a
 * `/api/login_check` endpoint, cookie extractors and an API Platform integration. **None of that is
 * enabled, and the omission is the decision.** ADR 008 chose Symfony's native `access_token`
 * firewall authenticator plus our own `AccessTokenHandlerInterface` implementation, because that
 * seam is the one an identity server replaces without a call site moving;
 * {@see \App\Shared\Infrastructure\Security\JwtTokenHandler} is that implementation and it asks three
 * questions in an order ADR 009 argues for, the second of which (has this `jti` been withdrawn) no
 * bundle authenticator knows to ask.
 *
 * So the bundle is installed as **an encoder and a decoder**, reached through
 * `Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface` from exactly one class,
 * {@see AccessTokens}. `security.php` names no Lexik service, and `firewalls.*.jwt` stays absent.
 *
 * ## Four settings, and why each one is written down
 *
 * `secret_key`, `public_key` and `pass_phrase` are the recipe's, and `.env` says what they are and
 * how to make them. `token_ttl` and `encoder.signature_algorithm` are this file's own, because both
 * have a bundle default that would otherwise quietly answer a question this repository has already
 * answered somewhere else.
 */

return App::config([
    'lexik_jwt_authentication' => [
        // Paths, not key material: `.env` points at `config/jwt/`, which `.gitignore` keeps out of
        // the repository. `resolve:` is what expands `%kernel.project_dir%` inside the value.
        'secret_key' => '%env(resolve:JWT_SECRET_KEY)%',
        'public_key' => '%env(resolve:JWT_PUBLIC_KEY)%',
        'pass_phrase' => '%env(JWT_PASSPHRASE)%',

        // The bundle's default is 3600 and this repository's answer is `AccessTokens::LIFETIME`,
        // which ADR 008 names twice as its own example of a decision outliving its decider. The
        // constant is read rather than copied, so there is one fifteen minutes in the tree.
        //
        // Nothing reads this today: `AccessTokens::issue()` puts an explicit `exp` in the payload
        // and `LcobucciJWSProvider::create()` prefers it over the ttl. It is here so that the hour
        // cannot become the answer by default the day a second mint site appears, and because a
        // configuration file that silently disagrees with a constant is worse than one that repeats
        // it out loud.
        'token_ttl' => (new \DateTimeImmutable('@0'))->add(new \DateInterval(AccessTokens::LIFETIME))->getTimestamp(),

        'encoder' => [
            // The bundle's default, stated rather than inherited, because the class this replaces
            // carried the algorithm as a constant and argued for it in a docblock.
            //
            // That argument was for `HS256`: one issuer and one verifier in one process, so a
            // shared secret is enough and asymmetry buys nothing. It is still true, and it is not
            // what this says. A keypair is what the bundle is built around and what its own
            // `lexik:jwt:generate-keypair` produces, so taking it means the documented path works;
            // what it costs is a generated file per installation instead of a secret that already
            // existed. The day a second service verifies our tokens, that cost turns into the
            // reason, and nothing in {@see AccessTokens} changes when it does.
            //
            // It also pins the algorithm, which matters: `Lcobucci\JWT\Validation\Constraint\SignedWith`
            // refuses a token whose `alg` header is not this one, so `alg: none` is a signature
            // failure and not a trusted document.
            'signature_algorithm' => 'RS256',
        ],
    ],
]);
