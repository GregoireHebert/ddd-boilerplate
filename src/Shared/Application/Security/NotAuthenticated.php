<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Exception\DeclaredFailure;
use App\Shared\Domain\Exception\SharedFailure;
use App\Shared\Domain\Exception\UnauthenticatedThrowable;

/**
 * ADR 008: something needed a principal and there was not one.
 *
 * In `Application` rather than in a context's `Domain` for the reason
 * {@see \App\Shared\Application\ReadModel\BadFilterInput} gives about itself: a credential the
 * caller did not present is not a domain concept, and nothing in `Membership` or `Matching` has an
 * opinion about it. What it does implement is a `Shared\Domain` marker and a `Shared\Domain`
 * failure code, which is the one direction allowed.
 *
 * It is `\RuntimeException` rather than `\InvalidArgumentException` on the same reasoning
 * {@see DeclaredFailure} sets out for the other twenty-one: nothing was wrong with the argument,
 * the process was in a state where the question could not be answered.
 *
 * Almost always the firewall gets there first and this is never constructed. What constructs it is
 * {@see \App\Shared\Infrastructure\Security\Actor::subject()} on a path that let an anonymous caller
 * through and then reached for a subject, which is the case worth having an exception for: the
 * alternative is a null travelling into a command as somebody's identifier.
 */
final class NotAuthenticated extends \RuntimeException implements UnauthenticatedThrowable
{
    use DeclaredFailure;

    /**
     * No named constructor taking what was being attempted, and no parameters, deliberately. See
     * {@see SharedFailure::NotAuthenticated}.
     */
    public static function required(): self
    {
        return new self(SharedFailure::NotAuthenticated);
    }
}
