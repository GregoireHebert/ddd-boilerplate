<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Selects the `api` firewall, and it does so by looking at the credential the request carries rather
 * than at the path it asks for.
 *
 * This is [ADR 009](docs/ADR/009_one-url-two-representations.md) rule 8, which is the rule this
 * class exists for and the only thing it knows:
 *
 * > **Two firewalls, chosen by the credential the request carries, never by the path.** A request
 * > with `Authorization: Bearer` is the `api` firewall's and is `stateless`; everything else is the
 * > `web` firewall's and may have a session.
 *
 * ## Why a class rather than a `pattern`
 *
 * `pattern` is sugar for a path matcher, and a path cannot answer this question. One signed-in
 * reader's page, the `<img>` elements inside it and the `fetch()` calls it makes all travel with one
 * cookie and ask for three different media types at three different URLs; a path list splits that
 * reader across two firewalls and one of the halves lands on a `stateless` one that never reads the
 * session. That is not hypothetical: `^/(login|logout|badge-designs|register|me)(/|$)` was the list,
 * and `GET /badge-models` answered `302 /login` to a reader who had just signed in while answering
 * `200 text/html` to a bearer token, which is ADR 009's rule 1 defeated at the security layer.
 *
 * `request_matcher` is a first-class firewall option
 * ({@see \Symfony\Bundle\SecurityBundle\DependencyInjection\MainConfiguration}) and takes a service
 * id implementing {@see RequestMatcherInterface}. It replaces `pattern` outright rather than
 * narrowing it, so the `api` firewall below declares no path at all.
 *
 * ## It does not read the format, and it does not read the token
 *
 * Two deliberate narrownesses, each the other's opposite.
 *
 * **It does not ask what the caller wants back.** A bearer token asking for `text/html` is still the
 * API's caller, and a cookie asking for `application/ld+json` is still the browser's `fetch()`. The
 * media type says which representation to render, which is the rendering processor's business under
 * rule 2; the credential says how the caller proved themselves, which is this one's.
 *
 * **It does not ask whether the token is any good.** The regex Symfony's
 * {@see \Symfony\Component\Security\Http\AccessToken\HeaderAccessTokenExtractor} applies is stricter
 * than this one and is case sensitive on the scheme, so a malformed or oddly cased
 * `Authorization: Bearer` lands here as a match and extracts to nothing. That is the answer we want:
 * the caller meant to present a token, so they get the `api` firewall's refusal, a `401` carrying
 * `WWW-Authenticate`, rather than being quietly demoted to an anonymous browser and redirected to a
 * login page they cannot read. **Intent selects the firewall; validity is the authenticator's
 * verdict.** A matcher that validated would be a second, earlier copy of {@see JwtTokenHandler}.
 *
 * ## Everything else is the `web` firewall's, including requests with no credential at all
 *
 * There is no matcher for `web`: it is the catch-all, so an anonymous request lands on it whatever
 * it was asking for. That is harmless because {@see SecurityEntryPoint} content-negotiates the
 * refusal under rule 11, so an anonymous browser is redirected to `/login` and an anonymous JSON
 * client gets the same RFC 9457 document it got when the catch-all was the other firewall. The
 * entry point was built for exactly this, and it is what lets the split be this coarse.
 *
 * `Authorization: Basic` and any other scheme therefore fall to `web` too, deliberately: this tree
 * has one bearer-token authenticator and one form, and a scheme neither of them implements is not a
 * credential here.
 */
final readonly class BearerTokenRequestMatcher implements RequestMatcherInterface
{
    /**
     * RFC 9110 §11.1 makes an `auth-scheme` case insensitive, so the comparison is too. Symfony's
     * extractor is not, which is the looseness the docblock above argues for rather than a
     * disagreement to resolve.
     */
    private const string SCHEME = 'bearer ';

    #[\Override]
    public function matches(Request $request): bool
    {
        $authorization = $request->headers->get('Authorization');

        if (!\is_string($authorization)) {
            return false;
        }

        return str_starts_with(strtolower(ltrim($authorization)), self::SCHEME);
    }
}
