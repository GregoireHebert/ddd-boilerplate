<?php

declare(strict_types=1);

namespace ApiPlatform\Provider;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ApiResource\Error;
use ApiPlatform\State\ProviderInterface;
use App\Shared\Application\Security\Forbidden;
use App\Shared\Domain\Exception\TranslatableThrowable;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Translation\FailureMessages;

/**
 * The parts of an RFC 9457 document this application owns: the `type` a client routes on, the
 * `detail` a human reads, and the `instance` that says which request they belong to.
 *
 * The first two are one job rather than two. Both are projections of
 * {@see TranslatableThrowable::code()}, both need the same unwrapping to find the exception behind
 * the response, and both answer the same question about production ("is this a 5xx, and are we
 * hiding it"). The third is not a projection of anything and is set a few lines earlier, for the
 * reason its own section gives.
 *
 * ## The type
 *
 * A URI Tag per RFC 4151, so clients can handle specific errors without relying on error messages
 * (which are not meant to be parsed, and which now change language) or on HTTP status codes (which
 * are too generic). `membership.handle-too-short` becomes
 * `tag:gheb.dev,2026:membership.handle-too-short`.
 *
 * **The code goes in verbatim, and that is the change ADR 007 made here.** This class used to
 * compose the tag from `get_class($exception)`: a regex for the context segment
 * (`/\\\\([^\\\\]+)\\\\Domain/`), then a camel-to-kebab pass over the short name with `Exception`
 * stripped. It worked, and it was precisely what ADR 005 forbids for `code()`. It published our
 * namespace as public contract, so moving `InvalidNameException` between directories, or renaming
 * it, silently broke every caller routing on the tag. It also only ever ran for
 * `DomainUnprocessableThrowable`, so a 404, a 409 and a 424 all shared whatever `type` API Platform
 * defaulted to.
 *
 * Now the code is declared, the transformation is the empty one, and the widening to
 * `TranslatableThrowable` means every marker gets an honest tag.
 *
 * ## The detail
 *
 * `Error::createFromException()` fills `detail` from `$exception->getMessage()`, and under ADR 007
 * that is the code rather than a sentence, so something has to look it up. This is that something,
 * and {@see \Translation\FailureMessages} is the only object in the application that calls `trans()`.
 *
 * ## The instance
 *
 * [ADR 009](docs/ADR/009_one-url-two-representations.md) rule 11 asks for all five RFC 9457 members
 * on the JSON representation of a refusal, and `instance` was the one nothing set: `Error`'s
 * constructor defaults it to null and `skip_null_values` then drops it, so every problem document
 * this API has ever sent has had four members. It is the request URI, which RFC 9457 §3.1.5 calls
 * "a URI reference that identifies the specific occurrence", and a relative reference is what it
 * asks for.
 *
 * Three things about where it is set.
 *
 * It is set **before** the narrowing below, so it lands on every problem document rather than only
 * on ours. A `403` raised by the firewall is a Symfony `AccessDeniedHttpException` and carries no
 * declared code, so it takes neither `type` nor `detail` from here; it is still one of the three
 * rows in ADR 009's refusal table and a client correlating it against a log needs the same member
 * the other two carry.
 *
 * It is set on a `5xx` too, which the section below deliberately does not do. The reason the others
 * stop there is that they publish something of ours; a request URI is what the caller sent a
 * moment earlier, so there is nothing of ours in it to hide.
 *
 * And it is **not** governed by ADR 008 rule 14, which is about what a refusal *says*: a `404` on
 * `/pairs/01J...` puts that identifier in `instance` while `detail` still refuses to name it. The
 * caller typed it; the rule is about not handing back what they did not.
 *
 * ## The title
 *
 * This class used to say `title` was "deliberately left alone" because API Platform derived it from
 * the status phrase. **It does not.** `Error::createFromException()` reads `getTitle()` off an
 * exception implementing `ProblemExceptionInterface`, and none of ours does, so every failure this
 * API has ever published has been titled `"An error occurred"`. That is not a summary of a problem
 * type, it is the absence of one, and ADR 009 rule 11 asks for a short stable phrase.
 *
 * So the phrase is RFC 9110's own reason phrase for the status: "Unauthorized", "Forbidden", "Not
 * Found". It satisfies RFC 9457 §3.1.2, which says a title "SHOULD NOT change from occurrence to
 * occurrence of the problem, except for purposes of localization", because a status phrase does not
 * change at all.
 *
 * **ADR 009's note under the refusal table offers "Authentication required" instead, and this is
 * the one place in the refusal where the code does not follow it to the letter.** The reason is
 * ADR 007. A title that is prose of ours is prose a reader reads, so it would need a declared code,
 * a catalogue key and an entry per enabled locale, and it would then be a second translated
 * sentence next to `detail`, which already is one. A status phrase is protocol vocabulary rather
 * than prose: nobody translates "Not Found", and a client reading it is reading something RFC 9110
 * named in English before we did. Choosing the other reading is one line here plus two catalogue
 * entries per refusal, and it is reported rather than taken quietly.
 *
 * The title is set inside the narrowing below rather than beside `instance`, and the reason is that
 * it *replaces* something. A failure of ours is never a `ProblemExceptionInterface`, so its title is
 * always the fallback and there is nothing to lose; a failure that is not ours may have brought its
 * own, and overwriting that would be this class inventing an opinion about somebody else's document.
 *
 * A `403` raised by a `#[Guarded]` operation's `security` expression used to keep
 * `"An error occurred"` and Symfony's own `"Access Denied."`, because the exception behind it was
 * `AccessDeniedException` rather than a `TranslatableThrowable`. The narrowing below turns that one
 * exception into {@see \App\Shared\Application\Security\Forbidden} first, so it reaches this method
 * exactly as a `Forbidden` raised any other way would.
 *
 * The `422` from a payload does not come through here at all: it is a different error resource with
 * a different provider, and its title is `payload.unprocessable` rendered by
 * {@see \ApiPlatform\Validation\ValidationExceptionFactory}.
 *
 * @author Grégoire Hébert <contact@gheb.dev>
 *
 * @implements ProviderInterface<object>
 */
#[AsDecorator(decorates: 'api_platform.state.error_provider')]
final readonly class ErrorTypingProvider implements ProviderInterface
{
    private const string TAG_FORMAT = 'tag:gheb.dev,2026:%s';

    /**
     * API Platform's own fallback, kept for the status nobody has a phrase for. Reaching it means
     * a status outside RFC 9110's registry, which is a bug rather than a caller's problem.
     */
    private const string UNTITLED = 'An error occurred';

    public function __construct(
        /** @var ProviderInterface<object> */
        private ProviderInterface $inner,
        private FailureMessages $messages,
        #[Autowire('env(bool:APP_DEBUG)')]
        private bool $debug = false,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = $this->inner->provide($operation, $uriVariables, $context);

        $request = $context['request'] ?? null;

        if (!$request instanceof Request || !$operation instanceof HttpOperation) {
            throw new \RuntimeException('Not an HTTP request');
        }

        if ($result instanceof Error) {
            $result->setInstance($request->getRequestUri());
        }

        if (!($exception = $request->attributes->get('exception'))) {
            return $result;
        }

        // Using WithHttpStatus attributes causes Symfony to wrap the exception in an HttpException.
        if ($exception instanceof HttpExceptionInterface) {
            $exception = $exception->getPrevious() ?? $exception;
        }

        // A `#[Guarded]` operation's `security` expression is asked by API Platform's own access
        // checker, not by our `Gate`, and a refusal there arrives as
        // `ApiPlatform\Symfony\Security\Exception\AccessDeniedException`, which extends Symfony's
        // own `AccessDeniedException` and carries "Access Denied." rather than a declared code. It
        // is still ADR 008 question 2, decided without reading anything of ours, which is exactly
        // `shared.forbidden`; so it is turned into the declared failure the rest of this method
        // already knows how to render, instead of being left as Symfony's own sentence. A refusal
        // that already declares itself, such as one thrown by `Gate::isGranted()` directly, keeps
        // its own code and is untouched by this branch.
        if ($exception instanceof AccessDeniedException && !$exception instanceof TranslatableThrowable) {
            $exception = Forbidden::refused();
        }

        if (!$exception instanceof TranslatableThrowable || !$result instanceof Error) {
            return $result;
        }

        // Hide internal error details in production. A 5xx reaching here means a marker was mapped
        // to one on purpose, and the person who needs to know why is reading the logs.
        if (($result->getStatus() ?? 500) >= 500 && !$this->debug) {
            return $result;
        }

        $result->setType(\sprintf(self::TAG_FORMAT, $exception->code()));
        $result->setDetail($this->messages->render($exception));
        $result->setTitle(Response::$statusTexts[$result->getStatus() ?? 0] ?? self::UNTITLED);

        return $result;
    }
}
