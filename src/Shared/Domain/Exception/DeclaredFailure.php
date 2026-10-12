<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * {@see TranslatableThrowable}, implemented once.
 *
 * Twenty-one exceptions in this tree carry a marker and therefore a code, and the body of `code()`
 * and `parameters()` is identical in all of them. A trait rather than a base class, because they
 * extend four different SPL exceptions on purpose: `\InvalidArgumentException` for a payload,
 * `\OutOfBoundsException` for a lookup, `\RuntimeException` for a conflict, `\DomainException` for
 * a state failure. That choice is readable at the class declaration and worth keeping.
 *
 * The constructor is `protected` rather than `private` for one reason:
 * {@see AbstractDomainUnprocessableException} uses this trait and is extended, and a private
 * constructor declared in a parent is not visible to `new self()` in a child's named constructor.
 * Every class using it is `final` and constructs itself through named constructors, so the
 * practical effect is the same.
 */
trait DeclaredFailure
{
    /**
     * @param array<string, scalar> $failureParameters
     */
    protected function __construct(
        private readonly FailureCode $failureCode,
        private readonly array $failureParameters = [],
        ?\Throwable $previous = null,
    ) {
        // The code, not a sentence. ADR 007: `getMessage()` is for a stack trace and a log line,
        // and the reader's language lives in the catalogue.
        parent::__construct($failureCode->code(), 0, $previous);
    }

    /**
     * @return non-empty-string
     */
    public function code(): string
    {
        return $this->failureCode->code();
    }

    /**
     * @return array<string, scalar>
     */
    public function parameters(): array
    {
        return $this->failureParameters;
    }
}
