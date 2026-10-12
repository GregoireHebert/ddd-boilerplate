<?php

declare(strict_types=1);

namespace Translation;

use App\Shared\Domain\Exception\TranslatableThrowable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ADR 007's one translator. Nothing else in this application calls `trans()`.
 *
 * Five things read a failure's text: API Platform's violation normalizer, the `Error` resource's
 * `detail` via {@see \ApiPlatform\Provider\ErrorTypingProvider},
 * {@see \ApiPlatform\JsonRpc\RpcErrorFactory}, the console's
 * {@see \App\Ui\Console\Write\RenderViolations} for writes, and the six `catch (BadFilterInput)`
 * blocks in `Ui/Console/Query/` for reads. Putting the lookup in each of them would be five homes
 * for one policy, so they all call this instead. It extends ADR 005 lock 12 by a word: *one error
 * object, one translator, three renderers*.
 *
 * ## There is no fallback logic here, and that is deliberate
 *
 * `MessageCatalogue::get()` walks its `fallbackCatalogue` and `Translator::trans()` uses it, so a
 * missing `fr` entry resolves through `en` without a line of ours. Symfony owns the fallback chain;
 * our only job is to make sure `en` is never the one missing, and that job belongs to
 * {@see \App\Ui\Console\MessagesCommand} rather than to a `??` in this file.
 *
 * What `get()` does when nothing at all resolves is return the key, so a code with no entry
 * anywhere ships `membership.handle-too-short` to a caller. Loud, greppable, and the reason the
 * codes are declared as enum cases in the first place.
 *
 * ## Why {@see self::translate()} asks before it answers
 *
 * A single list can hold violations from both of ADR 005's designs, and they arrive in opposite
 * states. Option A's are produced by Symfony's `ConstraintViolationBuilder`, which calls
 * `trans(...)` at build time against the `validators` domain, so they are **already** in the
 * reader's language. Option B's are built with `new` by
 * {@see \App\Shared\Application\Validation\Violations}, which bypasses the builder and therefore
 * the translator, so they carry a code where their message should be.
 *
 * Translating everything would take option A's finished French sentence and replace it with the raw
 * English template Symfony kept next to it. So this asks the catalogue whether it owns the template
 * before touching anything. `has()` rather than `defines()`, because `has()` traverses the fallback
 * catalogues and `defines()` does not; a code that only exists in `en` is still ours while a French
 * reader is asking.
 *
 * The delivery layer is the only place this can live, and `Ui` is where phpat says so: ADR 007
 * narrows `Symfony\Component\Translation` and `Symfony\Contracts\Translation` to this namespace, so
 * neither a domain, nor a handler, nor a context's own infrastructure can reach a translator.
 *
 * Nothing here takes a locale. `framework.set_locale_from_accept_language` puts the caller's on the
 * request and on the translator, and the console runs at `framework.default_locale`; both are
 * ambient, which is safe *only* because this class is the only thing allowed to read them.
 */
final readonly class FailureMessages
{
    /**
     * Not `messages` and not `validators`. Its own domain, so that
     * {@see self::owns()} can answer "is this string one of ours" with a lookup rather than a
     * heuristic about what the string looks like.
     */
    public const string DOMAIN = 'failures';

    public function __construct(
        private TranslatorInterface $translator,
        /**
         * The same `translator` service, asked for by id rather than by type: FrameworkBundle
         * aliases `TranslatorInterface` and does not alias `TranslatorBagInterface`, and the
         * catalogue lookup in {@see self::owns()} needs the second half of what that service is.
         */
        #[Autowire(service: 'translator')]
        private TranslatorBagInterface $catalogues,
    ) {
    }

    /**
     * One failure, in the reader's language. The `detail` of an RFC 9457 document, the `message` of
     * a JSON-RPC error, a line on stderr.
     */
    public function render(TranslatableThrowable $failure): string
    {
        return $this->translator->trans($failure->code(), $failure->parameters(), self::DOMAIN);
    }

    /**
     * A whole list, with every violation this application built rendered and every violation
     * Symfony built left exactly as it arrived.
     */
    public function translate(ConstraintViolationListInterface $violations): ConstraintViolationListInterface
    {
        $rendered = [];

        foreach ($violations as $violation) {
            $rendered[] = $this->owns($violation->getMessageTemplate())
                ? self::withMessage($violation, $this->translator->trans(
                    $violation->getMessageTemplate(),
                    $violation->getParameters(),
                    self::DOMAIN,
                ))
                : $violation;
        }

        return new ConstraintViolationList($rendered);
    }

    private function owns(string $template): bool
    {
        return $this->catalogues->getCatalogue()->has($template, self::DOMAIN);
    }

    /**
     * `ConstraintViolation` is a value object with ten constructor arguments and no setters, which
     * is correct and mildly inconvenient exactly once: replacing one field means rebuilding it.
     *
     * `constraint` and `cause` only exist on the concrete class rather than on the interface, so
     * they survive a rebuild only when there is a concrete class to read them from. Ours always has
     * one, and `cause` is the original throwable, which is what keeps a log able to show the stack
     * that produced a failure after it has been flattened into a value.
     */
    private static function withMessage(ConstraintViolationInterface $violation, string $message): ConstraintViolation
    {
        return new ConstraintViolation(
            message: $message,
            messageTemplate: $violation->getMessageTemplate(),
            parameters: $violation->getParameters(),
            root: $violation->getRoot(),
            propertyPath: $violation->getPropertyPath(),
            invalidValue: $violation->getInvalidValue(),
            plural: $violation->getPlural(),
            code: $violation->getCode(),
            constraint: $violation instanceof ConstraintViolation ? $violation->getConstraint() : null,
            cause: $violation instanceof ConstraintViolation ? $violation->getCause() : null,
        );
    }
}
