---
name: guarding-an-operation
description: Add authentication, permissions, ownership or entitlement checks in this DDD/CQS tree, following ADR 008 - four questions with four different homes, ask for a permission and never for a role, the actor as an argument rather than ambient, 404 over 403 for ownership, scoping a read as a criterion, and what is built versus still open. Use before guarding any endpoint, adding a Permission case, editing security.php or the firewall, or deciding which status a refusal answers with.
---

# Guarding an operation

The argument is `docs/ADR/008_security.md`, and ADR 009 rules 8, 13 and 14 carry
the rest. This was the ADR written entirely ahead of its code; **most of it has
since been built**, so check the state below rather than either assumption.
`php bin/console app:access` walks every operation and what guards it.

## Four questions, four homes

|    | Question                      | Decided                               | Refused with          |
|----|-------------------------------|---------------------------------------|-----------------------|
| 1  | are you anybody               | the firewall, before our code         | `401`                 |
| 2  | may somebody like you do this | the edge, through the gate            | `403`                 |
| 3  | is this one yours             | the aggregate, or the scoped provider | `404`                 |
| 4  | does your plan cover it       | a local projection, in the handler    | `422` + what lifts it |

> **What does the answer have to read?** If it can be taken from the actor's own
> grants, it is the gate's. If it needs this application's store, it is the
> domain's.

Only question 2 is what a Keycloak, a Casbin or an OPA is for. A policy engine
that answers 3 or 4 has had to be fed this application's data, which is how it
becomes a second, worse copy of the domain, in a language PHPStan cannot read
and `git grep` cannot find.

## The two rules that do the work

> **Ask for a permission, never for a role.** A role is somebody's *answer* to
> the question, and naming one at a call site hard-codes the subsystem you were
> planning to replace. `RoleMap` is the only file that names one.

> **The actor is an argument, never ambient.** Four deliveries, two of which
> have no request, no session and no token storage.

This is why the adapter depends on `AccessDecisionManagerInterface` (which
*takes* a token) and not `AuthorizationCheckerInterface` (which reads one from
storage). A console command pushing a token into `TokenStorage` works, and makes
an operator assertion indistinguishable from a login for the rest of the
process.

```php
interface Gate { public function isGranted(Actor $actor, Permission $permission): bool; }
```

The name is Symfony's on purpose. **Note what the signature does not take: a
subject.** A gate taking `(actor, permission, object)` is one somebody will hand
an aggregate to, and then the edge loads it to ask a question the handler is
about to ask again. The absent third argument is the four-questions rule, made
unwritable.

## Where it lives, and what exists

`src/Shared/Infrastructure/Security/`. It used to be `Ui/Infrastructure/Security/`
and the argument for that placement still holds in the part that matters:
**the vocabulary is Infrastructure, so a handler cannot call the gate**, because
a context's `Application` may name `App\Shared\Application` and
`App\Shared\Domain` and not `App\Shared\Infrastructure`. The three
`Application` files that mention `Gate`, `Actor` and `Ownership` mention them in
a `{@see}` and import nothing.

**The move did break the principal's type, and the fix is the thing to learn
here.** `Actor`, `Credential`, `AccessToken`, `AccessTokens`, `Credentials`,
`CredentialProvider` and `Ownership` all typed a principal `Id<Member>`, so
`Shared` depended on a context and phpat said so in 16 errors. The resolution is
`Shared/Domain/Models/PrincipalAggregate`, an empty marker the context's
aggregate implements:

```php
final class Member implements PrincipalAggregate { }          // the context opts in
public ?Id $principalId;                                      // Id<PrincipalAggregate>, in Shared
$actor->subject(Member::class);                               // the edge narrows, once, named
Ownership::orNotFound($id, $actor, MemberNotFoundException::unknown(...));
```

Three rules fall out, and breaking any of them puts `Member` back in `Shared`:

- **`Id`'s `A` is `@template-covariant`**, so an `Id<Member>` is usable where an
  `Id<PrincipalAggregate>` is wanted. Sound because `A` is in no parameter
  position; the one place it was, `Id::sort()`, takes `self<*>`;
- **narrowing is declared, never commented.** `subject()` takes a
  `class-string<T>` it does not read, purely so PHPStan resolves `Id<T>`. Do not
  write `@var Id<Member>` at a call site instead;
- **the rule is shared, the failure code is not.** `Ownership::orNotFound()`
  takes the context's own `::unknown(...)` because ADR 007 keys every message on
  a code a context owns.

**Reusing this tree means implementing `PrincipalAggregate` on your own
principal** (a `Member`, a `Player`, a `Customer`, a `User`) and passing it to
`subject()`. Nothing under `Shared` changes.

**Shipped, and that is nearly all of it:**

| half           | classes                                                                                                                                                                                          |
|----------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| vocabulary     | `Actor`, `Authority`, `PrincipalKind`, `Permission` + `Permission/{Membership,Matching}Permission`, `Permissions`, `Ownership`                                                                   |
| the port       | `Gate` with its one `AccessDecisionGate` adapter, `PermissionVoter`, `RoleMap`                                                                                                                   |
| declaration    | `#[Guarded]`, `#[Unguarded(because:)]` (ADR 009 rule 13), `GuardedOperationsFactory`, `RpcMethod::permission()`, `bin/console app:access`                                                        |
| who is asking  | `CurrentActor` (the port) with `TokenCurrentActor`, `SessionCurrentActor` and `Ui/Console/Security/AssertedActor`                                                                                |
| authentication | `Credential`, `CredentialProvider`, `Credentials`, `SecurityUser`, `JwtTokenHandler`, `AccessToken(s)`, `RevokedToken(s)`, `AccessTokenGrant`, `BearerTokenRequestMatcher`, `SecurityEntryPoint` |
| failures       | `UnauthenticatedThrowable`, `ForbiddenThrowable`, their failures in `Shared/Application/Security/`, `ErrorCode::Forbidden = -32005`                                                              |
| enforcement    | phpat's `no_security_outside_ui`, which excludes `App\Shared\Infrastructure\Security` by name                                                                                                    |

`config/packages/security.php` is **written, not the recipe**: two firewalls told
apart by the credential (ADR 009 rule 8), a `credentials` provider over the
`ui_credential` table, `access_token` with `JwtTokenHandler` on the `api`
firewall, `form_login` and a session on the `web` one, and no in-memory `admin`.
The console issues and revokes: `app:credentials:issue`, `app:tokens:issue`,
`app:tokens:revoke`.

The subject now comes from the principal on `POST /likes`, `POST /blocks` and on
`membership.acquireBadge`, `loseBadge` and `discardBadge`.

**Still open:** moderators and therefore `Trust` and `Actor::moderator()`, the
device authorization grant, the RFC 9457 `401` body, the principal's type above,
and the fact that **no test proves any of it**. `app:access` proves that every
operation names a permission or declares itself unguarded, and that every
permission is both granted to somebody and asked by something. That is exactly
as much as `app:messages` proves about the catalogue: that nothing is missing,
never that anything is right.

## Adding a guarded operation

1. **Decide which of the four questions your refusal is.** If the answer has to
   load anything of ours, it is not a permission and the rest of this list is
   the wrong list.
2. **Add a case to your context's `Permission` enum.** Context prefix, dots,
   verb last: `matching.like.cast`. Declared, never derived from a class or a
   route name. This is the fourth declared string vocabulary in the tree and it
   deliberately does **not** reuse the others: a routing key names a handler, a
   permission names a capability a delivery exposes, and they differ wherever
   one command backs two operations with different audiences, and wherever an
   operation exposes no command at all, which is every read.
3. **Put `#[Guarded(YourPermission::Case)]` on the operation.** Do not
   hand-write a `security:` expression; a hand-written one is one `app:access`
   cannot see. If the operation genuinely has no actor, say so with
   `#[Unguarded(because: '...')]` rather than leaving it bare: ADR 009 rule 13
   exists so `app:access` can tell a decision from an oversight, and
   `POST /members` is the first of the former. On a `Ui/Web` controller the
   attribute goes **on the class**, which is why one controller is one route and
   one `__invoke()` (ADR 009 rule 14).
4. **Take the subject from `CurrentActor::actor()->subject()`**, never from the
   body and never from the URI. If you are reading it from the payload, either
   the operation is not self-scoped and needs a second permission, or it should
   not exist.
5. **For a read, pass the actor into the query object as a scoping value** and
   make sure the declaration does not publish it. Check the OpenAPI export, not
   the code.
6. **For ownership, let the aggregate answer**, with `::unknown()` and a
   `DomainNotFoundThrowable`. No `if` at the edge answering `403`, and no
   `object.` in an expression.
7. **Grant it in `RoleMap`.** That file is the policy, and it is the only file
   that would be deleted the day a policy server arrives, which is the test of
   whether anything else leaked.
8. `bin/console app:access`, then `bin/console app:messages`.
9. **Do not touch the gate.** If you needed to, the question was not question 2.

## Option A versus option B

**Option A**, API Platform's `security:` expression, is kept for reads whose
audience is "anybody authenticated", and mostly because it is what every
tutorial shows. It is a string PHPStan cannot see, it names a role as a literal,
it answers `403` (right for question 2, wrong for question 3, and `object.` is
one dot away), and the console gets nothing from it.

**Option B, the declared permission, for every write, without exception**,
because a write is what has to be enumerable and writes are where the four
deliveries diverge. This is ADR 004's option B mechanism for mechanism, and the
second user of machinery this tree already pays for.

## The refusal table

| the caller is                                 | refused with              | and learns                  |
|-----------------------------------------------|---------------------------|-----------------------------|
| not authenticated                             | `401`, `WWW-Authenticate` | that authenticating is next |
| authenticated, lacks the permission           | `403`                     | something about themselves  |
| authenticated, not the owner                  | `404`                     | nothing, deliberately       |
| authenticated, owner, wrong plan              | `422` + what lifts it     | what to buy                 |
| authenticated, owner, right plan, wrong state | `409` or `422`            | ADR 005 decided this        |

**`404` over `403`, and the scoped provider is what makes it free.** A `403`
confirms that an identifier is real. If the provider asks the read model for
"this pair, for this actor", a pair that is not yours is a row that does not
come back, the processor is never reached, and `404` is what *happens* rather
than what somebody remembered to write. ADR 007 already took the identifier out
of the message, so a caller cannot tell the two cases apart from the text
either.

**An entitlement is an upsell, not a wall.** `422` with what would lift it. A
write refused by an entitlement answers `422`; a read *narrowed* by one answers
`200` with the representation the plan includes, because a `403` would take away
the count and the count is the entire upsell.

**A refusal says nothing**: no permission name, no identifier, no hint about
what exists.

## Reading

**Scoping is a criterion, never a filter applied afterwards.**

```php
$mine = array_filter($rows, fn ($r) => $r->memberId === $actor->subject());  // no
$this->ledger->sentBy($actor->subject(), $window);                          // yes
```

The first built a collection containing rows the caller may not see, and it now
exists in memory, in the profiler, in a log line, and in the first `json_encode`
somebody adds.

> **A scoping value is never an exposed parameter.** Not a `QueryParameter`, not
> an `InputOption`, not a form field. A caller who can supply it can read
> somebody else's rows.

**One audience, one shape.** Authorization may choose a *representation* by
choosing a query: one method per audience on the read port, each returning its
own DTO. Never by building the full DTO and nulling fields, which is a contract
promising data it will usually withhold.

## Asynchronous

> **A command is authorized once, where it is accepted. An event is never
> authorized at all**, because an event is a consequence.

**There is no `Actor::system()` and there will not be one.** An operation with
no actor is *unguarded*, which is a property of the operation; the absence of a
principal is not a principal.

**The bus is not the firewall.** An `#[Around]` interceptor on `CommandBus`
looks tempting and breaks on a handler that already exists:
`SubscriptionMaintenanceService::closeExpired()` dispatches through the same bus
from inside another command with no actor anywhere, so a system principal gets
invented, granted everything, and the design is over.

**Freshness is a domain rule.** The window was always open: a synchronous
request checked at T and wrote at T+40ms. Where the gap matters, the handler
**re-reads the state a decision depended on, never the grant** - re-reading the
grant needs the principal in the worker, which needs a credential in the
message. A message that may wait carries what makes its own staleness
answerable, the way `expiredBefore` already does.

**A suspension is state, not a revoked role.** A role lives in the identity
provider: re-checking it needs the provider from the worker, it cannot
participate in our transaction, it cannot be a domain event, and it cannot be
reverted by the mechanism that set it. Implement it as `ProfileStatus::Suspended`.

Never in a message: a token, a session id, a role, a grant list, or an `Actor`.
The subject travels as an `Id<Member>`; the actor travels only when the
aggregate **writes it down** as part of the fact, and the test is whether
removing the field breaks the audit or breaks the check.

## The console

- **operator commands** (`app:outbox:relay`, `app:paths`, ...): no actor, no
  gate call, no `--as`. They act as the installation.
- **commands acting for somebody**: an explicit `--as <memberId>`,
  `Authority::Assertion`, and an audit line naming both.
- **`--as` is root.** Anyone who can run the console has the database
  credentials. It is not dressed up as authentication and does not consult the
  gate for permission to impersonate, because a check whose subject can rewrite
  the check is theatre.

## Two things that stay Symfony's

**We do not re-render an authentication failure.** It happens before any of our
code runs, so there is no domain exception; Symfony's `security` catalogue
renders it in fifty-seven locales. What we own is the *shape*: a `401` must
leave as RFC 9457 like every other failure.

**A framework interface on an aggregate is a delivery concern that has found a
way into the model.** `Member` does not implement `UserInterface`; `SecurityUser`
does, in `Shared/Infrastructure/Security/`, and knows nothing about badges. Three ADRs have now removed such a
widening (validator, translator, security).
