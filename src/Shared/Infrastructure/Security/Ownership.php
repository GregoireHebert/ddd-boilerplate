<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Models\Id;
use App\Shared\Domain\Models\PrincipalAggregate;

/**
 * ADR 008 question 3, in one place, so that every operation that owes it answers it the same way.
 *
 * > | 3 | is this one yours | the object | the handler, or the provider that scoped the read | `404` |
 *
 * {@see \App\Ui\Api\StateProvider\QueryParameters} is this class's sibling and the shape to read
 * first: a `final` class with a private constructor, static methods, no state and no dependencies,
 * holding the mechanics an edge repeats and none of the vocabulary. That one turns a string into a typed value and raises the
 * status that fits where the string came from; this one compares two identifiers and raises the
 * status that says nothing. **Both exist because an edge has work that is identical everywhere and a
 * decision that is different everywhere, and only the first of those may be shared.**
 *
 * ## Why this is not the aggregate's job, although a plan said it was
 *
 * `.claude/plans/membership/03_change-your-handle.md` decision 5 says that what stops you renaming
 * somebody else is "question 3, answered by the aggregate, as `404`". The status is right and the
 * home is not, and the reason is three lines further up in the same ADR rather than anywhere
 * subtle: `RenameMember` carries an `Id<PrincipalAggregate>` and a
 * `Handle`, and **an aggregate cannot answer a question about an actor it was never told about**.
 *
 * Telling it is not a smaller change than it looks. ADR 008 rule 2 is that the actor is an argument
 * and never ambient, so the actor would have to travel in the command; rule 6 says a command carries
 * an actor only when the aggregate writes one down, and a rename writes down a handle; rule 8 says
 * the bus is not the firewall, and a command that is refused by its own handler on the strength of a
 * field is a firewall on the bus, reached by every delivery including the asynchronous ones, where
 * ADR 008 rule 7 says there is no actor at all. Four rules move for one `if`.
 *
 * ADR 008 anticipated this, which is why its own table does not say "the aggregate" but
 * **"the handler, or the provider that scoped the read"**, and why its sentence elsewhere is
 * "ownership at the aggregate **or a scoped provider** (404, never 403)". The aggregate answers when
 * it is already open and already holds the relation,
 * `Pair::includes()` being the worked example: a pair *contains* two
 * members, so "is this one yours" is a question about the object's own state. A `Member` does not
 * contain its reader. The relation in `PATCH /members/{memberId}` is not a fact about the member, it
 * is identity between two things the edge is holding at the same moment, and the edge is the only
 * place in the request where both are in scope.
 *
 * So the plan is wrong about where and right about what, and the checklist item 6 in ADR 008 ("let
 * the aggregate answer") reads as the first choice rather than the only one. Its second sentence is
 * the part that holds everywhere: **do not add an `if` at the edge that answers `403`.** This is an
 * `if` at the edge that answers `404`.
 *
 * ## Static, and taking the actor, rather than a service holding a `CurrentActor`
 *
 * A service would be the obvious shape and it cannot be written. {@see CurrentActor} has three
 * implementations and **nothing is aliased to the interface**, deliberately: aliasing one would make
 * the other two exceptions to it, and the table in that interface is the argument. A service here
 * would have to name one of them in its own constructor, which means an `Ownership` written for the
 * API silently reads token storage when the console calls it, and token storage on the console is
 * empty. The failure is the worst available kind: it compiles, it passes in a request, and it is
 * wrong in a worker.
 *
 * Taking the {@see Actor} as an argument is ADR 008 rule 2 applied to this class rather than quoted
 * by it. The caller already holds an actor, because the caller is the one thing that knows which
 * delivery it is, and the comparison needs nothing else. No state, no dependency, nothing to inject,
 * so no container entry and no second opinion about which delivery is the default.
 *
 * ## Why `404`, and never `403`
 *
 * A `403` says "this exists and you may not", which answers the caller's question for them: an
 * enumerating client learns that the identifier is real, and a sweep over identifiers becomes a
 * membership oracle over the whole table. A `404` says nothing. That is ADR 008's refusal table,
 * third row, "authenticated, not the owner, learns nothing, deliberately", and its rule 11.
 *
 * The constructor is the caller's own `::unknown()` and there is no named variant,
 * because **the refusal has to be indistinguishable from the one a member who never existed gets**,
 * including in its text. ADR 007 already took the identifier out of that message and out of
 * `parameters()`, which is what makes this free: there is nothing in the rendered failure that could
 * differ between the two cases, in any locale, because a failure can only leak what it hands over.
 * ADR 008 rule 14, "a refusal says nothing", and ADR 007's ninth, meeting on one exception class.
 *
 * ## Why this is not a middleware, an interceptor or an attribute
 *
 * It is the obvious next move and it is the wrong one. Any of those three has to decide, for every
 * operation carrying a `{memberId}`, whether ownership is owed, and **the answer genuinely differs
 * per operation**: `GET /members/{memberId}` is a public directory entry that any authenticated
 * member may read, `PATCH /members/{memberId}` is the same URI and owes everything. A mechanism that
 * guessed from the segment name would have to be told it is wrong on the first of those, by an
 * annotation, which is the attribute again with an extra step.
 *
 * An explicit call per operation is **enumerable by reading**, and the grep is
 * `grep -rnE 'Ownership::orNotFound|ownSubscription\(|includes\(' src/`. It is three patterns and
 * not one, which is the honest version of a claim this docblock used to overstate: two operations
 * answer question 3 without going through this class and both are right to.
 * `ChangeSubscriptionPlanProcessor::ownSubscription()` raises
 * `membership.subscription-not-found` instead, because on `/subscriptions/{subscriptionId}` that is
 * what every other `404` says and a different code would make this one distinguishable; and
 * `DELETE /pairs/{pairId}` answers it in the handler, through
 * `Pair::includes()`, which is the aggregate case this docblock
 * names two sections above as the first choice.
 *
 * So the set is still enumerable and still readable, and it is three greps rather than one. What
 * matters is the property, not the pattern: an operation that answers question 3 does it in a line
 * somebody can point at, and an operation missing from all three is missing in a way a reviewer can
 * see. A silent default is not enumerable, and its gaps are the ones nobody finds. This repository has chosen an enumerable set over a clever mechanism
 * three times already, in {@see \App\Ui\Console\MessagesCommand} (every failure code, found by
 * walking `cases()` rather than by extracting from an AST), in
 * {@see \ApiPlatform\Metadata\DeclaredFiltersFactory} (every filter, declared
 * once and projected into each delivery) and in {@see Guarded} with
 * {@see \App\Ui\Console\AccessCommand} (every permission, read as an attribute and never as a
 * generated expression). This is the fourth, and it is the cheapest of them: one call, one comment.
 *
 * The cost is honest and worth stating. Nothing fails when somebody forgets the call, which is
 * exactly the gap `app:access` cannot close today, and the FINDINGS of the commit that added this
 * class say what a declaration would have to look like before it could.
 *
 * ## What it does not answer
 *
 * Not question 2. The permission is already decided, by {@see Guarded} on the resource, before any
 * processor runs, and asking the gate here would be a second answer to a settled question.
 *
 * Not question 4. "Your plan does not cover this" is entitlement, it is read from a local
 * projection, it answers `422` carrying what would lift it, and it is a different question with a
 * different home precisely because it is not about who owns anything.
 *
 * And not scoping. ADR 008 rule 12 says scoping is a criterion rather than a filter applied
 * afterwards, so a *list* that is the actor's own passes the actor into the query object and gets an
 * empty page rather than a refusal. This class is for the case where the object is addressed by
 * identity, in the URI, and there is exactly one row to compare.
 */
final class Ownership
{
    private function __construct()
    {
    }

    /**
     * The identifier back when it is the actor's own, and the caller's not-found when it is
     * not.
     *
     * It returns rather than answering `void` so that the call wraps the parse instead of sitting
     * beside it, which keeps the guarded identifier and the guard in one expression and leaves no
     * unguarded local for the next line to reach for:
     *
     * ```php
     * $memberId = Ownership::orNotFound(
     *     QueryParameters::identifierFrom('memberId', (string) ($uriVariables['memberId'] ?? ''), Member::class),
     *     $this->actors->actor(),
     * );
     * ```
     *
     * {@see Actor::subject()} is what raises the `401` for an anonymous caller and the `403` for a
     * principal who is not a member, so those two refusals keep their own home and this method has
     * one branch. It is called before the parse's own `400` could matter and after it has happened,
     * which is the right order: a malformed segment is not a confidentiality question, and telling a
     * caller that `banana` is not an identifier reveals nothing about anybody.
     *
     * ## Why the refusal is an argument
     *
     * The *rule* is shared: ownership answers `404`. The *failure code* is not, because ADR 007
     * keys every message on a declared code owned by a context, and `Shared` may not name one. So
     * the caller hands in the constructor of its own not-found, as a first-class callable:
     *
     * ```php
     * Ownership::orNotFound($id, $actor, MemberNotFoundException::unknown(...));
     * ```
     *
     * It costs one argument and buys the property this class exists for: the comparison, the
     * status and the silence are written once, and **nothing under `Shared` knows what this
     * application calls its principal.** A `\Closure` rather than a built exception so the cost is
     * paid only on the refusing path.
     *
     * @template T of PrincipalAggregate
     *
     * @param Id<T>                  $inThePath
     * @param \Closure(): \Throwable $unknown the context's own not-found, taking no identifier,
     *                                        because ADR 007 rule 9 says a caller learns nothing
     *                                        from one
     *
     * @return Id<T>
     */
    public static function orNotFound(Id $inThePath, Actor $actor, \Closure $unknown): Id
    {
        if (!$inThePath->equals($actor->subject(PrincipalAggregate::class))) {
            throw $unknown();
        }

        return $inThePath;
    }
}
