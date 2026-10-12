<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * A conditional write arrived with no `If-Match` at all. `428`.
 *
 * {@see PreconditionRequiredThrowable} carries the argument for the status and
 * `SubscriptionPlanResource` carries the
 * argument for requiring the header in the first place. This class is four lines because both of
 * those are written somewhere a reader will actually meet them.
 *
 * `\RuntimeException` for the same reason as {@see StalePreconditionException}: nothing about the
 * domain is wrong. The request is incomplete in a way no amount of reading our state would reveal.
 */
final class MissingPreconditionException extends \RuntimeException implements PreconditionRequiredThrowable
{
    use DeclaredFailure;

    public static function ifMatch(): self
    {
        return new self(SharedFailure::PreconditionRequired);
    }
}
