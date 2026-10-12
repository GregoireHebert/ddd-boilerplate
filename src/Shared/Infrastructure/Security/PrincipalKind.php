<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

/**
 * What kind of thing is asking.
 *
 * Two kinds and a hole, because [trust/02](../../../../docs/kanban/trust/02_the-moderation-queue.md)
 * needs a moderator and a moderator is not a member: no badge, in no feed, cannot be liked, and
 * modelling one as a member with a flag is the shortcut the board named before ADR 008 did.
 *
 * `Moderator` is declared now and used by nothing, which is a deliberate exception to this
 * repository's habit of not building for a future caller. The reason is that the alternative is
 * worse in a specific way: {@see Actor::subject()} has to refuse a principal of the wrong kind, and
 * retrofitting that refusal later means auditing every call site that had been written when there
 * was only one kind and the check was vacuous.
 *
 * `Nobody` is not "an anonymous user"; it is the absence of one. See {@see Authority::None}.
 */
enum PrincipalKind: string
{
    case Member = 'member';
    case Moderator = 'moderator';
    case Nobody = 'nobody';
}
