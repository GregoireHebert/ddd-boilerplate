<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

/**
 * How we know who is asking. ADR 008 calls it the actor's *provenance*, and it is a field rather
 * than a comment for exactly one reason: an operator assertion and a login produce identical
 * effects everywhere downstream and must not produce identical log lines.
 *
 * That is the whole of its job. Nothing branches on it except the audit line, and the design is
 * deliberately arranged so that nothing else can: a processor, a command and an aggregate cannot
 * tell `bin/console app:likes:cast --as X` from `POST /likes`, which is the property that makes the
 * console worth having as a second delivery at all.
 *
 * {@see Authority::Assertion} is the uncomfortable one and ADR 008 says so out loud rather than
 * dressing it up: **`--as` is root.** Anyone who can run the console already holds the database
 * credentials, so an application-level check on who may assert what would be theatre. What the
 * enum buys is that the assertion is *visible*, and the access control lives where it can actually
 * be enforced, on who may reach the machine.
 */
enum Authority: string
{
    /** A bearer token presented on this request. The API and the RPC endpoint. */
    case Token = 'token';

    /** A session cookie. The webapp, which is a first-party client and is what a cookie is for. */
    case Session = 'session';

    /** An operator said so, with `--as`. Never issued by an HTTP delivery. */
    case Assertion = 'assertion';

    /**
     * Nobody is asking. An anonymous request, or a console command acting for the installation
     * rather than for a person.
     *
     * This is not a principal with no rights; it is the absence of one, and ADR 008 rule 7 turns
     * that distinction into a design constraint: an operation reached with no actor is *unguarded*
     * rather than privileged, and there is no `Actor::system()` for it to be privileged as.
     */
    case None = 'none';
}
