<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Exception\ConflictThrowable;
use App\Shared\Domain\Exception\DeclaredFailure;
use App\Shared\Domain\Exception\SharedFailure;

/**
 * Somebody already signs in with that name.
 *
 * ## Why it is a `409` and not a violation
 *
 * The same shape as `HandleAlreadyTakenException`, and for the
 * same reason: uniqueness among every credential is a set-based invariant, so it cannot be known
 * from the payload alone and ADR 005 keeps it out of the collectable channel.
 * {@see ConflictThrowable} is not {@see \App\Shared\Domain\Exception\UnprocessablePayloadThrowable},
 * it arrives alone by construction, and ADR 009 rule 6 says a browser therefore shows it as a
 * page-level notice rather than painting it onto the username field.
 *
 * ## Why it lives in `Shared/Application/Security/` rather than in a context
 *
 * Because a credential does, and ADR 008 argues that at length on {@see \App\Shared\Infrastructure\Security\Credential}:
 * a hash is not the member's business and `Membership` never learns that authentication exists. So
 * there is no context enum to declare the code in, and it goes where ADR 008's other two refusals
 * already are, beside {@see Forbidden} and {@see NotAuthenticated}, with a `shared.` prefix that
 * {@see SharedFailure} reserves for concepts no bounded context owns.
 *
 * ## It names the username, and that is not ADR 007 rule 9 being broken
 *
 * Rule 9 takes **identifiers** out of failure messages, because a ULID is on no screen and in no
 * password manager. A username is the opposite of that by construction: ADR 009 rule 10 exists
 * precisely because it is the one handle a person typed from memory a moment earlier, so quoting it
 * back tells them which of their attempts collided. `membership.handle-taken` made the same call for
 * the same reason.
 */
final class UsernameAlreadyTaken extends \RuntimeException implements ConflictThrowable
{
    use DeclaredFailure;

    public static function withUsername(string $username): self
    {
        return new self(SharedFailure::UsernameTaken, ['value' => $username]);
    }
}
