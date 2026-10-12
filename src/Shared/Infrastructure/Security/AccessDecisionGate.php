<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * The only implementation of {@see Gate}, and it is this short on purpose.
 *
 * `AccessDecisionManagerInterface` is the one interface in Symfony's security component that
 * **takes the token as an argument** rather than reading it out of `TokenStorage`, which is the
 * single technical fact the whole port rests on. `AuthorizationCheckerInterface` sits one layer
 * above it and hard-codes "whatever is in storage", which is right for a controller and useless in
 * the two deliveries that have no request.
 *
 * Everything else is Symfony's, working as documented: the strategy, the voters, and whatever a
 * policy engine's bundle registers alongside {@see PermissionVoter}.
 *
 * Two details in one line of code:
 *
 * - **`wire()`, not the enum.** The attribute crosses into a world of strings here: Symfony's
 *   abstract `Voter::supports()` is typed `string`, Casbin's `enforce()` takes strings, Keycloak's
 *   scopes are strings. A typed enum on our side of the seam, a documented string on theirs, which
 *   is the same trick `FailureCode::code()` plays at the translation seam;
 * - **no third argument.** `decide()` accepts an object and this never passes one. That is ADR 008
 *   rule 4, and {@see PermissionVoter::supports()} enforces the same thing for callers arriving
 *   through an `is_granted()` expression, where the object is handed in whether we want it or not.
 */
#[AsAlias(Gate::class)]
final readonly class AccessDecisionGate implements Gate
{
    public function __construct(
        private AccessDecisionManagerInterface $accessDecisions,
        private ActorTokens $tokens,
    ) {
    }

    #[\Override]
    public function isGranted(Actor $actor, Permission $permission): bool
    {
        return $this->accessDecisions->decide($this->tokens->for($actor), [$permission->wire()]);
    }
}
