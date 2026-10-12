<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Models\PrincipalAggregate;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * The other half of a sign-in: `POST /login` sent as JSON answers with a bearer token, where the
 * same route sent as a form answers with a session and a redirect.
 *
 * This is [ADR 009](docs/ADR/009_one-url-two-representations.md) rule 16, and
 * {@see SecurityEntryPoint} is its mirror: that class is the refusal and this one is the grant. Both
 * run before routing, both answer a question no provider and no handler has been asked yet, and
 * between them they are the whole of what `/login` can say.
 *
 * ## A session and a bearer token are not two representations of one thing
 *
 * It is tempting to file this under rule 1, because `/login` is one URL answering two shapes, and
 * the temptation is worth naming because it is wrong. Rule 1 is about a **resource**: one noun, one
 * address, several renderings of the same state. Here there is no state being rendered twice. There
 * are two different credentials:
 *
 * | | the server keeps | revoked by | survives a restart | CSRF applies |
 * |---|---|---|---|---|
 * | session | the whole of it | destroying the row | as long as the store does | yes |
 * | bearer token | a `jti`, and only once withdrawn | a row in `ui_revoked_token` | it is the client's | no |
 *
 * Nothing in that table is a rendering choice. So rule 16 is not rule 1 applied to a login; it is
 * rule 8 applied one step earlier. Rule 8 says the firewall is chosen by the credential a request
 * **carries**. A sign-in carries none, which is why it lands on `web` whichever one it wants, and
 * rule 16 finishes the sentence: **a request that carries no credential and asks for one is answered
 * with the credential its encoding implies.**
 *
 * ## So the selector is `Content-Type` and not `Accept`, which is the one place this repository
 * negotiates on the request rather than on the response
 *
 * `JsonLoginAuthenticator::supports()` claims the request when its format or its content type is
 * JSON (`security-http/Authenticator/JsonLoginAuthenticator.php:66`), and `form_login` is given
 * `form_only: true` next door so that `FormLoginAuthenticator::supports()` stops claiming every
 * `POST` to the same path (`:71`). Those two lines are the whole of the split, and without the
 * second one the form authenticator would swallow a JSON body and report a missing `_username`.
 *
 * Reading `Accept` instead was the first design and it is worse, for a reason worth keeping: a
 * browser page's own `fetch()` sends JSON and asks for `application/ld+json`, so `Accept` cannot
 * tell a first-party script from a third-party client, while `Content-Type` describes the thing
 * actually being submitted. And the question here is not "how would you like this rendered", it is
 * "which of two credentials did you come for", which is a fact about the caller rather than about
 * the response.
 *
 * ## Why this is not a state processor, and `POST /tokens` does not exist
 *
 * A resource would have been the house style: a `TokenResource` with a processor, serialized by API
 * Platform, documented in the OpenAPI deliverable for free, and rule 2 kept whole because
 * `RenderingProcessor` would own the output. It was refused, and the sentence that refuses it was
 * already written in {@see \App\Ui\Web\LoginFormController}:
 *
 * > A controller that verified a password would be a second home for authentication, in a delivery,
 * > which is the shape three ADRs have now removed from three different places.
 *
 * A processor is a controller with a better name. Verifying a password there would skip
 * `CheckPassportEvent` and everything listening on it, which today is the password hasher and the
 * user checker and tomorrow is whatever an installation adds; it would need its own timing-safe
 * comparison and its own rehash-on-login; and it would put ADR 008's first question in the one
 * layer ADR 008 says it must never be in. So the firewall authenticates, as it does for every other
 * request in the tree, and this class answers after it.
 *
 * **Rule 2 survives, for {@see SecurityEntryPoint}'s reason and not by exception.** That rule
 * reserves the turning of state into a representation for one class, because a provider or a read
 * model that branches on the format has become two of itself. Nothing has been provided here.
 * There is no resource, no state and no serializer context; there is a credential that did not
 * exist a line earlier and a client holding the only copy. A grant has nothing to fork, exactly as
 * a refusal does not.
 *
 * ## The response is `application/json` and carries no IRI
 *
 * Not JSON-LD, and the omission is deliberate rather than lazy. An `@id` is a promise that the thing
 * can be fetched there, and a bearer token has no address this application is willing to publish:
 * a `GET` on it would serve the credential to whoever asked. ADR 006 draws that line for a badge in
 * a badge collection and it draws it harder here. `jti` is not in the body either, for the same reason the
 * refusals do not name a permission, and because the client has it: it is a claim inside the token
 * they were just handed.
 *
 * `expiresAt` is, because it is the one thing a client must act on and the alternative is parsing
 * our JWT to find out when to stop using it. {@see AccessTokens::LIFETIME} is where the number
 * lives; this publishes the instant rather than the duration, so a clock skew is the client's to
 * measure rather than ours to assert.
 *
 * `200` and not `201`. A `201` is a promise of a `Location`, and the paragraph above is the reason
 * there is none to send.
 *
 * ## Nothing is signed in, and that is the line that does it
 *
 * `AuthenticatorManager::handleAuthenticationSuccess()` calls `$this->tokenStorage->setToken()`
 * before it calls this class (`security-http/Authentication/AuthenticatorManager.php:241`), and the
 * firewall this runs on is **stateful**, so `ContextListener::onKernelResponse()` would serialize
 * that token into a session and set a cookie. The client would leave holding two credentials, one
 * of which it never asked for, on a firewall whose whole contract with a bearer token is that there
 * is no cookie to forge a request with.
 *
 * So the token storage is emptied, and the emptying is the statement rather than the workaround:
 * **issuing a bearer token is not signing anybody in.** `ContextListener` then sees no authenticated
 * token, finds no previous session to clear, starts none, and the response carries no `Set-Cookie`
 * (`security-http/Firewall/ContextListener.php:175`). Verified by measuring the response headers,
 * because this is the kind of thing that works until a vendor upgrade and says nothing when it
 * stops.
 *
 * ## The failure is {@see SecurityEntryPoint}'s, not this class's
 *
 * `json_login` names that class as its `failure_handler`, so a wrong password here answers the same
 * RFC 9457 document as a wrong bearer token two paragraphs over, with the same
 * `shared.not-authenticated` and the same refusal to say which of the two it was. There is no
 * success handler's worth of code for the unhappy path because there is no second opinion about
 * what a refusal looks like.
 */
final readonly class AccessTokenGrant implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private AccessTokens $tokens,
        private TokenStorageInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();

        // Unreachable while `credentials` is the provider on both firewalls, and loud rather than
        // silent if that ever stops being true: a grant that could not name its subject would
        // otherwise mint a token for nobody. {@see CredentialProvider::securityUser()} is the one
        // place these are built.
        if (!$user instanceof SecurityUser) {
            throw new \LogicException(\sprintf('A sign-in succeeded with a %s, and only a %s carries the member a bearer token is issued for.', get_debug_type($user), SecurityUser::class));
        }

        // The member and not the credential, which is `AccessTokens`' decision and the reason
        // renaming a login handle does not invalidate a live token.
        $issued = $this->tokens->issue($user->actor()->subject(PrincipalAggregate::class));

        // See the docblock. This is the whole of "stateless", and it is one line rather than a
        // third firewall.
        $this->storage->setToken(null);

        // The same line `bin/console app:tokens:issue` writes, because they are the same event
        // reached two ways, and an operator correlating a `jti` should not have to know which.
        $this->logger->notice('Access token issued. jti={jti} member={member} expires={expires}', [
            'jti' => $issued->jti->toString(),
            'member' => $issued->subject->toString(),
            'expires' => $issued->expiresAt->format(\DATE_ATOM),
        ]);

        return new JsonResponse([
            'token' => $issued->encoded,
            'expiresAt' => $issued->expiresAt->format(\DATE_ATOM),
        ]);
    }
}
