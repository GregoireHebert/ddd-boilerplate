<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * **The caller stated which version they were editing, and it is not the one we hold.** `412`.
 *
 * It is the seventh marker in this directory and the first one that is about the *request* rather
 * than about a payload, a subject or a policy. A caller sends `If-Match` carrying the validator a
 * read handed them; by the time the write runs, somebody else has moved the thing. Nothing is wrong
 * with what they sent, nobody is missing a permission, and the object they named exists. The only
 * thing that is wrong is that their copy is old.
 *
 * ## Why it is not one of the six that already exist
 *
 * It was tried against each of them and each one is a different sentence:
 *
 * | marker | what it says | why it is not this |
 * |---|---|---|
 * | {@see ConflictThrowable} (`409`) | "the world disagrees with your request" | a `409` is about the *state*: that handle is taken, that subscription is already cancelled. Retrying with the same body never helps. A `412` is about the *copy*: re-read, and the identical body usually succeeds |
 * | {@see DomainUnprocessableThrowable} (`422`) | "your payload is fine, the state is not" | same objection, plus it is the status this API uses for things a caller fixes by editing the body, and there is nothing in the body to edit |
 * | {@see FailedDependencyThrowable} (`424`) | "somebody else's system said no" | `BillingConflictException` is that one, and the two genuinely co-occur on `PATCH /subscriptions/{subscriptionId}`. A `412` is **our** refusal, decided before a provider is asked; a `424` is the provider refusing the same way one layer further out. Flattening them is how a caller learns to retry the wrong thing |
 * | {@see BadRequestThrowable} (`400`) | "I could not work out what you asked" | the header parsed perfectly. It just does not match |
 *
 * ## It is in `Shared` and not in a context
 *
 * A conditional request is an HTTP mechanism and no bounded context owns one; `Membership` does not
 * know that `If-Match` exists and must not learn. What a context owns is the **validator**, and on
 * ADR 003's path 4 that is `Subscription::etag()`, which is the
 * billing provider's own ETag carried on the aggregate. Comparing the two is the edge's job, and
 * `ChangeSubscriptionPlanProcessor` is where it happens.
 *
 * ## ADR 003 names `$versionBeforeHandling` and this does not use it
 *
 * ADR 003 says `$versionBeforeHandling`, populated when an aggregate carries `#[Version]`, is the
 * optimistic-locking hook and that it "maps cleanly onto conditional requests". That is true and it
 * is not what this tree does, for a reason
 * {@see \App\Shared\Infrastructure\Ecotone\Repository\TrackedAggregateRepository} wrote down before
 * any endpoint existed: **the providers in this project hand out an ETag that the aggregate carries
 * itself, which survives a round trip through a queue in a way a per-message integer does not.**
 *
 * Adding `#[Version]` to `Subscription` on top of that would mint a *second* version number,
 * incremented by us, for an aggregate whose authoritative half lives at the billing provider and
 * changes without us. The two would disagree the first time the provider moved a subscription
 * between two of our writes, and the one we had incremented is the one that would look fresh. One
 * validator, minted by whoever owns the data.
 *
 * {@see PreconditionRequiredThrowable} is the other half of the mechanism, for the caller who sent
 * no `If-Match` at all.
 */
interface PreconditionFailedThrowable extends TranslatableThrowable
{
}
