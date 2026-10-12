# Security

Four questions hide behind the word, and a design that answers them in one place would answer three of
them wrong.

**Who are you?** Something was presented and recognized, or it was not. **What may somebody like you
do?** A policy about the actor, decided without looking at our data. **Is this one yours?** A fact
about an object in our store. **Does your plan cover it?** A fact about a subscription, which is a
domain rule wearing a lock's clothing.

Only the second is what a Keycloak, a Casbin or a UMA 2.0 authorization server is for. The first is
a protocol, and Symfony has implemented it for fifteen years. The third and the fourth are this
application's own rules, and a policy engine that answers them has had to be fed this application's
data, which is how a policy engine becomes a second, worse copy of the domain.

So the requirement this document was opened for, *swap the subsystem without changing how we ask*,
is not really about the subsystem. It is about keeping the four questions far enough apart that the
second one has somewhere to be replaced. The two rules that do the work fit on one line each:

> **Ask for a permission, never for a role.** A role is somebody's answer to the question; naming
> one at a call site is hard-coding the subsystem you were planning to replace.

> **The actor is an argument, never ambient.** Four deliveries, two of which have no request, no
> session and no token storage. Anything that reads the current user from the air works in one of
> them and lies in the other three.

None of this is built. It is the ADR the board has been blocked on for thirty cards
(the board is the
argument, and `coverage.md` calls it the largest gap on the list), so the shell block below is not
something to poke at yet; it is the acceptance test for the commit that follows this one.

```shell
bin/console app:access                 # every operation on all four deliveries, and what guards it
bin/console app:access --unguarded     # only what nobody decided. Exit 1 if a write is listed

curl -s -i localhost:8000/likes -X POST -d '{"to":"..."}'            # 401, and no `from` in the body
curl -s -i localhost:8000/pairs/01J... -X DELETE -H 'Authorization: Bearer ...'   # 404 if not yours
curl -s localhost:8000/rpc -d '{"jsonrpc":"2.0","method":"trust.suspendMember",...}' | jq .error.code

bin/console app:likes:cast --as 01J...   # an assertion, audited, and never called authentication
```

## Flow

A request arrives. The firewall decides whether it carries a credential it recognizes. A path that
needs one and has none is answered `401` before a line of ours runs, by a component we did not
write.

The delivery resolves an **`Actor`**: a session for the webapp, a bearer token for the API and the
RPC endpoint, an explicit assertion or nothing at all for the console. One class per delivery does
this and nothing else in the process reads an ambient token.

The operation declares a **`Permission`**. The delivery asks the gate, passing the actor as an
argument. A refusal is a `403` and it says nothing about our data.

The write's **subject is taken from the actor**, never from the body and never from the URI. The
command is built out of value objects exactly as ADR 005 says, and it carries no credential, no
role and no token.

A read is **scoped before it is filtered**. The actor is a criterion the read model receives, not a
check applied to rows that already came back, so "not yours" and "not there" are the same answer
because they are the same query.

The handler enforces what needs the store: ownership, from the aggregate it had to load anyway;
entitlement, from a local projection. Those answer `404` and `422`. Neither of them answers `403`
and neither of them asks the gate.

Nothing on the far side of a channel asks again. A command was authorized when it was accepted, and
an event was never authorized at all because an event is a consequence. Where staleness genuinely
matters, it is a domain precondition re-read at handling time, and the message carries whatever
makes its own staleness answerable.

What was decided, for whom, through what, is written down once, at the edge, by the thing that
decided it.

## Architecture

### Four questions, and only one of them belongs to a subsystem

|    | Question                      | What it needs to decide              | Where it is decided                               | Refused with                   |
|----|-------------------------------|--------------------------------------|---------------------------------------------------|--------------------------------|
| 1  | are you anybody               | a credential and a store of them     | the firewall, before our code                     | `401`                          |
| 2  | may somebody like you do this | the actor's grants, and nothing else | the edge, through the gate                        | `403`                          |
| 3  | is this one yours             | the object                           | the handler, or the provider that scoped the read | `404`                          |
| 4  | does your plan cover it       | a local projection of an entitlement | the handler                                       | `422`, with what would lift it |

The test that separates row 2 from rows 3 and 4 is not about vocabulary:

> **What does the answer have to read?** If the decision can be taken from the actor's own grants,
> it is the gate's. If it needs this application's store, it is the domain's.

Casbin for example can express "the owner of the resource may edit it", and Keycloak's UMA 2.0 can express it
too. Both need the resource in order to do so, which means the edge loads the aggregate to feed the
policy engine and the handler loads it again to change it. Now the rule has two homes, which is the
one thing ADR 005 spent a section forbidding, and the second home is in a language PHPStan cannot
read and `git grep` cannot find. The aggregate is where "is this yours" lives, because the aggregate
is already open.

That is also why rows 3 and 4 do not answer `403`. A `403` is an answer about the caller. A refusal
that is about an object in our store either says something about that object, which is a leak, or
says nothing, in which case it may as well be the answer the caller would have got if the object did
not exist. See below; it is a decision the board took twice
(matching/07,
matching/08) before there was an ADR to take it in.

### Ask for a permission, never for a role

`is_granted('ROLE_MODERATOR')` at a call site is not a question, it is an answer that somebody
already committed to. It hard-codes *who* may do the thing into the place that does the thing, and
every subsystem worth swapping to exists precisely to move that decision elsewhere. A role map with
six entries is a fine policy; the mistake is that a call site can see it.

So the vocabulary is a **permission**, declared as an enum case, exactly as ADR 007 declared a
failure code and for the same three reasons: it is stable, it is published, and above all the set of
them is *enumerable*, which is what makes "every write is guarded" a checkable property rather than
a hope.

```php
enum MatchingPermission: string implements Permission
{
    case CastLike = 'matching.like.cast';
    case BlockMember = 'matching.block.place';
    case Unpair = 'matching.pair.unpair';
}
```

Dot-separated, context-prefixed, verb last. This is the fourth declared string vocabulary in the
tree, after routing keys (`membership.registerMember`), failure codes (`membership.handle-too-short`)
and RPC method names (`membership.acquireBadge`), and the obvious economy is to reuse one of them.
Do not: a routing key names a handler, and a permission names a capability a delivery exposes. They
differ wherever one command backs two operations with different audiences
(`SwitchProfileStatus` is sent by a member pausing their own profile and by `Membership` reacting to
`MemberSuspended`), and they differ wherever an operation exposes no command at all, which is every
read. A vocabulary that is right ninety per cent of the time is worse than a separate one, because
the ten per cent is discovered by an incident.

The enum is the compile-time vocabulary. The **string is the wire**, and the adapter converts before
it calls anything, exactly as `FailureCode::code()` does at the other seam. That is not a detail:
Symfony's abstract `Voter::supports()` is typed `string`, Casbin's `enforce()` takes strings, and
Keycloak's scopes are strings. A typed enum on our side of the seam and a documented string on
theirs is the whole trick, and it is the same trick twice in this repository now.

### The actor is an argument, never ambient

Symfony's `AuthorizationCheckerInterface::isGranted()` reads the token out of `TokenStorage`. That
is correct for the delivery it was designed for and it is a trap for this tree, which has four:

| delivery | is there a token in storage?    |
|----------|---------------------------------|
| ReST     | yes                             |
| RPC      | yes, same firewall              |
| Twig     | yes                             |
| console  | **no**, and there must never be |

A console command that wants to act for somebody has two ways out of that table. It can push a token
into `TokenStorage` and then call `isGranted()`, which works, and which makes an operator assertion
indistinguishable from a login for the rest of the process; or the actor can be a parameter. There
is one honest option.

Underneath `AuthorizationCheckerInterface` there is already an interface that takes the token as an
argument:

```php
AccessDecisionManagerInterface::decide(TokenInterface $token, array $attributes, mixed $object = null): bool
```

**We depend on that one.** The checker hard-codes "whatever is in storage"; the manager is given
one. Every voter in the application keeps working, because a voter is handed the token and never
asks where it came from, and the console never touches `TokenStorage` at all.

### A command never carries a credential

ADR 005 rule 6: a command is a proof of its own contents. This ADR adds the corollary it needs:

> A command carries the **subject**, because the aggregate needs it. It never carries the
> **credential**, the role, the token or the actor's grants, because no handler may re-decide.

`CastLike` today takes `fromMemberId` and `toMemberId`, and `POST /likes` takes `from` in the body,
so anybody can cast a like as anybody. Under this ADR the command **does not change**: it still
takes two `Id<Member>`, it still refuses equal ones in its own constructor, and the handler is
untouched. What changes is one line in `CastLikeProcessor` and one property on `LikeCastResource`.
That is the useful measure of whether the seam is in the right place: introducing authentication to
an application should be a diff in the delivery layer and a diff in the published schema, and
nowhere else.

The one case where an actor legitimately appears in a command is when **the aggregate writes it
down**. `Suspension` records who suspended, because that is part of the fact
(trust/03 already says "who decided" is a column). The
test is whether removing the field breaks the audit or breaks the check. If it breaks the check, it
was security state in a command, and it is in the wrong place.

### Where the vocabulary lives, and why it is `Ui`

`Actor`, `Authority`, `Permission`, `Gate` and `CurrentActor` all live under `App\Ui`. That is the
narrow answer and it is deliberate:

- **knowing who is asking is a delivery concern.** The four deliveries answer it four different
  ways, and the Application layer receives an `Id<Member>` and cannot tell which;
- putting the port in `Shared\Application` would let a handler ask, and a handler that *can* ask
  will. Then the gate is called on the far side of the async channel, where there is no actor, and
  the design is gone.

The permission enums live there too, which is the surprising half. A failure code lives in a
context's `Domain` because a domain raises it; a permission is never raised by a domain and is never
read by one. The set of permissions is the set of operations the deliveries expose, which is a
delivery fact, and it pays for itself immediately: with the declarations sitting next to the
operations, `bin/console app:access` can walk API Platform's resource metadata, the RPC
`MethodRegistry` and the console command list, and report every operation that nothing guards.
That is `app:messages`'s trick, applied to the other enumerable set, and it is only possible because
the two halves are in the same place.

```
src/Shared/Infrastructure/Security/
    Actor.php                    who is asking, and how we know. A value              shipped
    Authority.php                Session | Token | Assertion | None                   shipped
    PrincipalKind.php            Member | Moderator | Nobody                          shipped
    Permission.php               interface, extends \BackedEnum                        shipped
    Permission/{Membership,Matching}Permission.php   28 cases                           shipped
    Permissions.php              the registry that finds them, for the voter and app:access
    Gate.php                     the port: isGranted(Actor, Permission): bool         shipped
    CurrentActor.php             the port: actor(): Actor. One per delivery           shipped
    ActorTokens.php              Actor -> TokenInterface, and the seam below fills it shipped
    AccessDecisionGate.php       the adapter, over AccessDecisionManagerInterface     shipped
    PermissionVoter.php          one voter, reading the role map                      shipped
    RoleMap.php                  the demo's policy, the only thing a subsystem replaces
    SecurityUser.php             UserInterface, holding an Actor and roles            shipped
    CredentialProvider.php       UserProviderInterface over the credential table
src/Ui/Console/Security/
    AssertedActor.php            reads --as, and refuses to be anything else
```

`Actor` and `SecurityUser` are values rather than services and `Permission/` holds enums, so
`config/services.php` grows one exclusion line with the same reasoning as the `Shared/Application/`
one already there. Everything else in that directory is a service and `AccessDecisionGate` carries
`#[AsAlias(Gate::class)]`, which is how every other port in this tree is bound.

`Permissions.php` is the one entry the original sketch did not have, and it earns itself twice:
{@see PermissionVoter} uses it so that an attribute nobody declared is not supported and therefore
fails closed, and `app:access` will use it to walk the declared set against the declared operations.
It finds the enums with a `Finder` over one directory rather than from a list, which is the same
refusal to keep a hand-maintained registry that `MessagesCommand` makes for `FailureCode`.

## Authenticating

### The firewall answers one question, because a path regex can only answer one

The brief for this ADR put it as "a query or a command can be secured behind a firewall". It cannot,
and the distance between those two sentences is most of the design. A firewall matches **paths**. A
command is not a path; it is an object on a bus, reachable from four deliveries and from a handler.
`POST /rpc` is one path carrying every RPC method there will ever be, so a rule on that path can say
nothing about any of them.

So `access_control` answers exactly one question: **does this path need anybody at all.** That is
the only question a regex over a URI can answer without being wrong the first time somebody adds a
sub-resource with a different audience, and `GET /members/{id}/reputation` is already that
sub-resource (membership/10 serves three shapes from one URL).

The two entries in `config/packages/security.php` today point at `^/catalogue` and `^/smartphone`,
from a project that is not this one. They go.

Two firewalls, and the patterns must not overlap:

- **stateless**, over the API and `/rpc`: `access_token`, no session, no CSRF, because the API has
  consumers that are not our webapp;
- **stateful**, over the webapp when the Twig delivery lands: a login form, a
  cookie, and CSRF on every write form. A first-party client is what a cookie is for.

A stateful firewall over an API path is how a session cookie gets attached to a cross-origin request
nobody designed, so the separation is a decision rather than tidiness.

### The member is not a user

Symfony wants a `UserInterface` with roles and an identifier. `Membership` has a `Member` with a
handle, a badge and a badge collection. Making the aggregate implement the framework interface is the
shortcut, and it is the exact widening ADR 005 spent a section **removing** for the validator and
ADR 007 removed again for the translator. Three ADRs is enough to call it a rule:

> A framework interface on an aggregate is a delivery concern that has found a way into the model.

So: `SecurityUser`, in `Shared/Infrastructure/Security/`, holding an `Id<Member>`, a hash and a role
list, loaded by a user provider that asks a read model. It implements
`PasswordAuthenticatedUserInterface` and `UserInterface` and it knows nothing about badges.
`Membership` never learns that authentication exists.

### Where the credential is stored is the provider's problem

The user's own framing was right and it is the cheap half: credentials live in memory, in a table,
or in an identity server, and Symfony's `UserProviderInterface` is the seam for all three. It has
been the seam since 2011 and nothing in this ADR improves on it.

The demo ships a `credential` table: a member id, a hash, a created-at, nothing else. ADR 003
**path 1**, a flat row written and read by identity alone, never filtered and never part of any
aggregate's invariants. `docs/storage-map.md` gains a line saying so, including the part that will
raise an eyebrow: **the table is owned by `Ui`, not by a bounded context.** A credential has no
business behaviour, no invariant and no language; giving it a context would produce a context with
one entity and nothing to decide, which is the thing DDD warns about rather than the thing it asks
for.

The trigger that would change that is written down now so it is recognised later: **when credentials
grow invariants**, lockout after N failures, password history, MFA enrolment, recovery codes, they
have a state machine and a language, and at that point they are an `Identity` context and the table
moves with them. Not before.

### Four deliveries, four mechanisms, one `Actor`

```php
interface CurrentActor
{
    public function actor(): Actor;
}
```

One implementation per delivery, and the implementation is the only place in the application allowed
to know how the caller proved anything:

| delivery                 | implementation reads                                      | `Authority` |
|--------------------------|-----------------------------------------------------------|-------------|
| ReST, RPC                | the token on the request, through Symfony's token storage | `Token`     |
| Twig                     | the session, through the same storage                     | `Session`   |
| console, `--as`          | an `InputOption`, and the user provider                   | `Assertion` |
| console, everything else | nothing                                                   | `None`      |

**Amended by [ADR 009](009_one-url-two-representations.md): the first two rows share a firewall.**
I wrote this table assuming a delivery owns its own URLs, so ReST and Twig could have a firewall
each, matched by path. They do not own separate URLs; `GET /members` is one address answering both
`text/html` and `application/ld+json`, and a Symfony firewall cannot be matched by `Accept`. So
there is one firewall carrying both authenticators, a form login with a session and an access token
on the `Authorization` header, and the table above is still exactly right about what each
implementation reads and which `Authority` it stamps. Two authenticators, two `CurrentActor`
implementations, one firewall. What the table describes did not change; where it is configured did.

```php
final readonly class Actor
{
    public static function member(Id $memberId, Authority $via): self;
    public static function asserted(Id $memberId): self;
    public static function anonymous(): self;

    /** @return Id<Member> */
    public function subject(): Id;   // throws NotAuthenticated when there is nobody
}
```

`subject()` throwing rather than returning null is the point of the class. "The subject of a write is
the principal" stops being a convention somebody remembers and becomes a `401` raised by the value
object, at the one place where the absence is knowable. It is ADR 005's "one home per predicate",
applied to identity instead of to a handle.

The second principal kind, a **moderator**, is not a member: no badge, in no feed, cannot be liked,
and modelling them as a member with a flag is the shortcut that gets regretted in the queue
(trust/02). `Actor` therefore carries a kind alongside
the identifier and `subject()` refuses when the kind is wrong. PHPStan cannot narrow an `Id<A>`
phantom from a runtime check, so that accessor is an assertion rather than a proof, which is the
same honest limit `InspectPairCommand` is kept in the tree to demonstrate. The kind is decided now
so it is not retrofitted when `Trust` lands.

### The console, where there is no principle, and the `--as` that is not a login

The question in the brief was how to authenticate a CLI user. The first half of the answer is that
most console commands have no user and must not pretend otherwise:

- **operator commands**, `app:outbox:relay`, `app:subscriptions:project`, `app:paths`, `app:reads`:
  no actor, no gate call, no `--as`. They act as the installation. Whoever may run them is decided
  by who may reach the container, which is a deployment question and not one a PHP class improves;
- **commands acting for somebody**, `app:members:register`, `app:likes:cast`: an explicit
  `--as <memberId>`, an `Authority::Assertion`, and an audit line naming both;
- **a genuine human at a terminal**: the device authorization grant, RFC 8628, which is what every
  CLI that actually authenticates a person uses. It is eighty lines against an identity server and
  the demo has no identity server, so it is described here and not built.

The honest sentence about the middle row, which is the one that matters:

> **`--as` is root.** Anyone who can run the console has the database credentials, and no amount of
> PHP changes that.

So it is not dressed up as authentication. It does not consult the gate for permission to
impersonate, because a check whose subject can rewrite the check is theatre. It produces an `Actor`
whose `Authority` says what it is, that provenance reaches the audit line, and the access control is
where it can actually be enforced: on who may reach the machine.

What the design does buy is that the *rest* of the application cannot tell the difference and does
not need to. `app:likes:cast --as X` runs the same gate call, the same processor and the same
handler as `POST /likes`, which is the reason those two console writes exist at all
(ADR 005: option A is HTTP-only, and the console is what proves it).

### Adding Keycloak

Worth stating precisely, because it is the requirement this document was opened for, and because the
authentication half of it is genuinely not our problem:

```php
'firewalls' => ['api' => ['access_token' => ['token_handler' => ['oidc' => [...]]]]],
```

Symfony 7.4 ships the `access_token` authenticator with an `oidc` token handler (validating the JWT
locally against the issuer's keys) and an `oidc_user_info` handler (calling the userinfo endpoint).
Adding an identity server is that block, a JWT library in `composer.json`, and a user provider that
maps a `sub` claim onto an `Id<Member>`. **No call site moves**, because no call site ever named a
credential: they name a permission and they are handed an `Actor`.

One thing does change and it changes for the better, which is worth knowing before it is mistaken
for a regression: **an opaque token in our table is revocable and a JWT is not.** The demo's token
handler looks the token up, so revoking it is a `DELETE`. A JWT is a cached authorization decision
with a TTL and no recall, which is exactly the problem the asynchronous section below is about, one
layer up and with a fifteen-minute window instead of a queue-depth one. Anyone who reads that
section and then reaches for stateless tokens should at least do it on purpose.

## Authorizing

### Why not just the security component

The honest answer first: **we do use it, and almost all of it.** The firewall is Symfony's, the
authenticator is Symfony's, the user provider is Symfony's, the token is Symfony's, the roles are
Symfony's, the voters are Symfony's, the access decision manager is Symfony's, and every policy
engine this design is meant to admit (Casbin, Keycloak, OPA) plugs in as a voter, which is the
component's own extension point working exactly as documented. Nothing here is an anti-corruption
layer, nothing is wrapped "in case we move off Symfony", and deleting all of it would still leave
a swappable system.

What is added is three thin things, and each one exists because a **default** of the component is
right for the delivery it was designed for and wrong for three of the four here:

| the component's default | why it is right | why it is wrong here |
|---|---|---|
| the actor comes from `TokenStorage` | a controller is inside a request, and always has one | the console has no request, and the worker has no request |
| the attribute is `mixed`, in practice a `ROLE_*` string | a role hierarchy is a fine policy, and configurable | a string is untyped, ungreppable, and the tutorial answer to "what do I pass" is the one thing this ADR forbids |
| the declaration is an ExpressionLanguage string in metadata | one line, evaluated before the controller | it feeds one delivery, PHPStan cannot see it, and nothing can enumerate it |

Three defaults, three additions, and they are small enough to list by line count: an interface with
one method; an adapter of about fifteen lines over `AccessDecisionManagerInterface`; two value
classes; a marker interface and the enums that implement it; one attribute and one metadata factory.
Set against what was *not* written: no authenticator, no token, no user provider contract, no voter
system, no role hierarchy, no firewall, no session handling, no password hashing, no `403` listener.

The measure of whether that trade is right is not architectural taste, it is this call site, in the
delivery the component was not designed for:

```php
// without the layer, in a console command
$this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
if (!$this->authorizationChecker->isGranted('ROLE_MEMBER')) { /* ... */ }
```

```php
// with it
if (!$this->gate->isGranted($actor, MatchingPermission::CastLike)) { /* ... */ }
```

The first one works. It also fabricates a session-shaped token for somebody who did not log in,
leaves it in storage for the rest of the process, makes `--as` indistinguishable from a login
everywhere downstream, and names a role at the call site. Every one of those is a decision, and none
of them was taken on purpose; they are what the shortest path through the component produces when
the delivery has no request.

And the scoping that makes it a reasonable trade rather than a universal one:

> **A single-delivery application should use `security:` expressions and stop here.** This layer is
> overhead until there is a second delivery that has no request, and then it is the only thing that
> works. Four deliveries is the reason, not architecture.

### `isGranted`, deliberately the same word

```php
interface Gate
{
    public function isGranted(Actor $actor, Permission $permission): bool;
}
```

The name is Symfony's on purpose. A different verb would imply a different question, and it is the
same question; what differs is that the actor is an argument rather than something read from the
air, and that difference is visible in the signature at every call site. Somebody who knows
`isGranted($attribute)` reads `isGranted($actor, $permission)` and has already understood the whole
design.

The obvious objection is that two `isGranted()` in one file would be confusing. They would, and the
answer is that no class should hold both: one of them is the ambient one this ADR exists to stop
using. If a class has `Gate` and `AuthorizationCheckerInterface` injected side by side, the
confusing method name is the least of what is wrong with it.

It also reads as a question, which the obvious alternatives do not. `$gate->permits($actor, ...)`
and `$gate->allows($actor, ...)` are sentences the gate asserts; `$gate->isGranted($actor, ...)` is
one it answers. Inside an `if`, only one of those is in the right mood.

Note what the signature does **not** take: a subject. A gate that takes `(actor, permission, object)`
is a gate somebody will hand an aggregate to, and the first time that happens the edge is loading
the aggregate to ask a question the handler is about to ask again. The absent third argument is the
four-questions rule, made unwritable, and it is the one place this port is deliberately
*narrower* than `AccessDecisionManagerInterface::decide()`, which takes an object and invites it.

### Option A, the expression on the operation

API Platform's own answer, and it is one line:

```php
#[Post(uriTemplate: '/likes', security: 'is_granted("ROLE_USER")', processor: CastLikeProcessor::class)]
```

It works, it runs before the processor, it costs nothing, and it is HTTP-only. Its problems are the
ones this repository has met twice already:

- **it is a string.** A typo is a runtime failure, PHPStan sees nothing, and `git grep` for a
  permission finds the declaration or the call site but not both;
- **it names a role as a literal**, which is ADR 005 lock 9 (`Handle::MIN_LENGTH`, never `3`) with
  a policy engine on the other end of it instead of a validator;
- **it answers `403`**, which is right for question 2 and wrong for question 3, and `object.` is
  reachable inside the expression, so putting question 3 in there is one dot away;
- **the console gets nothing.** Two console writes exist specifically to demonstrate that
  HTTP-only designs are HTTP-only, and this is the third time that has been the deciding argument.

### Option B, the declared permission

One declaration per operation, read by a metadata factory, generating the surface for every
delivery. This is ADR 004's option B, mechanism for mechanism, and it should be: `DeclaredFilters`
plus `DeclaredFiltersFactory` plus `FilterSetOptions` already prove the shape works and already pay
for the factory.

```php
#[Post(uriTemplate: '/likes', processor: CastLikeProcessor::class)]
#[Guarded(MatchingPermission::CastLike)]
final class LikeCastResource { /* ... */ }
```

`GuardedOperationsFactory` decorates `ResourceMetadataCollectionFactoryInterface`, reads the
attribute and sets the operation's `security` to the generated expression. The runtime is still
Symfony's voters; what changed is that the *vocabulary* is now an enum the compiler can see, the
factory is the only place that writes an expression string, and three other deliveries can read the
same declaration:

| delivery | what the declaration generates                                                   |
|----------|----------------------------------------------------------------------------------|
| ReST     | the operation's `security`, plus a `401`/`403` response in the OpenAPI document  |
| RPC      | `RpcMethod::permission()`, asked by `JsonRpcServer` before the method is invoked |
| console  | the `--as` option, and the gate call, contributed by one helper                  |
| Twig     | what a template may render, and it is still not authorization. See below         |

### Why an attribute of ours, and not `security:`

The attribute is the half of this design that looks most like invention, so the five reasons are
worth listing plainly. None of them is "because a framework string is ugly".

1. **One declaration, four deliveries.** `security:` feeds API Platform and nothing else. The RPC
   server, the console helper and the template read the same `#[Guarded]`. This is ADR 004's option
   B argument, unchanged: one declaration generating every delivery's surface is the whole reason
   `DeclaredFilters` exists, and the attribute here is the **second user of a mechanism this tree
   already built and already pays for**, not a new one.
2. **Declared, never evaluated.** ADR 006 made the rule for OpenRPC's `Param` schemas: they cite
   domain constants and are published rather than executed. An expression string is a small program
   living in metadata, parsed at request time, with its own error channel; an attribute holding an
   enum case is a value, resolved by the compiler, renamed by an IDE, and found by `git grep` in
   one pass.
3. **Enumerable, which is the whole of `app:access`.** A factory can read an attribute and report
   it. Nothing can read the intent of `'is_granted("ROLE_X") and object.owner == user'`, so an API
   declared that way cannot answer "which writes are unguarded" and therefore cannot be checked.
   Same argument ADR 007 used for `FailureCode`: a set is only checkable if something can walk it.
4. **It closes the `object.` door.** The expression language is handed the object, so question 3
   fits inside `security:` and the first person in a hurry will put it there. `#[Guarded]` takes a
   permission and cannot take anything else, so the rule that ownership is the aggregate's is
   unwritable in the metadata layer as well as in the port. That is the same trick as the gate's
   absent third argument, applied one layer out.
5. **The generated document gets it for free.** A factory that reads the declaration adds the
   security scheme, and the `401` and `403` responses, to every guarded operation, once, exactly as
   `BadFilterInputResponses` already adds ADR 004's `400`. A string cannot be inspected, so the
   alternative is somebody maintaining that list by hand in an `openapi:` block, which ADR 006
   spends a section explaining is how a deliverable rots.

And the honest weakness, because it is the same one as everywhere else in this repository:
**nothing structurally prevents a hand-written `security:` expression.** `Ui` is allowed to name
Symfony and API Platform, so a `security:` string compiles and works. The enforcement is that
`app:access` reports that operation as unguarded and exits non-zero, which is not a compiler error
and is the best a metadata layer allows. It is also the right failure: the command reports what the
design can see, and an operation the design cannot see is one it does not have.

### Which goes where

Option A for a read whose audience is "anybody authenticated", where the whole policy is the
firewall and the declaration would add a file to say nothing. Option B for **every write**, without
exception, because a write is what `app:access` has to be able to enumerate and because writes are
where the four deliveries actually diverge.

That is a narrower split than ADR 004's and ADR 005's, where the two options genuinely coexist as
living demonstrations. Here option A is kept for one reason: it is what the reader will find in
every API Platform tutorial, and a document that never shows it cannot explain why the tree does
something else.

### The policy is data, and it is the only thing a subsystem replaces

The demo's policy is a map from role to permissions, declared once:

```php
final class RoleMap
{
    private const array GRANTS = [
        'ROLE_MEMBER' => [MatchingPermission::CastLike, MatchingPermission::BlockMember, /* ... */],
        'ROLE_MODERATOR' => [TrustPermission::SuspendMember, TrustPermission::ReviewCase],
    ];
}
```

That is the whole thing a policy engine is. Casbin replaces it with a model plus a policy store and
`Enforcer::enforce($sub, $obj, $act)`; Keycloak replaces it with client roles, or with UMA 2.0
resources and scopes evaluated against an RPT; OPA replaces it with Rego and an input document.
All four are a different implementation of `PermissionVoter`, and none of them is visible from
anywhere that asks a question.

Where it is visible is **the failure shape**, and that is the genuinely leaky part, so it is written
down rather than promised away. UMA 2.0 does not answer "no": it answers a `401` carrying a
permission ticket and an `as_uri`, so the client can go and get an RPT and come back. That is a
different conversation with the caller, not a different boolean, and it lands in the same place
every other refusal lands (the renderer, below), which is the reason that place is one class and not
a scattering of `throw` statements.

### There is no policy expression language in our code, deliberately

ADR 004 refused a shared filter abstraction: no `Criteria` travelling from UI to store, no
expression tree, no interface promising both mechanisms. The same refusal applies here and it is the
same argument.

**If a policy needs an expression language, the expression belongs in the engine.** Not in a PHP AST
we walk, not in a `Rule` interface with an `evaluate()` method, and not in a `#[Guarded]` attribute
that takes a closure. The moment our code can express policy, the policy has two homes, which is
the entire failure this document is arranged to prevent.

The port is deliberately **the intersection** of what the candidate subsystems can do, not the
union. A port shaped like Casbin (subject, object, action, domain, plus ReBAC lookups) could not be
implemented by a role map; a port shaped like a role map would not be worth replacing. Two
arguments and a boolean is what all of them can answer, which is why that is the port.

## Ownership, which is not authorization

### `404` over `403`, and the provider is what makes it free

`DELETE /pairs/{pairId}` on somebody else's pair answers `404`, identically to a pair that never
existed, because a `403` confirms that a given identifier is real and identifiers are guessable
enough to be worth confirming. Two cards decided this before there was an ADR
(matching/07,
matching/08); this makes it the rule.

The mechanism matters more than the status, because the status is easy to get right once and forget
later. **Scoping the provider is what makes it free.** API Platform fetches the item through a state
provider before the processor runs; if the provider asks the read model for "this pair, for this
actor", then a pair that is not yours is a row that does not come back, the processor is never
reached, and `404` is what happens rather than what somebody remembered to write.

That is also ADR 004 working as specified: a read model answers by criteria, and the actor is one of
the criteria. Nothing new was needed to make the secure thing the default one.

Where the object is loaded by the handler instead, the aggregate decides, because the aggregate is
already open: `Pair::includes($memberId)` returns false and `PairNotFoundException::unknown()` is
what leaves. Note the constructor name; ADR 007 took the identifier out of that message, so a
caller genuinely cannot tell the two cases apart, including from the text.

### An entitlement is an upsell, not a wall

"Your plan does not include this" is question 4, and it answers `422` carrying what would lift it,
never `403`. Three reasons, in increasing order of how much they matter:

1. it is not about who you are, it is about what you bought;
2. the caller can act on it, which is the whole definition of a `422` in this tree;
3. a `403` makes the *count* unavailable, and
   matching/10 is explicit that the count is the entire
   upsell: "twelve people liked you" with the faces blurred sells a subscription and a `403` sells
   nothing.

The entitlement is read from a local projection, never from the billing provider at request time,
which is ADR 001's rule about cross-context reads and ADR 003's path 4 both saying the same thing.

One refinement the board supplied and this document did not, because it only shows up once there
are two cards side by side: **a write refused by an entitlement answers `422`; a read *narrowed* by
one answers `200`.** `matching.rewindLastPass` refuses an action, so it names the plan and the
caller can act on that. `GET /received-likes` refuses nothing: the caller is served the
representation their plan includes, with the count and without the names, because a `403` there
would take the count away and the count is the entire upsell. Rule 13 covers the second case and
rule 11 the first; the distinction is whether anything was actually refused.

### The refusal table

| the caller is | refused with | and learns |
|---|---|---|
| not authenticated | `401`, `WWW-Authenticate` | that authenticating is the next move |
| authenticated, lacks the permission | `403` | something about themselves |
| authenticated, not the owner | `404` | nothing, deliberately |
| authenticated, owner, wrong plan | `422` + what lifts it | what to buy |
| authenticated, owner, right plan, wrong state | `409` or `422` | ADR 005 already decided this |

Reading down that column is the whole of what this ADR adds to the four that came before it, and the
third row is the one that is usually wrong in a codebase.

## Asynchronous, where the decision outlives the decider

This is the case the brief named, and it is the only part of the document where the answer is not
"put it in the obvious place".

### A command is a request; an event is a consequence

> **A command is authorized once, where it is accepted. An event is never authorized at all.**

An event is a fact that has already happened. `MemberErased` does not need permission to have
happened; something that was authorized caused it. `MatchingErasureSubscriber` reacting to it asks
nothing, and if it did, the question would be meaningless: there is no actor, and inventing one
means inventing a principal that may do everything, ambient, in a worker. That principal is the
single worst artefact this design could produce, so there is no `Actor::system()` and there will not
be one.

The corollary is that an operation with no actor is not authorized as the system; it is
**unguarded**, and being unguarded is a property of the operation. `app:outbox:relay` does not ask.
The absence of a principal is not a principal.

### The bus is not the firewall, and here is the one that proves it

The tempting design, and it is tempting because the mechanism already exists in this tree: an
`#[Around]` interceptor on `CommandBus`, next to `SaveTrackedAggregatesInterceptor`, mapping the
routing key to a permission. One place, impossible to forget.

It breaks on a handler that is already written. `SubscriptionMaintenanceService::closeExpired()`
dispatches `CloseOneExpiredSubscription` through the same `CommandBus`, from inside another command,
with no actor anywhere. With the bus as the firewall, that internal dispatch must carry a principal,
so a system principal gets invented, so it gets granted everything, so it is available to every
handler, and the design is over. It also crosses the async channel twice and can only ever ask
question 2, having no aggregate to ask 3 or 4 about.

So the pointcut is not the bus. The gate is called by the delivery, and that is the rule.

### The window was always open

"The user loses the permission between dispatch and handling" reads like an asynchrony problem and
is not. A synchronous request checked at T and wrote at T plus forty milliseconds; the grant could
have been revoked at T plus ten. The window is shorter, not absent. There is no design in which the
check and the effect are simultaneous, and a JWT with a fifteen-minute TTL has a longer window than
most queues.

So the question is not "re-check or not". It is **how long a decision is allowed to be valid, and
who says so**, and the answer is per operation rather than global:

- for nearly everything, dispatch-time is the answer, because the window is bounded by queue depth
  and the effect is small. A like cast by somebody who was suspended a minute later is a like, and
  the suspension deals with likes;
- where the gap genuinely matters, **the freshness requirement is a domain rule**, and it is a
  precondition re-read at handling time.

### When freshness is a rule, it is a domain rule

The tree already performs this, for staleness of a different kind, and the paragraph transfers
without editing. `SubscriptionMaintenanceService::closeOne()`:

> the *selection* is stale by construction [...] So the boundary is re-read, from the real source

and `expiredBefore` travels in the command specifically so that "has it been renewed since we chose
it" is answerable. That is the pattern:

> **A message that may be handled much later carries what makes its own staleness answerable**, and
> the handler re-reads the state the decision depended on. It does not re-read the grant.

The difference between those two sentences is the whole section. Re-reading the *grant* needs the
principal in the worker, which needs a credential in the message, which expires, which means either
messages that fail after a legitimate delay or a worker holding credentials that can act as anybody.
Re-reading the *state* needs the store, which the handler has, and produces a domain refusal with a
code and a status like every other one.

### A suspension is state, not a revoked role

The concrete case, and the one that will come up first
(trust/03): a suspended member must stop writing
immediately, including whatever they have in flight.

Do **not** implement that by revoking a role. A role lives in the identity provider, so re-checking
it needs the provider from the worker, it cannot participate in our transaction, it cannot be a
domain event, and it cannot be reverted by the same mechanism that set it. Every one of those is a
property the feature needs.

Implement it as what it is: **state on the account.** `ProfileStatus::Suspended` next to `Active`
and `Paused`, a pure enum through `PureEnumType`, which the card already chose for other reasons.
Then the handler re-reads it because it re-reads the aggregate anyway, `Matching`'s feed projection
reacts to `MemberStatusSwitched` as it already does, and reinstating is one more status change.

> **A role is a fact about the person. A suspension is a fact about the account.** They look alike
> and only one of them is transactional with anything you own.

### The one that sounds wrong and is right

A moderator upholds a case and dispatches the suspension asynchronously. One minute later their
moderator role is revoked, because they have just been dismissed. The message is still in the queue.
Does the suspension land?

**Yes.** It was decided while they held the role, the audit line records who decided and when, and
undoing it is a business action with a name (reinstate) performed by somebody who looked at it. The
alternative is a race between a queue and a directory sync deciding whether a moderation decision
took effect, which is not a security control, it is a coin flip with a compliance report attached.
An organisation whose policy really is "everything decided in the last hour by a dismissed moderator
is void" gets a compensating process, reviewed by a human, because that policy is a business rule
and not a permission check.

### What travels, and what does not

| in an async message                              | carried?                                                              |
|--------------------------------------------------|-----------------------------------------------------------------------|
| the subject the aggregate acts on                | yes, as an `Id<Member>`, exactly as today                             |
| the actor, when the aggregate records who did it | yes, as domain data                                                   |
| the instant the decision was taken               | when the handler has a freshness rule that needs it                   |
| a token, a session id, a role, a grant list      | **never**                                                             |
| an `Actor`                                       | never; it is a `Ui` type and it would not survive the boundary anyway |

That last row has a practical edge to it. `Ui` may name every context's `Application`, so a `Ui`
type in a command payload would compile; it would also make every context depend on the delivery
layer the first time a handler touched the field, and the phpat rules would catch it one commit too
late.

## Reading

### Scoping is a criterion, never a filter applied afterwards

"My likes", "my pairs", "my badge collection". Thirty cards say "my", and there are two ways to implement it:

```php
$rows = $this->ledger->list(new ListLikes(/* ... */));
$mine = array_filter($rows, fn ($r) => $r->memberId === $actor->subject());   // no
```

```php
$this->ledger->sentBy($actor->subject(), $window);                            // yes
```

The first one built a collection containing rows the caller may not see, and that collection now
exists in memory, in the profiler, in a log line, and in the first `json_encode` somebody adds
without re-reading the filter. The second never selected them.

So: **the actor's identifier is a value in the query object**, carried exactly like every other
filter value in ADR 004, parsed by the delivery like every other one, and with one difference that
has to be declared rather than assumed:

> **A scoping value is not an exposed parameter.** It comes from the principal, so it must not
> appear as a `QueryParameter`, as a console `InputOption` or as a form field; a caller who can
> supply it can read somebody else's rows.

That is a real constraint on ADR 004's option B: `MemberDirectoryFilters` declares filters and
`DeclaredFiltersFactory` turns each one into a documented query parameter. A scoping value declared
the same way would be published, so the declaration has to distinguish the two, and the factory has
to skip one. Getting that wrong is invisible until somebody reads the OpenAPI document, which is
precisely why ADR 006 insists the generated document is a deliverable.

### One audience, one shape

membership/10 serves three representations from one URL: the
member gets their open report count, anybody else gets trust level and verification, a moderator
gets the report summaries. That is authorization choosing a representation, which is legitimate and
is not a gate call.

It is **not** implemented by building the full DTO and nulling fields. A DTO whose fields are
sometimes absent is a DTO no client can type, and ADR 006 already learned this shape from the other
direction: a `oneOf` without a discriminator gets flattened by generators into one type with every
field optional, which accepts payloads that cannot exist. The same defect in a response is a
contract that promises data it will usually withhold.

So: one method per audience on the read port, each returning its own DTO, and the provider picks
which to call from the actor. The adapter selects different columns, which is the cheap half, and
the schema is honest, which is the half that matters.

## Rendering the refusal

### Two new markers, two statuses, one new RPC code

`Shared/Domain/Exception/` holds six marker interfaces mapped to statuses in
`config/packages/api_platform.php` and to `ErrorCode`s in `RpcErrorFactory`. Two join them:

```php
interface UnauthenticatedThrowable extends TranslatableThrowable {}   // 401
interface ForbiddenThrowable extends TranslatableThrowable {}         // 403
```

They are markers about a status, so they sit with the other six; the concrete failures live in
`Shared/Application/Security/`, because no domain raises them. That combination already exists and
is the precedent: `BadRequestThrowable` is declared in `Domain/Exception/` and its only
implementation, `BadFilterInput`, lives in `Shared/Application/ReadModel/`. Both use
`DeclaredFailure`, both declare a code in `SharedFailure`, and `bin/console app:messages` refuses to
pass until both have an entry in every enabled locale.

On the RPC side, `ErrorCode` gains one case at the next free number, per its own docblock:

```php
case Forbidden = -32005;   // ForbiddenThrowable, the REST 403
```

There is no `-32006` for unauthenticated, and the next section is why.

### `401` is the one thing `POST /rpc` does not answer with a `200`

ADR 006 is categorical: everything that endpoint decides is a `200`, because JSON-RPC puts the
failure taxonomy in the body. This amends it by exactly one case, and the amendment is narrow on
purpose:

> **A `401` leaves `POST /rpc` in the status line, because it is not this endpoint deciding.**

JSON-RPC 2.0 is transport-agnostic and its error space is about the request object and the method:
malformed JSON, a bad envelope, an unknown method, bad params. Authentication is a property of the
connection, RFC 9110 requires a `401` to carry `WWW-Authenticate`, and there is nowhere in a JSON-RPC
response object to put a challenge. Inventing `-32006` would mean a caller learning they must
authenticate from a field that carries no challenge, inside a `200` that every HTTP client in the
world treats as success.

A **forbidden** call is the opposite and stays inside the `200` as `-32005`, because that one is this
application deciding, with the actor known and nothing to challenge.

This has a consequence for `rpc.discover`, and it is the right one: if the firewall protects `/rpc`
as a whole, discovery needs a credential. That is acceptable. The human-readable description of
every method is in the OpenAPI export, which is public, and an OpenRPC document for an endpoint the
reader cannot call is a curiosity.

### A refusal says nothing

Per ADR 007 rule 5, a failure names what the reader has to change and nothing else. A `403` has
nothing they can change, so:

```
shared.forbidden          "You are not allowed to do that."         no parameters
shared.not-authenticated  "You need to sign in to do that."         no parameters
```

**The permission name does not travel.** `matching.like.cast` is our policy vocabulary; telling a
caller which permission they lack publishes the shape of the policy to somebody who has just
demonstrated they are not entitled to know it. It goes in the log, next to the actor and the
operation, which is where somebody debugging can use it. This is the same argument ADR 007 used to
take thirteen identifiers out of `parameters()` rather than only out of the sentences: a failure can
only leak what it hands over.

### Authentication failures are Symfony's, and that is ADR 007's debt paid

ADR 007 closed by noting that `AuthenticationException` already carries `getMessageKey()` and
`getMessageData()`, translated from the `security` domain, and that ADR 008 inherits the seam. Here
is the answer:

> **We do not re-render an authentication failure.** It happens before any of our code runs, so
> there is no command, no handler and no domain exception; Symfony's `security` catalogue renders
> it, in fifty-seven locales, and duplicating it into `failures` would be the second English that
> ADR 007 rejected everywhere else.

`FailureMessages` stays the one class that calls `trans()`, and it stays unaware of the `security`
domain. What we do own is the **shape**: a `401` from the entry point must leave as RFC 9457
`application/problem+json` like every other failure on this API, because a client that parses one
error format should not need a second one for the one case it will meet first. That is a small
decorator and it is on the list below.

### phpat, the third narrowing

ADR 005 took `Symfony\Component\Validator` out of `miscellaneous()` and put it in `application()`.
ADR 007 added `no_translator_outside_ui` to three rule sets. This adds the third in the same shape
and for the same reason:

```
no_security_outside_ui:  Symfony\Component\Security  may be named under App\Ui and nowhere else
```

`domain()` and `application()` already exclude it by omission; `infrastructure()` is the hole,
because it allows all of `Symfony` through `vendors()`. The violation to be afraid of is not a
malicious one, it is `Security::getUser()` autowired into a context's infrastructure service by
somebody in a hurry, which compiles, works in a request, and returns null in the worker.

Three ADRs, three components, one rule: **a delivery concern is named by a delivery.**

## Auditing

One line, written by the thing that decided, at the edge, before the command goes on the bus:

```
actor=01J...  via=assertion  permission=trust.member.suspend  operation=rpc:trust.suspendMember  subject=01J...  decided=2026-09-27T11:04:02Z
```

`via` is the reason `Authority` is a field rather than a comment. An operator assertion and a login
produce identical effects and must not produce identical log lines, and that is the only place in
the design where the provenance is load-bearing.

This is a log line and not an aggregate. An audit trail with retention, search and legal weight is a
product, and the moment somebody needs one it is a context with a language; there is no half version
of it worth building, and a half version is how a table called `audit_log` ends up with four
hundred million rows nobody has ever queried.

## The rules this obeys

1. **Ask for a permission, never for a role.** A role is an answer, and it is the answer the
   subsystem you may replace is responsible for.
2. **The actor is an argument, never ambient.** Nothing outside a `CurrentActor` implementation
   reads a token, a session or a request to find out who is asking.
3. **Four questions, four homes.** Authentication at the firewall, permission at the gate,
   ownership at the aggregate or the scoped provider, entitlement at the projection. A component
   that answers two of them has taken the other one's data.
4. **If it needs our store, it is not the gate's question.** The gate takes no subject, so it
   cannot be handed one.
5. **The subject of a write comes from the principal**, never from the body and never from the URI.
6. **A command never carries a credential.** It carries an actor only when the aggregate writes one
   down, and removing that field must break the audit rather than the check.
7. **A command is authorized once, at the edge. An event is never authorized.** There is no system
   principal, and an operation with no actor is unguarded rather than privileged.
8. **The bus is not the firewall.** A handler dispatching to a handler must need nothing.
9. **Freshness is a domain rule.** A handler re-reads the state a decision depended on; it never
   re-reads the grant. A message that may wait carries what makes its own staleness answerable.
10. **A suspension is state, not a revoked role.** Anything that has to be transactional with our
    data, reversible by us, or publishable as an event, is ours.
11. **A refusal about an object answers `404`.** A refusal about the caller answers `403`, and a
    refusal about a plan answers `422` with what would lift it.
12. **Scoping is a criterion, not a filter applied afterwards**, and a scoping value is never an
    exposed parameter.
13. **Authorization may choose a representation, by choosing a query.** Never by nulling fields on
    a DTO that was built with them.
14. **A refusal says nothing**: no permission name, no identifier, no hint about what exists.
15. **The policy is data.** No expression language of ours, no rule objects, no closures in an
    attribute. If a policy needs an expression, the expression lives in the engine.
16. **The port is the intersection, not the union.** Two arguments and a boolean, because that is
    what a role map and a policy server can both answer.
17. **Only a delivery may name `Symfony\Component\Security`.** Enforced.
18. **A credential is identified by something a human typed, a session is stored nowhere of ours,
    and a revocable token is a JWT whose `jti` is the row.** Added by
    [ADR 009](009_one-url-two-representations.md), which also content-negotiates the refusal.
19. **The shared vocabulary names a marker, never an aggregate.** A principal is an
    `Id<PrincipalAggregate>` everywhere under `Shared`, the context implements the marker, and the
    narrowing back to `Id<Member>` is declared once, at the edge, by an argument.

### What rule 19 corrects, and it is this document's own move

This rule exists because the code could not follow, which is the finding this repository says is
worth reopening an ADR over.

**What happened.** The section above argues the vocabulary belongs in `Ui`, and it was there. Then
`Ui/Infrastructure` was dissolved and the security vocabulary moved to `Shared\Infrastructure\Security`
with everything else that extends a library rather than this application. Nobody re-read this
document while doing it, and the move took the vocabulary out of **the one namespace allowed to name
every context**. `Actor::subject()` returning an `Id<Member>` had been correct in `Ui` and was a
layering violation the moment it arrived in `Shared`. phpat said so, sixteen times, in six files,
and the errors sat in the baseline being described as "the baseline" for long enough that the
`verify-the-tree` skill taught people to expect them.

**Why the obvious fixes are wrong.** Deleting the `Id<Member>` annotation compiles and loses exactly
the type safety [ADR 002](002_identifying-everything.md) exists for. Widening the *commands* to take
an `Id<PrincipalAggregate>` pushes the weaker type past the edge and into every handler and
aggregate downstream, which is the same leak arriving from the other side. Moving the vocabulary
back under `Ui` makes the errors vanish and re-opens a move [ADR 001](001_architectural-organization.md)
is already said to owe an argument.

**What the marker buys.** `Shared\Domain\Models\PrincipalAggregate` is an empty interface. It is
empty on purpose: a principal has no behaviour in common across applications, it has an identity,
and `Id<PrincipalAggregate>` is exactly that claim and no more. Giving it a method would be
inventing a requirement for somebody else's aggregate.

Two mechanics make it work, in opposite directions, and both are worth stating as rules of their own
because each one is a place somebody will take the shortcut:

- **widening is free, because `Id`'s `A` is now `@template-covariant`.** An `Id<Member>` is an
  `Id<PrincipalAggregate>` exactly as a `Member` is a `PrincipalAggregate`. This is sound rather
  than convenient: `A` is a phantom and appears in no parameter position. The single place it did,
  `Id::sort()`, takes `self<*>` and its docblock says why. **The invariance was the actual cause**:
  widening was unavailable, so somebody narrowed the shared type instead, which is how a context's
  name got into `Shared` in the first place;
- **narrowing is declared, never commented.** `Actor::subject(Member::class)` takes a
  `class-string<T>` it never reads, so PHPStan resolves `Id<T>`. The alternative, `@var Id<Member>`
  at twenty three call sites, is the same unchecked assertion made twenty three times instead of
  declared once, and [ADR 005](005_validation.md)'s "one home per predicate" is the same argument
  about a different kind of claim.

**`Ownership::orNotFound()` takes the refusal as an argument** for the matching reason on the other
axis. The *rule* is shared: ownership answers `404`. The *failure code* is not, because
[ADR 007](007_translation.md) keys every message on a code a context declares, and `Shared` may not
name one. So the caller hands in `MemberNotFoundException::unknown(...)` as a first-class callable.
One argument, and nothing under `Shared` knows what this application calls its principal.

**Measured:** 27 PHPStan errors to 11, all sixteen `phpat.infrastructureIndependence` gone, and
`grep -rn 'use App\\Membership\|use App\\Matching' src/Shared/` returns nothing. The deliberate
`InspectPairCommand` error survives, which is the point: the phantom type is still checked by
PHPStan and still not by PHP.

**What this leaves open.** The section above still argues the vocabulary belongs in `Ui`, and the
tree has it in `Shared`. Rule 19 makes that placement *safe* rather than *right*, and the argument
the placement owes is ADR 001's rather than this one's.

### What rule 18 corrects

Three things I got wrong in this document's first implementation, and they are worth naming because
each one looked reasonable while it was being written.

**A `Member` identifier is not a login.** The credential table above says "a member id, a hash, a
created-at, nothing else", and nothing else turns out to include the one field a person can type.
Rule 9 of [ADR 007](007_translation.md) already says a ULID is a value the reader cannot use, which
is why no failure message interpolates one; asking somebody to sign in with one is the same mistake
with a form around it. A credential carries an email address or a username.

**A session is not ours to store.** Symfony manages a session, expires it and garbage-collects it. A
row beside it is a second lifetime to keep in step with the first, and the only thing it can tell me
is something the session already knows.

**An opaque token in a primary key is not revocation.** The implementation made the credential row's
identifier the bearer token itself, which stores the token in clear text and means revoking a token
destroys the password login sharing its row. If a token is to be revocable it is a JWT, its `jti` is
what a revocation table holds, and the token stays a token.

## If you are adding a guarded operation

1. Decide which of the four questions your refusal actually is. If the answer has to load anything
   of ours, it is not a permission, and the rest of this list is the wrong list.
2. Add a case to your context's `Permission` enum. Context prefix, dots, verb last, declared and
   never derived from a class or a route name.
3. Put `#[Guarded(YourPermission::Case)]` on the operation. Do not write a `security:` expression by
   hand; the factory writes those, and one written by hand is one `app:access` cannot see.
4. Take the subject from `CurrentActor::actor()->subject()`. If you are reading it from the payload
   or from a URI variable, stop: either the operation is not self-scoped and needs a second
   permission, or it should not exist.
5. For a read, pass the actor into the query object as a scoping value and make sure the
   declaration does not publish it. Check the OpenAPI export, not the code.
6. For ownership, let the aggregate answer, with `::unknown()` and a `DomainNotFoundThrowable`. Do
   not add an `if` at the edge that answers `403`, and do not put `object.` in an expression.
7. Grant the permission in `RoleMap`. That file is the policy; it is also the only file that would
   be deleted the day a policy server arrives, which is the test of whether anything else leaked.
8. Run `bin/console app:access`. Every write must name a permission and every permission must be
   granted to somebody, and the command exits non-zero when one is not. Then find your new case in
   the `asked by` column of the last table. A permission nothing asks for prints `NOTHING` and does
   not fail the run, because a permission may precede the operation that will ask for it, which is
   what this document itself did; a case still reading `NOTHING` after the operation has shipped
   means the declaration is not where the command reads it.
9. Run `bin/console app:messages`. A new refusal is a new code and a new catalogue entry, in every
   enabled locale, like all the others.
10. Do not touch the gate. If you needed to, the question was not question 2.

## What this ADR still owes

This was the first ADR in the repository written entirely ahead of its code. The first of the six
commits below has since landed, and the honest summary of the rest is that the tree still has one
in-memory user called `admin`, `http_basic`, and two `access_control` rules pointing at another
project's paths.

In the order the code should follow:

- ~~**the vocabulary and the port**~~ **shipped**: `Actor`, `Authority`, `PrincipalKind`,
  `Permission` with `MembershipPermission` and `MatchingPermission` (28 cases), `Permissions` finding
  them the way `MessagesCommand` finds `FailureCode`, `Gate`, `CurrentActor`, `SecurityUser`,
  `ActorTokens`, `AccessDecisionGate` over `AccessDecisionManagerInterface`, `PermissionVoter` and
  `RoleMap`. Also `UnauthenticatedThrowable` and `ForbiddenThrowable` with their two failures, two
  `SharedFailure` cases, four catalogue entries, `ErrorCode::Forbidden = -32005` and its arm in
  `RpcErrorFactory`, because `Actor::subject()` has to throw something real; and
  `no_security_outside_ui` in the three context rule sets, because the rule that protects the code
  should not land after it. Nothing calls any of it: the container removes `Gate` as an unused
  private service, which is the commit's own proof of that claim. PHPStan is unchanged at 21 errors
  in the same four files.

  Three deviations from the plan above, each small and each recorded rather than smoothed over.
  `ActorTokens` is a class this document did not name and the design needs: the decision manager
  wants a `TokenInterface` and the actor is a value, so something bridges them, and it is also the
  seam the credential commit fills. `SecurityUser` moved one commit earlier for the same reason.
  And `Actor::moderator()` is **not** shipped: `PrincipalKind::Moderator` is declared and
  `subject()` already refuses it, because that refusal is what would be expensive to retrofit,
  while the constructor needs a phantom tag for a moderator's identifier and there is no `Trust`
  context to hold one. Inventing the tag in a delivery is the mistake this directory exists to
  avoid;
- **authentication**: the `credential` table, `CredentialProvider`, the four `CurrentActor`
  implementations, the stateless firewall with an opaque bearer token handler, and `access_control`
  reduced to the one question it can answer. `security.php`'s two foreign rules go here, and
  `ActorTokens::rolesFor()` loses its placeholder body;
- **the declaration and the factories**: `#[Guarded]`, `GuardedOperationsFactory`,
  `RpcMethod::permission()`, the console helper, and `bin/console app:access` that walks all four
  deliveries, the fourth being ADR 009 rule 14's Twig surface, in both directions: every operation
  against its permission, and every permission against the operations that ask for it;
- **the subject moves**: `from` leaves `POST /likes` and `POST /blocks`, `CastLikeProcessor` and
  `BlockMemberProcessor` read the principal, and the generated OpenAPI document shows the diff.
  This is a breaking API change and it is the reason the decision had to precede the endpoints
  rather than follow them;
- **the refusals**, of which only the `JsonRpcServer` re-throw is left: everything else shipped
  with the vocabulary, because `Actor::subject()` needed somewhere real to throw;
- **the `401` body**, decorated into RFC 9457 so the first failure a client meets is not the one
  shaped differently from all the others.

And four things left open on purpose:

- **moderators, and therefore `Trust`.** `Actor` carries a principal kind and `subject()` refuses
  the wrong one, so the shape is decided; the second kind arrives with the context that needs it
  rather than as an enum with one case and a plan;
- **the device authorization grant.** RFC 8628 is how a CLI authenticates a human, it is described
  above, and it is not built because there is no identity server to build it against. `--as` is what
  ships, it is root, and it says so;
- **no test proves any of this.** `tests/` still holds no cases. `app:access` proves that every
  operation names a permission and reports every permission that no operation names, which is
  exactly as much as `app:messages` proves about the catalogue: that nothing is missing, never that
  anything is right. The second direction reports and does not fail, because a permission may
  legitimately precede the operation that will ask for it and this document is the reason it may; a
  row is settled by opening its card, not by the command. The `404`-over-`403` rule, the scoped
  provider and the `subject()` refusal are three behaviours that each fit in a test and currently
  rest on a paragraph;
- **the async converter, which gets worse before it gets better.** `CloseOneExpiredSubscription`
  already carries an `Id` and a `\DateTimeImmutable` across the `async` channel with no Ecotone
  converter, and it only passes under `APP_ENV=test` because the in-memory channel hands objects
  over by reference. The first message to carry a decision instant, under rule 9, makes that two
  missing converters instead of one. Write them before the async story matters.
