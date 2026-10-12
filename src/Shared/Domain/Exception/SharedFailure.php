<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * The shared kernel's failures: identity, the payload container, ADR 004's filter parse failures,
 * and a remote call that did not work out.
 *
 * Everything here is prefixed `shared.` for the reason {@see InvalidIdentifierException}'s docblock
 * gives about its own code: these are concepts no bounded context owns. `payload.` is the one
 * exception and it is deliberate, because {@see \App\Shared\Application\Validation\InvalidPayload}
 * is a container for other failures rather than a failure about anything, and a caller routing on
 * it is routing on the shape of the response rather than on a subject.
 *
 * @see FailureCode for why the set is declared rather than extracted
 */
enum SharedFailure: string implements FailureCode
{
    case MalformedIdentifier = 'shared.malformed-identifier';

    case PayloadUnprocessable = 'payload.unprocessable';

    case FilterUnknownValue = 'shared.filter-unknown-value';
    case FilterMissing = 'shared.filter-missing';
    case FilterMalformedCursor = 'shared.filter-malformed-cursor';

    /**
     * One code per expected type, where ADR 004 had one generic `notA()` taking the type as a
     * string.
     *
     * That string was English prose (`'an identifier'`, `'a boolean'`), interpolated into a
     * message that is now translated, which produced `« banana » n'est pas an identifier` the
     * first time a French caller sent a bad URI segment. ADR 007 found it by shipping, and the
     * rule it breaks is the ADR's own: a parameter is a value, never a fragment of the sentence
     * around it. A fragment cannot be translated in place, because the language it has to agree
     * with is chosen somewhere else.
     *
     * Four codes rather than one with a nested lookup, and a caller gets something better out of
     * it than we did: `shared.filter-not-an-integer` is routable and `shared.filter-wrong-type`
     * was not.
     */
    case FilterNotABoolean = 'shared.filter-not-a-boolean';
    case FilterNotAnInteger = 'shared.filter-not-an-integer';
    case FilterNotAnIdentifier = 'shared.filter-not-an-identifier';
    case FilterNotADate = 'shared.filter-not-a-date';

    /**
     * The same split, for what `outOfRange()` used to take as a whole English clause
     * (`'page numbers start at 1'`).
     */
    case PageBelowOne = 'shared.page-below-one';
    case PageSizeTooSmall = 'shared.page-size-too-small';
    case SliceSizeTooSmall = 'shared.slice-size-too-small';

    case RemoteCallFailed = 'shared.remote-call-failed';
    case RemoteCallUnreadable = 'shared.remote-call-unreadable';

    /**
     * ADR 008's two refusals, and they are the only two codes in this enum that deliberately take
     * **no parameters at all**.
     *
     * Everything else here names what the reader has to change: the value that would not parse, the
     * parameter it arrived under, the page number that was out of range. A caller who is not
     * authenticated has nothing to change except that; a caller who lacks a permission has nothing
     * they can change at all, and naming the permission would publish the shape of the policy to
     * the one person who has just demonstrated they are not entitled to know it.
     *
     * So the permission goes in the log line beside the actor and the operation, where somebody
     * debugging can use it, and never into `parameters()`. ADR 007 found the general version of
     * this the expensive way, across thirteen exceptions: a failure can only leak what it hands
     * over, so an identifier has to leave the parameter map and not merely the sentence.
     */
    case NotAuthenticated = 'shared.not-authenticated';
    case Forbidden = 'shared.forbidden';

    /**
     * ADR 009's registration conflict, raised by {@see \App\Shared\Application\Security\UsernameAlreadyTaken}.
     *
     * It is here rather than in `MembershipFailure` because a credential is not `Membership`'s: ADR 008
     * keeps that table in `Shared/Infrastructure/Security/` and `Member` never learns that
     * authentication exists. The subject belongs to no bounded context, which is exactly what the
     * `shared.` prefix means.
     *
     * Unlike the two refusals above it takes a parameter, and that is not an inconsistency. A
     * username is the one handle ADR 009 rule 10 guarantees the caller typed from memory, so quoting
     * it back tells them which of their attempts collided; `membership.handle-taken` made the same
     * call for the same reason. ADR 007 rule 9 is about identifiers a reader cannot use, and a
     * username is the opposite of one.
     */
    case UsernameTaken = 'shared.username-taken';

    /**
     * ADR 003's optimistic-locking hook, surfaced as a conditional request, and the two codes are
     * deliberately separate for the same reason their markers are: a `412` tells a client to
     * re-read and retry, a `428` tells them to send a header they have never sent.
     *
     * Neither takes a parameter. {@see \App\Shared\Domain\Exception\StalePreconditionException}
     * carries the argument: an ETag is an opaque token the reader cannot act on, so quoting one back
     * is ADR 007's identifier problem wearing a different name.
     */
    case PreconditionFailed = 'shared.precondition-failed';
    case PreconditionRequired = 'shared.precondition-required';

    #[\Override]
    public function code(): string
    {
        return $this->value;
    }
}
