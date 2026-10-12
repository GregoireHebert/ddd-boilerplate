<?php

declare(strict_types=1);

namespace ApiPlatform\Validation;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Application\Validation\InvalidPayload;
use Translation\FailureMessages;

/**
 * The seam between ADR 005's option B and API Platform.
 *
 * {@see InvalidPayload} is thrown from `Shared\Application`, which may name
 * `Symfony\Component\Validator` and may not name `ApiPlatform`
 * (`ArchitectureTestCase::application()`). API Platform renders
 * violations natively for anything implementing its `ConstraintViolationListAwareExceptionInterface`
 * (`ErrorListener` checks for it by hand), and its `ValidationException` implements that along with
 * `ProblemExceptionInterface`. So the list crosses the boundary here, in `Ui`, which is the one
 * namespace allowed to know about both ends.
 *
 * **A factory and not a subclass, and that was a bug before it was a decision.** The first version of
 * this file extended `ValidationException`, which reads naturally and fails at runtime with
 * `Operation "" not found for resource "ValidationException"`: the parent carries `#[ErrorResource]`
 * metadata with its operations declared on *that* class, and a subclass inherits the behaviour while
 * the metadata factory looks up the subclass and finds nothing. API Platform's error resources are
 * configuration keyed by class name, so they are extended by being *used*, not by being subclassed.
 *
 * This is also the answer to the `// TODO make something better to not import it from domain
 * application` the old file carried. That version took `string[]` and invented a `ConstraintViolation`
 * per message with a null everything; nothing upstream produced the `string[]`, so the TODO was
 * really asking where the violations were supposed to come from. ADR 005 answers it:
 * {@see \App\Shared\Application\Validation\Violations} builds them, carrying the `propertyPath` and
 * the declared `code` the old shape had nowhere to put.
 *
 * Note what comes free once the codes are real: `ValidationException::getId()` composes the problem's
 * identifier out of `getCode()` of each violation, so the codes ADR 005 locks as the published
 * contract are what a caller sees rather than an `spl_object_hash`.
 *
 * Option A needs none of this. A constraint on an input is validated by API Platform itself, which
 * throws this same class; the two designs converge on one response shape here, which is the point of
 * ADR 005 choosing `ConstraintViolationList` as the shared answer. What they do *not* share is the
 * `code` vocabulary: option A emits Symfony's constraint UUIDs
 * (`9ff3fdc4-b214-49db-8718-39c315e33d45`) because that is what a `Constraint` carries, while option
 * B emits the declared `membership/handle-too-short`. That difference is real, it is visible in the
 * response, and it is one more thing option A cannot be talked out of.
 */
final readonly class ValidationExceptionFactory
{
    public function __construct(private FailureMessages $messages)
    {
    }

    /**
     * A service with a constructor, where ADR 005 left a static factory with a private one. ADR 007
     * is the reason: this is the single point every option B `422` passes through, so it is where
     * the violations get their words, and a translator is not something a static method can hold.
     *
     * Both things it renders come from the same catalogue. `errorTitle` is
     * `payload.unprocessable`, whose entry is the one plural in the tree
     * (`{count, plural, ...}`); the list is {@see FailureMessages::translate()}, which renders
     * option B's violations and leaves option A's alone because Symfony's validator already
     * translated those at build time.
     */
    public function from(InvalidPayload $failure): ValidationException
    {
        return new ValidationException(
            $this->messages->translate($failure->getConstraintViolationList()),
            previous: $failure,
            errorTitle: $this->messages->render($failure),
        );
    }
}
