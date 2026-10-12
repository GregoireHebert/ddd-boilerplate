<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Application\Security\NotAuthenticated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\HttpUtils;

/**
 * One refusal, two representations of it, chosen by `Accept`: a person who is not signed in is sent
 * to the login page, and a client that parses JSON gets the same RFC 9457 problem document as every
 * other failure on this API.
 *
 * This is [ADR 009](docs/ADR/009_one-url-two-representations.md) rule 11. The statuses are
 * [ADR 008](docs/ADR/008_security.md) rule 11's and none of them moves.
 *
 * ## What it looked like before ADR 008
 *
 * The API firewall had no entry point at all, which is a supported state rather than a mistake:
 * `access_token` is not an entry point, so Symfony's `ExceptionListener` fell through to
 * `throwUnauthorizedException()` and raised `HttpException(401, $authException->getMessage())` with
 * no headers. API Platform rendered that, so the status and the media type were already right and
 * the two published members were both wrong:
 *
 * ```
 * "type":   "/errors/401"                                        instead of a tag URI
 * "detail": "Full authentication is required to access this resource."   an internal English string
 * ```
 *
 * Every other failure on this API answers `type: tag:gheb.dev,2026:<code>` and a `detail` rendered
 * from the catalogue in the negotiated locale, which is
 * {@see \ApiPlatform\Provider\ErrorTypingProvider}'s whole job. A client
 * routing on `type` therefore needed a second rule for the first failure it will ever see, and
 * `detail` was a sentence in English whatever `Accept-Language` said.
 *
 * ## Why this class reads the request format, when rule 2 says only one class may
 *
 * [ADR 009](docs/ADR/009_one-url-two-representations.md) rule 2 reserves
 * `Request::getRequestFormat()` for {@see \ApiPlatform\State\RenderingProcessor},
 * because a provider, a handler or a read model that branches on the format has become two of
 * itself wearing one class name. Rule 11 then asks the entry point to negotiate, and the two rules
 * do not collide, for a reason worth writing down rather than leaving to be noticed:
 *
 * **a refusal has no provider, no handler and no read model to fork.** Nothing has run when this is
 * called. `ContentNegotiationProvider` has not run either, because the firewall answers before
 * routing hands anything to API Platform, so there is no negotiated format to read and nobody else
 * who could have read it. Rule 2's seam is the read path, and this is not on it. There is still
 * exactly one place per path that asks what the caller wants, which is what the rule protects.
 *
 * ## The browser is redirected, and it is not told anything
 *
 * A person who is not signed in wants the login page, not a document describing their predicament.
 * `ExceptionListener::startAuthentication()` has already stored the target path by the time this
 * runs, on a firewall that is not `stateless`, so the redirect back after signing in is the
 * framework's and not ours.
 *
 * `HttpUtils::createRedirectResponse()` is what Symfony's own `FormAuthenticationEntryPoint` uses,
 * and the argument is the route **name** rather than the path, so moving `/login` moves this with
 * it.
 *
 * ## The client gets a problem document, and this class does not write it
 *
 * An entry point is allowed to build a `Response` and most of them do. Building one here would mean
 * writing an RFC 9457 document by hand, choosing between `application/problem+json` and
 * `application/ld+json` by hand, and owning a second opinion about what a failure looks like. That
 * is the defect this class exists to remove, so it does the opposite: it raises the failure the
 * tree already declares and lets the pipeline that renders the other twenty render this one.
 * `ExceptionListener` catches whatever `start()` throws and sets it as the event's throwable, which
 * is exactly the hand-over we want.
 *
 * Everything downstream then happens for free, and none of it is repeated here:
 *
 * - `exception_to_status` maps {@see \App\Shared\Domain\Exception\UnauthenticatedThrowable} to
 *   `401`, so the status is declared in the same block as the other seven markers;
 * - `ErrorTypingProvider` unwraps the `HttpException`, finds a `TranslatableThrowable` behind it
 *   and publishes `tag:gheb.dev,2026:shared.not-authenticated`, the rendered sentence, and the
 *   `instance` of what was asked for. That is the same unwrapping it already does for
 *   `#[WithHttpStatus]`, not a new path;
 * - {@see \Translation\FailureMessages} renders it, and stays the only object
 *   in the application that calls `trans()`.
 *
 * ## `POST /rpc` is the third column and needs nothing here
 *
 * [ADR 006](docs/ADR/006_rest-vs-rpc.md) puts every failure that endpoint decides inside a `200`,
 * and ADR 008 amends it by exactly one case: **a `401` leaves `POST /rpc` in the status line,
 * because it is not that endpoint deciding.** RFC 9110 requires a challenge and a JSON-RPC response
 * object has nowhere to carry one, which is why {@see \ApiPlatform\JsonRpc\ErrorCode}
 * declares no case for it. A JSON-RPC caller sends `Accept: application/json` and therefore takes
 * the same branch as any other client, which is the whole of the third column.
 *
 * ## The same answer whether the credential was missing or wrong
 *
 * `start()` is called when there was no credential. `onAuthenticationFailure()` is called when there
 * was one and it did not verify, and it is the commoner of the two: a token that has expired, been
 * revoked or been copied badly. Symfony's `AccessTokenAuthenticator` answers that case itself, with
 * `new Response(null, 401, ['WWW-Authenticate' => ...])`, an **empty body labelled `text/html`**,
 * unless a `failure_handler` is wired. So the published OpenAPI description promised a problem
 * document and the API returned nothing at all, on the one failure every client meets first.
 *
 * Wiring this class as both is what makes the two agree, and it is one object rather than two on
 * purpose: "the same refusal" is easier to keep true when there is one copy of it.
 *
 * ## The challenge
 *
 * RFC 9110 §15.5.2 requires a `401` to carry `WWW-Authenticate`, which is the one thing the refusal
 * table says this answer teaches: that authenticating is the next move.
 * {@see \ApiPlatform\State\Util\HttpResponseHeadersTrait} merges a Symfony `HttpException`'s headers
 * into the response, and a `TranslatableThrowable` is not one, so the wrapper below exists for that
 * header and for nothing else.
 *
 * `Bearer` with no `realm` because the firewall extracts a bearer token and there is nothing to
 * protect a realm from: one credential store, one audience. An identity server would put its own
 * parameters here, and UMA 2.0 would put a permission ticket and an `as_uri`, which ADR 008 names
 * as the one place a policy subsystem is visible from outside.
 *
 * ## We still do not re-render an authentication failure
 *
 * ADR 007 left a debt here and ADR 008 paid it by refusing the obvious thing. Symfony's
 * `AuthenticationException` carries `getMessageKey()` and `getMessageData()`, translated from the
 * `security` domain in fifty-seven locales, and copying any of that into `failures` would be the
 * second English that ADR 007 spent a section removing everywhere else. So the exception is read
 * for nothing at all, on either entry: the caller is told the one thing a refusal may tell them,
 * which is `shared.not-authenticated`, "You need to sign in to do that.", with no parameters. Which
 * credential was missing, malformed or expired is the operator's business, and the firewall has
 * already logged it next to the exception.
 *
 * This is the same argument ADR 008 makes for the `403` one row below it in the refusal table: the
 * permission that was refused does not travel either, because a failure can only leak what it hands
 * over.
 */
final readonly class SecurityEntryPoint implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    private const string CHALLENGE = 'Bearer';

    /**
     * The route, not the path. {@see \App\Ui\Web\LoginFormController} declares it.
     */
    private const string LOGIN_ROUTE = 'app_login';

    /**
     * API Platform's format name and Symfony's, which are the same one. `text/html` is mapped onto
     * it by `Request`'s own format list, so nothing here parses a media type.
     */
    private const string BROWSER_FORMAT = 'html';

    public function __construct(private HttpUtils $httpUtils)
    {
    }

    /**
     * No credential was presented. `$authException` is deliberately unused; see the docblock.
     */
    #[\Override]
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->refuse($request);
    }

    /**
     * A credential was presented and did not verify. `$exception` is deliberately unused, for the
     * same reason, and the answer is deliberately identical: a caller who learns which of the two
     * happened learns something about a credential they do not hold.
     */
    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->refuse($request);
    }

    /**
     * Returns for a browser and throws for everybody else, which looks lopsided and is the point:
     * the redirect is a response this class owns, and the problem document is one it refuses to
     * own. Both callers hand a throwable onward the same way, Symfony's `ExceptionListener` from
     * `start()` and the kernel from `onAuthenticationFailure()`, so the document is rendered by the
     * pipeline that renders every other failure.
     */
    private function refuse(Request $request): Response
    {
        // `null` as the default rather than the signature's own `'html'`: a request with no usable
        // `Accept` (`*/*`, which is curl's) is a client, not a browser, and must not be redirected
        // into a login page it cannot read.
        if (self::BROWSER_FORMAT === $request->getPreferredFormat(null)) {
            $redirect = $this->httpUtils->createRedirectResponse($request, self::LOGIN_ROUTE);

            // ADR 009 rule 1, on the one response in the tree that negotiates and then has nobody
            // downstream to say so. `api_platform.defaults.vary` puts `Accept` on everything
            // `AddHeadersProcessor` touches, and this redirect is built by the firewall before
            // routing, so no processor ever sees it. Measured: `GET /me/badges` anonymous answered
            // `302` with `Vary: Accept-Language` and nothing else, while the same URL and the same
            // state answered a `401` problem document to `Accept: application/ld+json`. A shared
            // cache keyed on the URL alone would have served the login redirect to the client.
            $redirect->setVary('Accept', false);

            return $redirect;
        }

        $failure = NotAuthenticated::required();

        throw new HttpException(
            Response::HTTP_UNAUTHORIZED,
            $failure->getMessage(),
            $failure,
            ['WWW-Authenticate' => self::CHALLENGE],
        );
    }
}
