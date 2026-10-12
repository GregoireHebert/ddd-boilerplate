<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * ADR 008, question 2: somebody is asking, and somebody like them may not do this.
 *
 * `403`, and `-32005` on the RPC surface. The narrowest of the markers in this directory, and the
 * discipline is in what it is *not* used for:
 *
 * - **not for ownership.** "This pair is not yours" answers `404` through
 *   {@see DomainNotFoundThrowable}, identically to a pair that never existed, because a `403`
 *   confirms that a given identifier is real. That decision was taken on two cards before there was
 *   an ADR to take it in, and ADR 008 made it rule 11;
 * - **not for an entitlement.** "Your plan does not include this" answers `422` through
 *   {@see DomainUnprocessableThrowable}, carrying what would lift it, because the caller can act on
 *   it and because a `403`-shaped refusal sells nothing;
 * - **not for state.** "Already cancelled", "already extended", "the line is closed" are `409` or
 *   `422`. Nothing about the caller changed; the world did.
 *
 * What is left is a genuine policy refusal, which is the only one of the four questions a Keycloak,
 * a Casbin or an OPA is for, and the only one whose answer can be taken without reading anything of
 * ours.
 *
 * ## It says nothing
 *
 * No permission name, no identifier, no hint about what exists. Telling a caller which permission
 * they lack publishes the shape of the policy to somebody who has just demonstrated they are not
 * entitled to know it; the permission belongs in the log line, next to the actor and the operation,
 * where somebody debugging can use it. ADR 007's rule that a failure can only leak what it hands
 * over is why {@see \App\Shared\Application\Security\Forbidden} takes no parameters at all.
 */
interface ForbiddenThrowable extends TranslatableThrowable
{
}
