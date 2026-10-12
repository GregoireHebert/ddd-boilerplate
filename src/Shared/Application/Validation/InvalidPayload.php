<?php

declare(strict_types=1);

namespace App\Shared\Application\Validation;

use App\Shared\Domain\Exception\SharedFailure;
use App\Shared\Domain\Exception\UnprocessablePayloadThrowable;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Every payload failure {@see Violations} collected, in one throwable.
 *
 * It implements {@see UnprocessablePayloadThrowable} like each of its contents, which is what keeps
 * `exception_to_status` down to a single new entry: the container and the failures inside it are
 * the same kind of thing, so they answer with the same status whether one field was wrong or five.
 *
 * What it deliberately does *not* implement is API Platform's
 * `ConstraintViolationListAwareExceptionInterface`, which `ErrorListener` special-cases and which
 * would render this natively with no adapter at all. `ArchitectureTestCase::application()` allows
 * `Symfony\Component\Validator` and does not allow `ApiPlatform`, and that rule is right: a
 * vocabulary shared by every delivery cannot name one of them. The conversion is one class on the
 * other side of the seam, {@see \ApiPlatform\Validation\ValidationExceptionFactory}.
 *
 * Symfony's own `ValidationFailedException` was the other candidate and carries the same list. It
 * lost because it implements no marker of ours, so the HTTP mapping and the console's exit code
 * would both have had to key off a vendor class, and ADR 005 lock 12 wants one error object driving
 * both renderers.
 *
 * ## The one plural in the tree
 *
 * Its `count` parameter is what `failures+intl-icu.en.xlf` runs through ICU's `plural` argument,
 * and it is the reason ADR 007 writes the catalogue in ICU rather than Symfony's `%name%` format.
 * English needs two forms here, French needs the same two with a different boundary (zero is
 * singular), and a Slavic language needs three or four. `{count, plural, ...}` is the only notation
 * that lets a translator say so without us shipping code per language.
 */
final class InvalidPayload extends \RuntimeException implements UnprocessablePayloadThrowable
{
    /**
     * EINVAL, and the same 22 {@see \App\Shared\Application\ReadModel\BadFilterInput} returns for
     * ADR 004's filter failures. Not a duplicated decision: a caller who typed something
     * unacceptable should get one answer from this application whether they typed it into a query
     * string or a request body, and a script should not have to tell the two apart.
     */
    public const int EXIT_CODE = 22;

    /**
     * The one failure in the tree that does not use {@see \App\Shared\Domain\Exception\DeclaredFailure}, because it is the one
     * with a public constructor: {@see Violations::orThrow()} builds it at an edge from a list it
     * already holds, rather than through a named constructor inside a domain object. The trait's
     * constructor is `protected` and takes a code and a parameter map, which is the wrong shape for
     * a container whose only argument is its contents.
     */
    public function __construct(private readonly ConstraintViolationListInterface $violations)
    {
        parent::__construct(SharedFailure::PayloadUnprocessable->code());
    }

    public function getConstraintViolationList(): ConstraintViolationListInterface
    {
        return $this->violations;
    }

    /**
     * The container's own code. Each violation inside carries the specific one that
     * {@see \App\Shared\Domain\Exception\TranslatableThrowable::code()} declared, which is where
     * a caller should route.
     *
     * @return non-empty-string
     */
    #[\Override]
    public function code(): string
    {
        return SharedFailure::PayloadUnprocessable->code();
    }

    /**
     * @return array<string, scalar>
     */
    #[\Override]
    public function parameters(): array
    {
        return ['count' => \count($this->violations)];
    }
}
