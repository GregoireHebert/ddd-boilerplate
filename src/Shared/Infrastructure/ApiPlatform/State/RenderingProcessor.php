<?php

declare(strict_types=1);

namespace ApiPlatform\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Error as ErrorOperation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Twig\Environment as TwigEnvironment;

/**
 * The representations of a resource that are not serialized documents, and the only class of ours
 * that reads the request format.
 *
 * [ADR 009](docs/ADR/009_one-url-two-representations.md) rule 1 says a resource has one URL and that
 * what a caller reads it as is answered by `Accept`. Rule 2 says exactly one class of ours may look
 * at the answer, because a provider, a handler and a read model that branch on the format have
 * become two of themselves wearing one name. **This is that class**; anything else in the tree
 * calling `Request::getRequestFormat()` is the seam this design exists to avoid.
 *
 * Two formats arrive here, `html` and `svg`, and the second one is why the sentence above says
 * "representations" rather than "pages". Everything else is handed to the serializer chain
 * untouched.
 *
 * A provider decides *what data*; a processor decides *what response*. Nothing about the read
 * changes between a browser and an API client, so nothing about the provider changes: `GET /members`
 * runs one provider, puts one query on the bus and gets one `PageOf<MemberRow>` back whoever is
 * asking. Only the last step parts, and this is the last step.
 *
 * {@see \ApiPlatform\Symfony\Bundle\SwaggerUi\SwaggerUiProcessor} is the vendor's own answer to the
 * same problem and is the model ADR 009 names. Ours is the simpler half of it: the documentation
 * page wants *different* data and therefore needs a provider too, while we want the same data drawn
 * differently and therefore need only this.
 *
 * ## Where it sits in the chain
 *
 * `api_platform.state_processor.main` is decorated by `SerializeProcessor` (priority 200) and
 * `WriteProcessor` (priority 100), and a **lower** decoration priority is the outer wrapper, so the
 * runtime order is write, then serialize, then respond. Priority 150 puts this **inside the write
 * and outside the serialization**, which is the only place it can be:
 *
 * - inside the write, so a `POST` has already run its state processor and we render what the domain
 *   decided rather than what the client sent;
 * - outside the serialization, so we receive the provider's return value **before normalization**.
 *   A template reads `member.handle` off the DTO with every type the query bus just proved. That
 *   was the cost of the serializer-encoder design ADR 009 rejected, and choosing a processor does
 *   not pay it.
 *
 * Rendering returns a `Response`, which `SerializeProcessor` short-circuits on, so the serializer is
 * never asked for an encoder it does not have. That `Response` is handed **down** the chain rather
 * than returned from here, and the next section is the whole of why.
 *
 * ## What a rendered response carries, and why every arm feeds one `$this->inner->process()`
 *
 * The first build returned the `Response` straight out of `process()`, **which skips everything
 * inner to this decorator**. `SerializeProcessor` is a no-op on a `Response` and was not missed.
 * The two below it are not, and between them they own every header that is a fact about the
 * operation rather than about the body:
 *
 * - `RespondProcessor` sets `Vary: Accept`, `X-Content-Type-Options` and `X-Frame-Options`, in
 *   {@see \ApiPlatform\State\Util\HttpResponseHeadersTrait};
 * - `AddHeadersProcessor` applies the configured `cache_headers`, which is the `vary` list in
 *   `config/packages/api_platform.php` and the per-operation `public`.
 *
 * So the one thing ADR 009 newly creates, several bodies at one URL chosen by `Accept`, was the one
 * thing no cache was ever told about, while `api-platform/http-cache` is installed and answering.
 * `GET /badge-models` read as HTML carried `Vary: Accept-Language` and nothing else; the JSON-LD body
 * at the same URL carried five. A shared cache would have handed the page to the next caller asking
 * for JSON-LD, and `GET /members/{memberId}/badge` would have been stored under a URL that mentions
 * no member. [`membership/12`](docs/kanban/membership/12_your-badge-drawn.md) calls that the difference
 * between a correct and a dangerous deployment.
 *
 * **The fix is that every arm of the `match` feeds one `$this->inner->process()` call and no arm
 * returns.** A rendered `Response` then travels the rest of the chain exactly as a serialized string
 * does: `RespondProcessor` returns it untouched rather than building a second one, and
 * `AddHeadersProcessor` decorates the *return* value, so it stamps ours like any other.
 *
 * **Three headers still do not survive that, and the split between them is the whole of the
 * design.** `AddHeadersProcessor` decorates the return value and so reaches anything; the three in
 * the first bullet are written while `RespondProcessor` *builds* a response, and building is exactly
 * the step a ready-made `Response` skips. They are recovered in two different places, for two
 * different reasons:
 *
 * - **`Vary: Accept` is declared once in `defaults.cache_headers.vary`.** It is a fact about the
 *   operation, it already has a configured home next to `Accept-Language`, and from there
 *   `AddHeadersProcessor` applies it to every operation of every format, including the fourth one
 *   nobody has written. It `array_diff`s against what is on the response, so the JSON path does not
 *   end up saying `Accept` twice. **This is the line that fixes the bug**, and it is a line of
 *   config rather than a line of code precisely so that no format can be the one that forgot;
 * - **`X-Content-Type-Options` and `X-Frame-Options` are set in {@see self::representation()}**,
 *   which every arm of the `match` goes through and which is the only place in this file that names
 *   a header. They are two constants with no configuration behind them and nothing to drift from,
 *   so there is no list to declare them in; and leaving them off would mean the page at a URL is
 *   hardened less than the JSON document at the same URL, which is this bug's own shape repeated
 *   one layer down.
 *
 * So a new format is a body and a media type handed to one private method, and everything else
 * about the response is decided by code it does not touch.
 *
 * ### The two alternatives, and why neither is this
 *
 * **Setting all of them here.** A `Vary` and a `Cache-Control` built alongside, in `page()` and in
 * `drawing()` and in whatever the fourth format is: a copy of the vendor's list that drifts the day
 * `cache_headers` changes. That is the opposite of the count this choice was made on even with one
 * shared helper, because the helper would then have to be handed the operation's cache policy and
 * re-derive it. It is also worse in a way that is easy to miss: `Cache-Control` would be decided by
 * the renderer, so `GET /members/{memberId}/badge` could not say `private` in its own declaration,
 * next to the permission that is the reason it is private.
 *
 * **Returning the rendered string and letting `RespondProcessor` build the response.** The tempting
 * one, because it is the only shape that gets all three headers for nothing. It cannot be done, for
 * two independent reasons. This decorator sits **outside** `SerializeProcessor` on purpose, so a
 * string returned from here is a string handed to the serializer, which would normalize the page
 * into a JSON document; moving inside to fix that gives up the un-normalized data the section above
 * argues for, which is the reason this is a processor rather than an encoder at all. And the flag
 * that would have let the string through, `Operation::canSerialize()`, is a getter with no `with`
 * counterpart in the vendor, so the operation cannot be handed down saying "already rendered".
 *
 * ## How a template path is worked out
 *
 * **Every wave 1 page depends on this paragraph, so it is written out rather than left to be read
 * off the code.** ADR 009 rule 7 names the shape,
 * `templates/<context>/<resource>/<operation>.html.twig`, and the three segments come from the
 * operation as follows.
 *
 * `<context>` and `<resource>` come from `Operation::getClass()`, which is the API Platform resource
 * class. It must sit under `App\Ui\Api\Resources\`; the one namespace segment
 * below that is the context, and the class short name with its `Resource` suffix removed is the
 * resource. Both are snake_cased, which is the convention the components under `templates/` already
 * use:
 *
 * - `Resources\Membership\MemberResource`               becomes `membership/member`;
 * - `Resources\Membership\MatchingPreferencesResource`  becomes `membership/matching_preferences`;
 * - `Resources\Matching\CandidateFeedResource`        becomes `matching/candidate_feed`.
 *
 * `<operation>` is `collection` for any operation implementing `CollectionOperationInterface`,
 * `item` for a `Get`, and otherwise the operation class short name snake_cased, so a `Post` renders
 * `post.html.twig`. ADR 009 gives the first two by name (`GET /members` renders
 * `membership/member/collection.html.twig` and `GET /members/{memberId}` renders
 * `membership/member/item.html.twig`); the third exists because rule 6 lets an HTML form perform a
 * `POST` and a `422` has to come back through here and re-draw it.
 *
 * A template receives three variables and no others: `data`, which is whatever the provider or the
 * state processor returned, untouched; `operation`; and `uri_variables`. Rule 12 is why the list is
 * short: anything a page needs that its resource does not hold arrives as an ESI fragment or as an
 * async call to the resource that does hold it, never as a member this class quietly adds.
 *
 * ## A representation does not have to be a page
 *
 * `svg` is the second format this class answers, and ADR 009's section "A representation does not
 * have to be a page, and `image/svg+xml` is the proof" added it here rather than anywhere else, in
 * one sentence worth quoting because it is the whole design:
 *
 * > The rendering processor answers `svg` the same way it answers `html`: it reads the format, and
 * > instead of handing a template to Twig it hands a colour and a motif to `BadgeRenderer`, which
 * > knows nothing about the request. **Rule 2 was always about the reading rather than about
 * > HTML**, and a third format is the first thing to test that.
 *
 * So the branch is here and there is no second class. What decides whether an operation can answer
 * it is {@see Drawable}, implemented by the representation rather than named here: this class asks
 * `$data instanceof Drawable` and never which resource it is holding, exactly as the `html` branch
 * asks the loader whether a template exists and never which page it is. The fourth format, if one
 * ever comes, is an arm of the same `match` and an interface or a template convention beside these
 * two.
 *
 * `BadgeRenderer::SIZE_LG` is the size, for the reason `BadgeDesignController` gives
 * about the one other standalone drawing in the tree: a document with no page around it has nothing
 * to be sized by, so it is drawn at the largest size the design system names and scaled down by
 * whatever `<img>` is pointing at it. There is deliberately no `?size=` parameter; a caller who
 * wants the drawing inside a page of ours gets it inline from `components/_badge.html.twig`, at the
 * size that page's card is.
 *
 * ## Why a missing template, or an undrawable resource, is a 406
 *
 * ADR 009 rule 3: a media type an operation does not have is a `406`, never a degraded rendering of
 * one it does have. Not a `500`, and above all not a JSON dump inside a `<pre>`, which is the kind
 * of helpfulness that ships and then stays. `NotAcceptableHttpException` is the exception the
 * framework itself raises for this condition, in
 * {@see \ApiPlatform\Metadata\Util\ContentNegotiationTrait}, so the answers a caller can meet are
 * one answer:
 *
 * - **negotiated away**, when the operation declares `outputFormats:` without `html` or `svg`.
 *   Raised before any code of ours runs, by `ContentNegotiationProvider`. This is the readable way
 *   to say an operation has no such representation, and ADR 009's checklist prefers it;
 * - **no template**, raised here. Same status, and it means nobody has written the page yet;
 * - **nothing to draw**, raised here too, when an operation declares `svg` and hands back something
 *   that is not {@see Drawable}. That one is a declaration and an implementation disagreeing, which
 *   is a mistake of ours rather than of the caller's; it is still a `406` because the caller's
 *   question ("can I have this as an image") has the same true answer either way, and the log is
 *   where the difference belongs.
 *
 * **Both of those sentences are English prose and neither declares a `FailureCode`, which is
 * [ADR 007](docs/ADR/007_translation.md) rule 17 and not an oversight.** Rule 1 of that ADR says no
 * exception carries a sentence; rule 17 exempts content negotiation, and only content negotiation,
 * on the test it writes down: is there a person who will change what they typed because of this
 * message. A `422` on a handle has one. A `406` is read by whoever wrote the `Accept` header,
 * which is a client developer with a terminal open, so a French translation of it would be a French
 * sentence in a log nobody reads and a declared code would put a case in an enum no human-facing
 * surface will ever render. `app:messages` therefore has nothing to check here, by design.
 *
 * An error operation is handed straight on, and that guard is load-bearing rather than defensive.
 * `error_formats` **does** carry `html`, because ADR 009 rule 11 sends a browser's `403` and `404`
 * to Symfony's own error templates, so a failure negotiated against a browser's `Accept` arrives
 * here with the request format already reading `html`. Rendering it as a resource page would look
 * for `templates/<context>/<resource>/item.html.twig` for a resource nobody asked for and raise a
 * `406` in the middle of answering a `404`, which is a loop rather than an answer. `svg` is in no
 * error format list and never will be: an error is not a drawing.
 *
 * @implements ProcessorInterface<mixed, mixed>
 *
 * @author Grégoire Hébert <contact@gheb.dev>
 */
#[AsDecorator(decorates: 'api_platform.state_processor.main', priority: 150)]
final readonly class RenderingProcessor implements ProcessorInterface
{
    /**
     * The request format API Platform already maps onto `text/html`; the name is the vendor's, not
     * ours. An operation opts in with `outputFormats:`, never the global `formats` list, which is
     * ADR 009's correction: a global entry hands every `text/html` request to `SwaggerUiProvider`.
     */
    private const string HTML = 'html';

    /**
     * The two values {@see \ApiPlatform\State\Util\HttpResponseHeadersTrait} writes on every
     * response it builds, named here because a rendered `Response` is not one it builds.
     * {@see self::representation()} says why these two are copied and `Vary` is not.
     */
    private const string NO_SNIFF = 'nosniff';

    private const string NO_FRAME = 'deny';

    private const string RESOURCE_NAMESPACE = 'App\\Ui\\Api\\Resources\\';

    private const string RESOURCE_SUFFIX = 'Resource';

    /**
     * @param ProcessorInterface<mixed, mixed> $inner
     */
    public function __construct(
        private ProcessorInterface $inner,
        private ?TwigEnvironment $twig = null,
    ) {
        // Copied from SwaggerUiProcessor, and for its reason: the container builds this decorator
        // whether or not Twig is installed, so without this the failure arrives as a null
        // dereference in a request instead of at compile time, on the one day somebody is least
        // equipped to read it.
        if (null === $this->twig) {
            throw new \RuntimeException('An HTML representation cannot be rendered since the Twig bundle is not installed. Try running "composer require symfony/twig-bundle".');
        }
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $request = $context['request'] ?? null;

        if (
            $data instanceof Response
            || $operation instanceof ErrorOperation
            || !$operation instanceof HttpOperation
            || !$request instanceof Request
        ) {
            return $this->inner->process($data, $operation, $uriVariables, $context);
        }

        // The one read of the request format in the application, and the only `match` on it. Every
        // format that is not one of these two is somebody else's: `jsonld` and `json` are the
        // serializer's, and an operation that declares neither of these never reaches an arm.
        //
        // **No arm returns.** Every one of them, the default included, feeds the single
        // `$this->inner->process()` below, so a rendered `Response` goes down the rest of the chain
        // and collects `Vary` and the cache headers from the vendor's own processors instead of
        // skipping them, which is the bug this shape fixes. The class docblock has the argument and
        // the two rejected alternatives; the property to keep is that an arm decides a body and
        // decides nothing about the response.
        return $this->inner->process(
            match ($request->getRequestFormat()) {
                self::HTML => $this->page($data, $operation, $uriVariables),
                default => $data,
            },
            $operation,
            $uriVariables,
            $context,
        );
    }

    /**
     * The `text/html` representation: the operation's template, handed the provider's return value
     * before normalization.
     *
     * **The `406` below carries an English sentence and no `FailureCode`, under
     * [ADR 007](docs/ADR/007_translation.md) rule 17.** A negotiation failure's only possible reader
     * is whoever wrote the `Accept` header, and no end user chose it or can change it, so there is
     * nobody to translate for. That exemption is for `406` and `415` and nothing else; a `400` on a
     * filter value is still somebody's typed query and is still a declared code. The class
     * docblock's section on the `406` has the full argument.
     *
     * @param array<string, mixed> $uriVariables
     */
    private function page(mixed $data, HttpOperation $operation, array $uriVariables): Response
    {
        $template = $this->template($operation);

        if (null === $template || !$this->twig->getLoader()->exists($template)) {
            // Phrased like the vendor's own 406 and naming nothing of ours. An operation name is
            // an internal route name and a template path is a filename, and neither is a thing the
            // caller asked for or can do anything about.
            throw new NotAcceptableHttpException('Requested format "text/html" is not supported by this operation.');
        }

        return $this->representation(
            $this->twig->render($template, [
                'data' => $data,
                'operation' => $operation,
                'uri_variables' => $uriVariables,
            ]),
            'text/html; charset=utf-8',
            $operation,
        );
    }

    // ------------------------------------------------------------------------------------------
    // The second representation, and why it is not here
    //
    // ADR 009 rule 3 says a representation need not be a document, and this class is where that is
    // proved: it answered `svg` next to `html`, by reading the format and handing the data to a
    // renderer instead of to Twig. The branch is not in this boilerplate because *what to draw* is
    // an application's question and there is nothing here to draw.
    //
    // Adding it back is four things and no new concepts:
    //
    //  1. a `Drawable` interface in `Ui/Web/Rendering/`, implemented by the REPRESENTATION rather
    //     than named here, so this class asks `$data instanceof Drawable` and never which resource
    //     it is holding, exactly as the `html` arm never asks;
    //  2. a renderer, injected into this constructor, that turns a `Drawable` into a string;
    //  3. `self::SVG => $this->drawing($data, $operation),` in the `match` above, and a `drawing()`
    //     that refuses a non-`Drawable` with the same `406` and the same prose `page()` uses;
    //  4. `'svg' => ['image/svg+xml']` in the `outputFormats:` of the operations that have one, and
    //     nowhere else. ADR 009's correction applies to every format, not only to `html`: a global
    //     entry makes every resource advertise a representation it does not have.
    //
    // The `SVG` constant went with the branch, because an unused constant is a level 6 error and
    // a boilerplate should not ship one. Declaring it again is step 3's first line.
    // ------------------------------------------------------------------------------------------

    /**
     * One body, one media type, one `Response`, and the only place in this file that names a header.
     *
     * Both arms of the `match` end here and so does the next one, which is the point: the class
     * docblock's section on headers says a format decides a body and decides nothing about the
     * response, and this method is where that sentence is enforced rather than merely meant.
     *
     * The two `X-` headers are the vendor's own, copied from
     * {@see \ApiPlatform\State\Util\HttpResponseHeadersTrait}, because that trait runs while
     * *building* a response and a `Response` we hand down is already built. They are constants and
     * not a configured list, so unlike `Vary` and `Cache-Control` there is nothing they could drift
     * away from; and a page is the representation that most needs them, since `deny` is what stops
     * one of ours being framed by somebody else's.
     *
     * `Vary` and `Cache-Control` are deliberately absent. Those two are facts about the operation,
     * `AddHeadersProcessor` reads them off it further down the chain, and writing them here would
     * move the decision from a declaration to a renderer.
     */
    private function representation(string $body, string $mediaType, HttpOperation $operation): Response
    {
        return new Response(
            $body,
            $operation->getStatus() ?? Response::HTTP_OK,
            [
                'Content-Type' => $mediaType,
                'X-Content-Type-Options' => self::NO_SNIFF,
                'X-Frame-Options' => self::NO_FRAME,
            ],
        );
    }

    /**
     * `templates/<context>/<resource>/<operation>.html.twig`, or null when the operation is not one
     * an HTML representation could exist for. The class docblock has the worked examples.
     */
    private function template(HttpOperation $operation): ?string
    {
        $class = $operation->getClass();

        if (null === $class || !str_starts_with($class, self::RESOURCE_NAMESPACE)) {
            return null;
        }

        $segments = explode('\\', substr($class, \strlen(self::RESOURCE_NAMESPACE)));

        // Exactly `<Context>\<Name>Resource`. A deeper path is a resource directory nobody has
        // decided the template layout for, and guessing one is how a convention stops being one.
        if (2 !== \count($segments) || !str_ends_with($segments[1], self::RESOURCE_SUFFIX)) {
            return null;
        }

        $resource = substr($segments[1], 0, -\strlen(self::RESOURCE_SUFFIX));

        if ('' === $resource) {
            return null;
        }

        return \sprintf(
            '%s/%s/%s.html.twig',
            self::snake($segments[0]),
            self::snake($resource),
            $this->operationSegment($operation),
        );
    }

    private function operationSegment(HttpOperation $operation): string
    {
        if ($operation instanceof CollectionOperationInterface) {
            return 'collection';
        }

        if ($operation instanceof Get) {
            return 'item';
        }

        $short = strrchr($operation::class, '\\');

        return self::snake(false === $short ? $operation::class : substr($short, 1));
    }

    private static function snake(string $studlyCaps): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $studlyCaps));
    }
}
