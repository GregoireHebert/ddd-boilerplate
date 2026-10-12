<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * **The caller sent no `If-Match`, on a write that refuses to guess.** `428`, RFC 6585 section 3.
 *
 * RFC 6585 invented this status for one purpose and it is exactly this one: "the origin server
 * requires the request to be conditional", with the lost update problem named in its own rationale.
 * So the status is not a judgement call; what is a judgement call is whether to require the header
 * at all, and that decision is argued in
 * `SubscriptionPlanResource` where a
 * caller will read it.
 *
 * ## Why this is a second marker and not the same one as `412`
 *
 * Two statuses cannot share an entry in `exception_to_status`, which maps a class to one integer,
 * and the honest shape of that file is one marker per status. The alternative considered was a
 * single `PreconditionFailedThrowable` answering `412` for both cases, on the argument that "the
 * precondition is unmet" covers an absent header as well as a stale one.
 *
 * It loses on what the caller does next, which is the only thing a status is for. A `412` tells a
 * client **retry after re-reading**, and a client that never sends `If-Match` will re-read and fail
 * identically, forever. A `428` tells them **send the header**, which is a change to their code
 * rather than to their data. RFC 9110 section 13.1.1 reserves `412` for a precondition that was
 * *evaluated* and failed, so answering it for one that was never stated is also simply wrong.
 *
 * The cost is honest: two markers, two `SharedFailure` cases and four catalogue entries for one
 * endpoint. It is paid once, and the second endpoint that wants a conditional write pays nothing.
 *
 * ## It carries no parameters, like ADR 008's two refusals
 *
 * {@see SharedFailure}'s `NotAuthenticated` and `Forbidden` take none, because there is nothing the
 * reader could use. This one is the same shape for the opposite reason: what the caller has to
 * change is the *request*, and naming the header in the sentence is the catalogue's job rather than
 * a parameter's. ADR 007's rule, in its own words, is that a parameter is a value the sentence talks
 * about and never a piece of the sentence.
 */
interface PreconditionRequiredThrowable extends TranslatableThrowable
{
}
