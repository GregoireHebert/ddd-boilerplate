<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

/**
 * ADR 008 question 2, and only question 2: *may somebody like this actor do this at all.*
 *
 * ## The name is Symfony's on purpose
 *
 * A different verb would imply a different question, and it is the same question. What differs is
 * that the actor is an **argument** rather than something read from the air, and that difference is
 * visible in the signature at every call site: somebody who knows `isGranted($attribute)` reads
 * `isGranted($actor, $permission)` and has already understood the design.
 *
 * It also reads as a question, which the obvious alternatives do not. `$gate->permits($actor, ...)`
 * and `$gate->allows($actor, ...)` are sentences the gate asserts; this is one it answers, and
 * inside an `if` only one of those is in the right mood.
 *
 * ## This is not an anti-corruption layer
 *
 * Worth saying plainly, because a port over a framework component usually is one. The firewall, the
 * authenticator, the user provider, the token, the roles, the voters and the access decision
 * manager are all Symfony's, and every policy engine this design admits (Casbin, Keycloak, OPA)
 * plugs in as a voter, which is that component's own extension point working as documented.
 * Deleting this interface would still leave a swappable system.
 *
 * It exists because two *defaults* of the component are right for a controller and wrong for three
 * of our four deliveries:
 *
 * 1. **the actor comes from `TokenStorage`.** A console command has no request and a worker has no
 *    request. The way out without a port is to push a token into storage and then call
 *    `isGranted()`, which works and which makes an operator assertion indistinguishable from a
 *    login for the rest of the process;
 * 2. **the attribute is `mixed`.** `isGranted('ROLE_ADMIN')`, `isGranted('matching.like.cats')` and
 *    `isGranted(null)` are all accepted with equal enthusiasm. Here the first is a type error, the
 *    second is a missing enum case and the third is impossible.
 *
 * {@see AccessDecisionGate} is the only implementation and it is about fifteen lines, because
 * {@see \Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface} is the one
 * interface in the component that takes the token as an argument.
 *
 * And the scoping, so this is not read as a universal recommendation: **a single-delivery
 * application should use API Platform's `security:` expressions and stop there.** This is overhead
 * until there is a second delivery that has no request.
 *
 * ## There is no third argument
 *
 * A gate that took `(actor, permission, object)` is a gate somebody will hand an aggregate to, and
 * the first time that happens the edge is loading the aggregate to ask a question the handler is
 * about to ask again. Then the rule has two homes, which is the one thing ADR 005 spent a section
 * forbidding, and the second home is in a policy language PHPStan cannot read and `git grep` cannot
 * find.
 *
 * The absent argument is ADR 008's four-question rule made unwritable, and it is the one place this
 * port is deliberately *narrower* than the interface underneath it, which takes an object and
 * invites one. {@see PermissionVoter} closes the same door from the other side, for the callers
 * that arrive through an `is_granted()` expression rather than through here.
 *
 * ## What does not come through here
 *
 * Ownership ("is this pair yours") and entitlement ("does your plan include it"). Both need this
 * application's store, so both are the domain's, and they answer `404` and `422` respectively.
 * {@see \App\Shared\Domain\Exception\ForbiddenThrowable} lists all three cases and where each goes.
 */
interface Gate
{
    public function isGranted(Actor $actor, Permission $permission): bool;
}
