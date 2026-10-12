<?php

declare(strict_types=1);

namespace App\Shared\Application\Validation;

use App\Shared\Domain\Exception\UnprocessablePayloadThrowable;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * ADR 005's option B: the value object's constructor is the authority, and this runs it against raw
 * input, one field at a time, collecting what refuses to parse.
 *
 * The one thing to understand before reading further is what this is *not* a reimplementation of.
 * Symfony's validator answers "the object you are holding is wrong"; it takes an object that
 * already exists and matches constraints against it. This answers "the object cannot be built",
 * which is a different question with no `ValidatorInterface` equivalent: there is no "attempt to
 * construct" operation in that component. What the two share is the *answer*, and so this produces
 * a `ConstraintViolationList` rather than a vocabulary of its own. Option A and option B therefore
 * hand the caller the identical 422 body, and differ only in who decided.
 *
 * Accumulation happens within a stage (ADR 005, lock 4). This is stage one, per-field parsing.
 * Stage two is whatever the command's own constructor checks across fields, which can only run once
 * every field has parsed, so it lands *after* {@see self::orThrow()} and its failure arrives alone:
 *
 * ```php
 * $parsed = (new Violations())
 *     ->at('fromMemberId', fn () => Id::fromString($body['from'] ?? ''))
 *     ->at('toMemberId', fn () => Id::fromString($body['to'] ?? ''))
 *     ->orThrow();                                     // stage 1, accumulated
 *
 * return new CastLike(                                 // stage 2, alone
 *     $parsed->get('fromMemberId', Id::class),
 *     $parsed->get('toMemberId', Id::class),
 * );
 * ```
 *
 * Stage three is the handler, which needs the store and is always one failure at a time.
 *
 * Not a service: it holds the state of one parse and is built with `new` at the edge that parses.
 * `config/services.php` excludes all of `Shared/Application/` for that reason.
 */
final class Violations
{
    /** @var array<string, object> */
    private array $values = [];

    /** @var list<ConstraintViolationInterface> */
    private array $violations = [];

    /**
     * Parse one field, and keep going whether or not it worked.
     *
     * Only {@see UnprocessablePayloadThrowable} is caught, and that is the point rather than an
     * oversight: it is the marker for failures that are pure functions of the payload, so catching
     * it is exactly the set that may be collected. A `DomainNotFoundThrowable` escaping from inside
     * one of these closures would mean the closure consulted a store, which is stage three wearing
     * stage one's clothes, and it bubbles rather than joining the list.
     *
     * @param string          $pointer the caller's name for this field, which becomes the
     *                                 violation's `propertyPath`; nest it the way the payload nests
     *                                 (`preferences.colors[0]`)
     * @param callable():object $parse  the named constructor that decides
     */
    public function at(string $pointer, callable $parse): self
    {
        try {
            $this->values[$pointer] = $parse();
        } catch (UnprocessablePayloadThrowable $failure) {
            $this->violations[] = self::violation($pointer, $failure);
        }

        return $this;
    }

    /**
     * @throws InvalidPayload if anything failed to parse
     */
    public function orThrow(): ParsedPayload
    {
        if ([] !== $this->violations) {
            throw new InvalidPayload(new ConstraintViolationList($this->violations));
        }

        return new ParsedPayload($this->values);
    }

    /**
     * Stage 2, wrapped in stage 1's clothes: one pure failure, reported as a list of one.
     *
     * A cross-field check like `CannotLikeSelfException` is raised by the command's own constructor,
     * outside any `at()`, because it cannot be asked until every field has parsed. Left alone it
     * would reach a caller as a bare problem document with a `detail` and no `violations`, while the
     * failure one keystroke away came back as a list. Two shapes, both 422, for what is from the
     * outside the same sentence: *your payload is wrong, here is what is wrong with it*.
     *
     * So an edge converts. Every 422 this application sends carries `violations`, and a client
     * writes one branch instead of two. The `$pointer` is null by default and that is the honest
     * answer: the failure belongs to the relationship between two fields rather than to either of
     * them, and `propertyPath: ""` says so more accurately than picking one at random.
     *
     * It is a separate method rather than something `at()` does, because the staging is real. Making
     * this collectable alongside the fields would mean evaluating it before they exist.
     */
    public static function of(UnprocessablePayloadThrowable $failure, ?string $pointer = null): InvalidPayload
    {
        return new InvalidPayload(new ConstraintViolationList([self::violation($pointer ?? '', $failure)]));
    }

    /**
     * `ConstraintViolation` takes ten arguments and most of them have no meaning here, which is why
     * this is one private factory instead of a pile of nulls at every call site.
     *
     * ## The translation seam, now populated
     *
     * The previous version of this method filled `messageTemplate` with the already-interpolated
     * message and `parameters` with an empty array, and said so: *"the template/parameters split is
     * a translation seam and nothing here is translated yet. When it is, that is the pair to
     * populate."* ADR 007 populates it. The template is the declared code and the parameters are
     * the exception's named map, which is the pair Symfony's own `ConstraintViolationBuilder`
     * carries for exactly this purpose.
     *
     * `message` holds the code too, and that is a placeholder rather than an answer.
     * {@see \Translation\FailureMessages} replaces it with the reader's
     * language before anything renders the list. It cannot happen here: this class is built with
     * `new` at an edge and is not a service, so it has no translator, and it would be the wrong
     * place anyway. Option A's violations arrive already translated by Symfony's validator, so a
     * list can hold both kinds, and only something that knows which catalogue owns a template can
     * tell them apart.
     *
     * Three of the nulls are worth a word:
     *
     * - `constraint` is null because in option B no constraint object exists. The rule lives in a
     *   named constructor, and pretending otherwise would be the only dishonest part of reusing
     *   this class;
     * - `invalidValue` is null because the offending value travels as a named parameter instead
     *   (`InvalidHandleException` passes `value`), so
     *   filling it would echo the caller's input back a second time;
     * - `plural` is null because ICU handles plurals inside the catalogue entry, which is one of
     *   the reasons ADR 007 chose ICU over Symfony's `%name%` format.
     *
     * `cause` carries the original throwable, so a log still has the stack that produced it after
     * the failure has been flattened into a value.
     *
     * One reserved name to know about: a parameter called `hint` is emitted as its own field in the
     * rendered violation, because `AbstractConstraintViolationListNormalizer` special-cases it.
     */
    private static function violation(string $pointer, UnprocessablePayloadThrowable $failure): ConstraintViolation
    {
        return new ConstraintViolation(
            message: $failure->code(),
            messageTemplate: $failure->code(),
            parameters: $failure->parameters(),
            root: null,
            propertyPath: $pointer,
            invalidValue: null,
            plural: null,
            code: $failure->code(),
            constraint: null,
            cause: $failure,
        );
    }
}
