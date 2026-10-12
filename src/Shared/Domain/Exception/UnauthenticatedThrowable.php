<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * ADR 008, question 1: nobody is asking, and something needed somebody to be.
 *
 * `401`, mapped in `config/packages/api_platform.php` like the other markers in this directory. It
 * is the seventh of them and the second that **no domain ever raises**, which is worth noticing
 * rather than glossing: these interfaces are a *status* vocabulary, not a domain one.
 * {@see BadRequestThrowable} was already in that position, declared here and implemented only by
 * {@see \App\Shared\Application\ReadModel\BadFilterInput} in the Application layer, and
 * {@see \App\Shared\Application\Security\NotAuthenticated} sits in exactly the same place for
 * exactly the same reason: a domain has no idea that authentication exists and must not learn.
 *
 * ## It usually never gets thrown
 *
 * In the common case the firewall answers `401` before a line of ours runs, and nothing constructs
 * this. What constructs it is {@see \App\Shared\Infrastructure\Security\Actor::subject()}, on a path
 * that allowed an anonymous caller through and then reached for a subject anyway. Raising it there
 * rather than returning null is the point of that method: "the subject of a write is the principal"
 * stops being a convention somebody remembers and becomes a refusal at the one place where the
 * absence is knowable.
 *
 * ## It is the one thing `POST /rpc` does not answer with a `200`
 *
 * ADR 006 is categorical that the RPC endpoint puts its whole failure taxonomy inside a `200`.
 * ADR 008 amends it by this case and no other: RFC 9110 requires a `401` to carry
 * `WWW-Authenticate`, a JSON-RPC response object has nowhere to put a challenge, and a caller
 * learning they must authenticate from a field inside a `200` learns it from a response every HTTP
 * client in the world has already treated as success. So there is no `ErrorCode` for this one, by
 * decision, and {@see \ApiPlatform\JsonRpc\JsonRpcServer} re-throws it.
 *
 * {@see ForbiddenThrowable} is the opposite and stays inside the `200`, because that one really is
 * this application deciding.
 */
interface UnauthenticatedThrowable extends TranslatableThrowable
{
}
