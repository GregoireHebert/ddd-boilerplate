<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use App\Shared\Domain\Exception\BadRequestThrowable;
use App\Shared\Domain\Exception\ConflictThrowable;
use App\Shared\Domain\Exception\DomainNotFoundThrowable;
use App\Shared\Domain\Exception\ForbiddenThrowable;
use App\Shared\Domain\Exception\PreconditionFailedThrowable;
use App\Shared\Domain\Exception\PreconditionRequiredThrowable;
use App\Shared\Domain\Exception\DomainUnprocessableThrowable;
use App\Shared\Domain\Exception\FailedDependencyThrowable;
use App\Shared\Domain\Exception\UnauthenticatedThrowable;
use App\Shared\Domain\Exception\UnprocessablePayloadThrowable;
use ApiPlatform\Validator\Exception\ValidationException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;

/*
 * ADR 009 rule 11, the HTML column, and the one thing `handle_symfony_errors` below does not buy.
 *
 * ## Two listeners decide a status and only one of them reads `exception_to_status`
 *
 * `ApiPlatform\Symfony\EventListener\ErrorListener::getStatusCode()` walks `exception_to_status`
 * and is reached only when the negotiated format is **not** `html`
 * (`api-platform/symfony/EventListener/ErrorListener.php:82`). A browser's refusal falls back to
 * Symfony's own `error_controller`, and the status there comes from
 * `Symfony\Component\HttpKernel\EventListener\ErrorListener::logKernelException()`, which walks a
 * different list: `framework.exceptions` (`http-kernel/EventListener/ErrorListener.php:54`).
 *
 * So turning `handle_symfony_errors` on fixed half of this and the measurement said so. A session
 * whose member row is gone, asking `GET /me/badges`:
 *
 * | `Accept`                | before      | with the flag | with this block |
 * |-------------------------|-------------|---------------|-----------------|
 * | `application/ld+json`   | `500`       | `404`         | `404`           |
 * | `text/html`             | `500`       | `500`         | `404`           |
 *
 * A person browsing got the one page that says nothing and means "we broke", for a member who had
 * simply been erased, while the client one header over was told the truth in their own language.
 * Rule 11 asks for two representations of **one** refusal, and a refusal that is a `404` to a
 * machine and a `500` to a human is two refusals.
 *
 * ## Why this is a variable rather than a second list
 *
 * Because a second list is a second answer waiting to disagree, which is the argument ADR 005 makes
 * about option A's constraint arguments and ADR 007 makes about the failure catalogue. The eight
 * entries are declared once, here, and spread into both consumers below.
 *
 * **The four vendor entries are deliberately not in it**, and the line is not arbitrary: these eight
 * are the marker interfaces in `Shared/Domain/Exception/`, which is to say the ones *our* code
 * throws and therefore the only ones a Twig page can raise. `SerializerExceptionInterface`,
 * `OptimisticLockException`, `ValidationException` and `ExtraAttributesException` are raised inside
 * API Platform's own pipeline, which no `Ui/Web` route enters.
 *
 * Keeping them out is also what protects the `422`. Symfony's listener does not merely *read* its
 * list, it **replaces the throwable** with an `HttpException` carrying the mapped status
 * (`:60`), and the `422` is the one answer in this application assembled from the exception itself:
 * `ValidationException::getId()` composes the problem IRI out of the violation codes, and
 * `ValidationExceptionFactory` builds the `violations` array from the object. An entry for
 * `ValidationException` here would hand that pipeline a wrapper instead. Measured before and after,
 * on both of ADR 005's designs, and both still answer the same document:
 * `/validation_errors/matching.cannot-like-self` with `code: matching.cannot-like-self`, and option
 * A's two Symfony constraint UUIDs.
 *
 * `UnprocessablePayloadThrowable` **is** in the list, and that is not the same risk: it is what
 * `InvalidPayload` implements, and `InvalidPayload` is converted to a `ValidationException` by the
 * state processors before anything reaches a kernel listener. On a Twig route, where nothing
 * converts it, this entry is the only thing between a form failure and a `500`.
 *
 * @var array<class-string, int<100, 599>>
 */
$declaredFailures = [
    // ADR 008's two refusals, and the order is the order a caller meets them.
    //
    // In the common case neither of these is ever constructed: the firewall answers the 401 before
    // a line of ours runs. What raises `UnauthenticatedThrowable` is `Actor::subject()` on a path
    // that let an anonymous caller through and then reached for a subject, which is the case worth
    // having an exception for.
    //
    // Note which refusals are *not* here, because that is the whole of ADR 008's four-question
    // split: "this is not yours" is a `DomainNotFoundThrowable` below, and "your plan does not
    // include it" is a `DomainUnprocessableThrowable`. Only a policy refusal, decided without
    // reading anything of ours, is a 403.
    UnauthenticatedThrowable::class => Response::HTTP_UNAUTHORIZED,
    ForbiddenThrowable::class => Response::HTTP_FORBIDDEN,

    BadRequestThrowable::class => Response::HTTP_BAD_REQUEST,
    DomainNotFoundThrowable::class => Response::HTTP_NOT_FOUND,
    ConflictThrowable::class => Response::HTTP_CONFLICT,

    // `BadRequestThrowable` above is "I could not work out what you asked" (ADR 004's query
    // parameters), `DomainUnprocessableThrowable` below is "your payload was fine, the world is not
    // in the state you assumed", and this one is "the payload is understandable, and its content is
    // unacceptable". Only this one can answer with a list, because only this one is decided without
    // asking the store anything.
    //
    // One entry covers both shapes: `Shared\Application\Validation\InvalidPayload` carries the
    // list and implements the same marker as each failure inside it, so five bad fields or one bad
    // field get the same status.
    UnprocessablePayloadThrowable::class => Response::HTTP_UNPROCESSABLE_ENTITY,

    DomainUnprocessableThrowable::class => Response::HTTP_UNPROCESSABLE_ENTITY,
    FailedDependencyThrowable::class => Response::HTTP_FAILED_DEPENDENCY,

    // ADR 003's optimistic locking, surfaced as a conditional request on
    // `PATCH /subscriptions/{subscriptionId}`. Two markers because two statuses, and
    // `PreconditionRequiredThrowable` carries the argument for not flattening them: a `412` tells a
    // caller to re-read and retry, a `428` tells them their client never sends the header at all,
    // and a client that gets the first answer to the second question retries forever.
    //
    // They belong in this variable rather than beside the four vendor entries below, because the
    // line that variable draws is "markers in Shared/Domain/Exception/, which our code throws".
    // These are two of those. No `Ui/Web` route raises one today, since ADR 009 rule 6 keeps every
    // write off the HTML negotiation, so `framework.exceptions` gains two entries nothing currently
    // reaches; that is the cheaper mistake. A second list holding only some of our markers is the
    // disagreement this variable was created to prevent, and Symfony's listener replacing the
    // throwable costs nothing here: neither of these answers is assembled from the exception, which
    // is the one thing that kept `ValidationException` out.
    PreconditionFailedThrowable::class => Response::HTTP_PRECONDITION_FAILED,
    PreconditionRequiredThrowable::class => Response::HTTP_PRECONDITION_REQUIRED,
];

return App::config([
    /*
     * Declared here, beside the list it is derived from, for the reason
     * `config/packages/security.php` declares `framework.csrf_protection` beside the firewall that
     * requires it: the requirement comes from this file, and a reader asking "why does a Twig page
     * answer 404" should find the answer next to the question.
     *
     * `status_code` only. `log_level` and `log_channel` are the other two keys this block takes and
     * neither is ours to set here: a `404` is already logged at the level Symfony picks for a
     * `4xx`, and a channel would be a routing decision about `monolog` taken in the wrong file.
     */
    'framework' => [
        'exceptions' => \array_map(
            static fn (int $status): array => ['status_code' => $status],
            $declaredFailures,
        ),
    ],
    'api_platform' => [
        'title' => 'My DDD CQS API',
        'version' => '1.0.0',

        // `title` and `version` alone leave `info.description` empty, and an empty `info.description`
        // is the first thing a reader of the generated document meets. What follows is the part of
        // ADR 004 and ADR 005 a *caller* needs, and only that part: which status means which kind
        // of mistake, the two pagination contracts they have to tell apart, what a repeated write
        // does, and the warning on the one endpoint that serves a projection. None of the
        // reasoning, which is in `docs/ADR/` for the people writing this side of the wire.
        'description' => <<<'MARKDOWN'
            An API built on the DDD + CQS boilerplate. Reads answer from read models; writes put a
            command on a bus and tell you what the domain decided.

            **Edit this as you add endpoints.** What follows is the part of ADR 004, ADR 005 and
            ADR 006 that is true of any API built this way, and therefore safe to keep; the
            endpoint-specific paragraphs the reference application carried here have been removed
            rather than left to go stale. None of the *reasoning* belongs here, only what a caller
            needs: the reasoning is in `docs/ADR/`.

            ## When something is wrong with what you sent

            Three answers, and which one you get tells you what kind of mistake it was.

            **`400`** means the request could not be worked out at all: a filter value that will not
            parse, a `page` below 1, a corrupt cursor, an identifier in the path or query string that
            is not a ULID. Nothing is silently defaulted and nothing widens to "everything".

            **`422`** means the request was understood and its content is unacceptable. Every `422`
            carries a `violations` array, and every entry names a `propertyPath` and a `code`, so
            one branch handles all of them. Fields are checked independently, so a body wrong in
            three places comes back once listing three violations; a rule *between* two fields can
            only be checked after both have parsed, so it arrives on its own, in the same shape,
            with an empty `propertyPath` because it belongs to neither field.

            **`404`, `409`, `424`** mean your payload was fine and the world is not in the state your
            request assumed: nobody holds that identifier, that name is taken, that resource is
            unreachable. These are never lists. Telling them apart from a `422` requires asking the
            database, which is exactly why they are a different answer.

            **`401` and `403`** are about you rather than about your request: `401` means
            authenticate and try again, `403` means you may not do this at all. A refusal about
            somebody else's object answers `404` instead, deliberately, so that an identifier you
            are not allowed to see is indistinguishable from one that does not exist.

            Errors are RFC 9457 problem documents (`application/problem+json`, or
            `application/ld+json` when you asked for JSON-LD).

            ## Writes

            `PATCH` takes `application/merge-patch+json`. A write that starts something longer than
            the request answers `202` rather than `204`.

            Repeating a write that has already happened is not an error where the intention is
            already satisfied. Where the repeat would overwrite something a reader may be holding,
            it is a `409`.

            ## Two ways to paginate, and how to tell which you were given

            Each endpoint offers exactly one, and says so in its own description. They are not
            interchangeable, and no endpoint accepts both vocabularies.

            **Page numbers.** Ordinary JSON-LD collections: `page`, `itemsPerPage`, a `view` with
            `first`/`last`/`next`/`previous`. Asking for page 3 twice returns the same rows. An
            endpoint may also let you choose what counting costs, with `?total=`, in which case the
            default is `none` and `totalItems` is absent until you ask for it.

            **Cursors.** These are windows onto a moving set, so they are modelled as a single
            resource holding `members` and a `next`, not as a collection. Pass the `next` you were
            handed back as `after`. It is opaque: not a page number, not a date, not something to
            construct. `next` is `null` when the store had nothing left. There is no total, no last
            page, and no way to ask for "page 7"; a read model cannot answer that without counting a
            set that changes while you walk it.

            Every window parameter clamps rather than refuses: `size` or `itemsPerPage` above the
            documented maximum returns the maximum, and is not an error.

            ## Identifiers and freshness

            Every identifier is a ULID, rendered as its 26-character canonical string.

            An endpoint serving a projection of a third-party system rather than something this API
            owns says so and carries a `refreshedAt`. Read it before trusting the rest of the row.
            MARKDOWN,

        'show_webby' => false,
        'path_segment_name_generator' => 'api_platform.path_segment_name_generator.dash',

        'resource_class_directories' => [
            '%kernel.project_dir%/src/Ui/Api/Resources',
            // `JsonRpcResource` moved in with the protocol it declares, so the RPC endpoint's one
            // resource class is scanned out of `Shared/Infrastructure` rather than out of `Ui`.
            '%kernel.project_dir%/src/Shared/Infrastructure/ApiPlatform/JsonRpc',
        ],

        'defaults' => [
            // ADR 009 rule 8, and this line is the first thing that rule broke.
            //
            // `stateless` is not a statement about the API. It becomes the `_stateless` request
            // attribute (`api-platform/symfony/Routing/ApiLoader.php:117`), which Symfony's
            // `AbstractSessionListener` reads to assert that **this response did not depend on a
            // session**: it throws `UnexpectedSessionUsageException` in debug and logs in
            // production. It was true while a path list kept every API route on a `stateless`
            // firewall that could not read a session even if one arrived.
            //
            // It stopped being true the moment the firewall started matching on the credential. A
            // reader signed in with a cookie reaches `GET /badge-models` for its `text/html`
            // representation, the `web` firewall reads their session to answer the gate, and the
            // response is now a function of that session. Measured before this line changed: `500`,
            // "Session was used while the request was declared stateless", on both the anonymous
            // and the signed-in request, which is the HTML half of ADR 009 failing one layer past
            // the firewall that was supposed to have unblocked it.
            //
            // **It is `false` for every operation rather than for the ones that declare `html`**,
            // and that distinction is the whole lesson of rule 8 arriving a second time. The
            // session is used because of the *credential*, not because of the representation: a
            // `fetch()` carrying a cookie and asking for `application/ld+json` uses it exactly as
            // the page does. Flipping only the operations with an HTML representation would be the
            // path list again, written in a different file.
            //
            // Nothing is lost on the bearer-token path, where no session is ever started and the
            // listener does nothing. What is gained on the cookie path is the listener's other
            // half: a response that did touch the session gets `Cache-Control: private`, which is
            // the correct answer for a per-reader document and which `_stateless` was previously
            // suppressing.
            'stateless' => false,
            'cache_headers' => [
                'vary' => [
                    // ADR 009 rule 1, and the only entry in this list that is also set somewhere
                    // else. `RespondProcessor` writes `Vary: Accept` while *building* a response,
                    // in `ApiPlatform\State\Util\HttpResponseHeadersTrait`, and that is the one step
                    // a rendered representation skips: `RenderingProcessor` hands a `Response` down
                    // the chain and every processor below returns it untouched. So the JSON path got
                    // the header from the vendor and the HTML and SVG paths got nothing, on the
                    // exact design whose point is that one URL answers several bodies depending on
                    // this header.
                    //
                    // Declaring it here fixes it for every format at once, including the fourth one
                    // nobody has written: `AddHeadersProcessor` decorates the *return* value, so it
                    // reaches a rendered `Response` as readily as a serialized string, and it
                    // `array_diff`s this list against what is already on the response, so the JSON
                    // path does not end up saying `Accept` twice. The alternative was writing the
                    // header inside the renderer, once per format, which is the copy that drifts the
                    // day this list changes.
                    'Accept',
                    'Content-Type',
                    'Authorization',
                    'Origin',
                    // ADR 007, and it is not optional the moment a second locale ships.
                    // `set_locale_from_accept_language` makes the response body depend on this
                    // header; `api-platform/http-cache` is installed and a shared cache that has
                    // not been told so will store the French answer under a key that does not
                    // mention the language and hand it to the next English caller.
                    'Accept-Language',
                ],
            ],
            'extra_properties' => [
                // `SwaggerUiProvider` serves the documentation page for ANY operation whose request
                // format is `html`, and it is registered because `enable_re_doc` is true. Our
                // resources opt out so that `Accept: text/html` reaches `RenderingProcessor`. The
                // documentation itself is unaffected: that provider returns early when the
                // operation's class is `OpenApi`, which is the route `/docs` goes through.
                '_api_disable_swagger_provider' => true,
                'standard_put' => true,
                'rfc_7807_compliant_errors' => true,
            ],
            'collect_denormalization_errors' => true,
            'normalization_context' => ['skip_null_values' => false],
        ],

        'doctrine' => false,
        'eager_loading' => [
            'force_eager' => false,
        ],

        'enable_swagger' => true,
        'enable_swagger_ui' => false,
        'enable_re_doc' => true,

        'openapi' => [
            'contact' => [
                'name' => 'Grégoire Hébert',
                'url' => 'https://knot.gheb.dev',
                'email' => 'contact@gheb.dev',
            ],
        ],

        // These are the *response* formats every operation advertises, and each entry here mints a
        // JSON Schema per resource. Two entries used to be wrong about this API rather than merely
        // verbose:
        //
        //  - `application/merge-patch+json` is a request format for PATCH, so listing it here
        //    documented it as a 200 response body on every GET, which is not something this API will
        //    ever send. It belongs in `patch_formats`, below, where ADR 005 put it back once there
        //    was a PATCH to need it;
        //  - `jsonproblem` belongs in `error_formats` below, where it already is. Listed here as
        //    well, it generated a `CandidateFeed.jsonproblem`, a `Like.jsonproblem` and three more
        //    like them: a "problem" flavour of each resource schema, offered as a 200 response,
        //    describing a document nothing produces.
        //
        // Dropping `jsonproblem` here is the one change in this pass that a client can observe:
        // `Accept: application/problem+json` on its own is now a 406 rather than a 200. What it used
        // to return was a successful resource body labelled as a problem document, so the 406 is the
        // more honest answer, and `Accept: application/problem+json, application/json` still works.
        // Errors are unaffected; they are rendered from `error_formats`, which still lists it.
        // `html` is ADR 009's whole configuration change, and the name is API Platform's own:
        // `Bundle/DependencyInjection/Configuration.php:208` already declares
        // `'html' => ['mime_types' => ['text/html']]` among the default `docs_formats`. Nothing is
        // invented here; a format is promoted from the documentation's list to the resources' list,
        // which is what makes `Accept: text/html` a representation to be rendered rather than a 406
        // raised by `ContentNegotiationProvider` before a line of ours runs.
        //
        // **`html` is deliberately NOT in this list**, and the first draft of ADR 009 had it here.
        // Two things go wrong when it is global. `SwaggerUiProvider` decorates the read provider
        // and hijacks any operation whose request format is `html`, and it is registered whenever
        // `enable_swagger_ui || enable_re_doc || enable_scalar` is true
        // (`ApiPlatformExtension.php:674`); `enable_re_doc` is `true` above, so `GET /members` with
        // `Accept: text/html` answered the ReDoc page rather than a representation. And every
        // resource gained a `<Resource>.html` schema in the OpenAPI export, documenting a
        // `text/html` response with a JSON body, on a document CLAUDE.md calls a deliverable.
        //
        // So an operation that has a page declares `'html' => ['text/html']` in its own
        // `outputFormats:`, which is ADR 009 rule 3 as written rather than a workaround, and every
        // other operation keeps answering `406` from `ContentNegotiationProvider`. What renders is
        // `Shared/Infrastructure/ApiPlatform/State/RenderingProcessor`, the one class in the tree
        // allowed to read the request format; a missing template there is the same 406.
        'formats' => [
            'jsonld' => ['application/ld+json'],
            'json' => ['application/json'],
        ],

        // The request format for `PATCH /members/{memberId}`, and it is back because the comment
        // above no longer holds. It used to say "there is no PATCH operation in the tree", which was
        // true when ADR 004 left the API read-only; ADR 005 added one, and without this a PATCH
        // answers 415 and names a media type the application does not advertise.
        //
        // Still not in `formats`, and the distinction the comment above draws is why: merge-patch is
        // something a client *sends*, never something this API returns, so listing it as a response
        // format would document a 200 body that nothing produces and mint a JSON Schema per resource
        // to describe it.
        'patch_formats' => [
            'json' => ['application/merge-patch+json'],
        ],

        // ADR 009 rule 11, the half of it that was declared and not reachable, and it is one
        // boolean.
        //
        // Rule 11 says a refusal has two representations chosen by `Accept`, and measuring it found
        // that true of every URL API Platform owns and false of every URL it does not. Anonymous
        // `GET /me/badges` with `Accept: application/ld+json` answered:
        //
        //     {"type":"https://tools.ietf.org/html/rfc2616#section-10",
        //      "title":"An error occurred",
        //      "detail":"shared.not-authenticated",
        //      "class":"Symfony\\Component\\HttpKernel\\Exception\\HttpException", "trace":[...]}
        //
        // while `GET /members/{memberId}/badges`, the same refusal one route over, answered the
        // document rule 11 asks for. Three of the five members were wrong and the fourth was worse
        // than wrong: `detail` was the **raw declared code**, which is the one string ADR 007 exists
        // to keep out of a reader's hands. `type` pointed at a dead RFC 2616 anchor, so a client
        // routing on it needed a second rule for refusals that happen to come from a Twig page, and
        // `trace` published our file paths to anybody who asked for JSON.
        //
        // The cause is that `ApiPlatform\Symfony\EventListener\ExceptionListener::onKernelException()`
        // returns early unless the request carries `_api_respond`, which no `Ui/Web` route does, so
        // `Symfony\Component\HttpKernel\EventListener\ErrorListener` rendered a `FlattenException`
        // instead. **`handle_symfony_errors` is the vendor's own name for that early return**
        // (`api-platform/symfony/EventListener/ExceptionListener.php:39`), and turning it off hands
        // every kernel exception to the same `ErrorListener` the API already uses.
        //
        // What that buys, in one sentence each: `ErrorTypingProvider` now runs on a Twig route, so a
        // refusal there publishes `tag:gheb.dev,2026:<code>`, a `detail` rendered from the catalogue
        // in the negotiated locale, a `title`, and the `instance` that says which request it was.
        // `exception_to_status` now applies there too, which is the other half: a
        // `DomainNotFoundThrowable` escaping a page used to be a `500`, because nothing mapped it,
        // against the `404` its twin answered.
        //
        // **The browser is untouched, and the line below is why.** `ErrorListener::duplicateRequest()`
        // falls back to Symfony's `error_controller` when the negotiated format is `html`, so a
        // person still gets `templates/bundles/TwigBundle/Exception/error{403,404}.html.twig`. The
        // flag widens *which requests are offered the negotiation*, not what the negotiation decides.
        'handle_symfony_errors' => true,

        // ADR 009 rule 11's browser column, and the whole of its mechanism.
        //
        // `ApiPlatform\Symfony\EventListener\ErrorListener::duplicateRequest()` hands a failure to
        // Symfony's own `error_controller` on exactly one condition: `'html' === $format`, where
        // `$format` is this list negotiated against `Accept`. That is the switch that makes a
        // browser's `403` and `404` render
        // `templates/bundles/TwigBundle/Exception/error{403,404}.html.twig` instead of a problem
        // document, and there is no other one. A client asking for JSON-LD or problem+json never
        // matches `html` and keeps the RFC 9457 document it has always had.
        //
        // **READ THIS BEFORE GIVING A WRITE AN HTML REPRESENTATION, BECAUSE THIS LINE BREAKS THAT
        // DAY.** The switch is status-agnostic: it cannot distinguish a navigation failure from a
        // `422`. A `422` is the one refusal that must come back to the form with the fields still
        // holding what the person typed, and an error page throws that away. What keeps the two
        // apart is not this block, it is ADR 009 rule 6: no write operation has an HTML
        // representation, none declares `html` in its `outputFormats`, and every form submits by
        // `fetch()` asking for `application/ld+json`. So a `422` is never negotiated to `html` and
        // never reaches the error controller. Declare `html` on a write and it will be, silently,
        // with no error and no failing check to notice it.
        //
        // The vendor offers no per-status variant of this, and writing one means our own listener
        // threaded into the vendor's chain by a priority number, which ADR 009 already refused once
        // when it chose a processor over a `kernel.view` listener.
        //
        // `html` stays out of `formats` above for the SwaggerUiProvider reason written there; it is
        // in *this* list only, so it is available to a failure and to nothing else.
        'error_formats' => [
            'jsonproblem' => ['application/problem+json'],
            'jsonld' => ['application/ld+json'],
            'html' => ['text/html'],
        ],

        // The four below are the vendor's, raised inside API Platform's own pipeline and
        // therefore never by a `Ui/Web` route; the eight spread after them are ours, declared at the
        // top of this file because Symfony's error listener needs the same eight in its own shape.
        // See that declaration for why the split falls exactly there.
        //
        // The order is the order they are tested in, first match wins, and it is unchanged in every
        // way that can be observed: moving `ExtraAttributesException` from between `ForbiddenThrowable`
        // and `BadRequestThrowable` to beside the other serializer entry cannot change an answer,
        // because no exception can implement both it and one of ADR 008's refusals.
        'exception_to_status' => [
            SerializerExceptionInterface::class => Response::HTTP_BAD_REQUEST,
            OptimisticLockException::class => Response::HTTP_CONFLICT,
            ValidationException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
            ExtraAttributesException::class => Response::HTTP_UNPROCESSABLE_ENTITY,

            ...$declaredFailures,
        ],
    ],
]);
