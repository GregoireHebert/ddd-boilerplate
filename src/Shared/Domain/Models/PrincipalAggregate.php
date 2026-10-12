<?php

declare(strict_types=1);

namespace App\Shared\Domain\Models;

/**
 * The aggregate this application authenticates as, named here so that `Shared` can talk about a
 * principal without naming a bounded context.
 *
 * ADR 008 put the security vocabulary in `Shared\Infrastructure\Security`, and ADR 002 types every
 * identifier `Id<A>` where `A` is the aggregate. Those two decisions met badly: an `Actor` holding
 * an `Id<Member>` makes `Shared` depend on `Membership`, which is the oldest rule in the tree, and
 * phpat said so in sixteen errors. **The phantom type is not the problem; the aggregate named in it
 * was.** So the shared half names this marker and the context implements it.
 *
 * It is deliberately empty. A principal has no behaviour in common across applications; what it has
 * is an identity, and `Id<PrincipalAggregate>` is exactly that claim and no more. Giving this
 * interface a method would be inventing a requirement for somebody else's aggregate.
 *
 * **Reusing this tree elsewhere means implementing it on your own principal** (a `Member`, a
 * `Player`, a `Customer`, a `User`) and changing nothing under `Shared`.
 *
 * The usual ADR 002 caveat applies and is the whole reason this works: `Id` is one Ulid-wrapping
 * class regardless of its phantom `@template`, so this costs nothing at runtime and is checked by
 * PHPStan or not at all.
 */
interface PrincipalAggregate
{
}
