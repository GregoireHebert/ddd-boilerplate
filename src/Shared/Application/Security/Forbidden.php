<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Exception\DeclaredFailure;
use App\Shared\Domain\Exception\ForbiddenThrowable;
use App\Shared\Domain\Exception\SharedFailure;

/**
 * ADR 008 question 2, refused: the gate said no.
 *
 * One named constructor, no arguments, and that is the whole class. The temptation is to accept the
 * {@see \App\Shared\Infrastructure\Security\Permission} that was refused so the message can name it,
 * and {@see SharedFailure::Forbidden} is where the refusal to do that is argued.
 *
 * The permission is not lost; it goes in the audit line, which is written at the edge next to the
 * actor, the operation and the provenance. That is where somebody debugging a policy looks, and it
 * is not reachable by the caller.
 *
 * Note what never reaches this class either: ownership and entitlement refusals.
 * {@see ForbiddenThrowable} lists all three cases and where each one actually goes.
 */
final class Forbidden extends \RuntimeException implements ForbiddenThrowable
{
    use DeclaredFailure;

    public static function refused(): self
    {
        return new self(SharedFailure::Forbidden);
    }
}
