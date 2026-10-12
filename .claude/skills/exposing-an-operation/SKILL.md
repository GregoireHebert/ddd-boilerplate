---
name: exposing-an-operation
description: Decide whether a capability in this DDD/CQS tree is a REST resource or a method on POST /rpc and then wire it, following ADR 006 - the three tests, one resource per write, state providers and processors, the JSON-RPC registry with its Param declarations and error table, and the four rules that keep the generated OpenAPI and OpenRPC documents honest. Use before adding any endpoint, ApiResource, operation, state provider, state processor or RPC method.
---

# Exposing an operation

The argument is `docs/ADR/006_rest-vs-rpc.md`. This project has both surfaces:
nine resource paths and `POST /rpc`. **Nothing is exposed twice.**

> **ADR 009 amends this one.** A representation is not an exposure: an HTML page
> for a resource that already exists adds no operation, no route and no entry to
> either generated document. Decide REST versus RPC here, then see the
> `rendering-a-representation` skill for what `Accept` does with the answer.
> Two amendments bite immediately: **a write declares no `html` and has no
> template** (ADR 009 rule 6), and **an operation with no actor says so with
> `#[Unguarded(because:)]`** rather than being left bare (rule 13).

## REST or RPC

> Is the thing the caller names in the request the thing the answer is about?

A capability belongs on **REST** when **all three** hold:

1. there is a noun with an identity, and **this application issues** it (ADR 002);
2. a `GET` on it would return something, now or one day;
3. the operation is one of the HTTP verbs without the verb having to be explained.

If any fails, it is a method on `/rpc`. Not as a fallback: a capability that
fails one of those is being described by a protocol that cannot see it.

## REST side

**Every write is its own resource.** `input:` and `output:` are not used. A
registration is not a member: `MemberRegistrationResource`,
`MemberHandleResource`, `MatchingPreferencesResource`, `MemberErasureResource`,
`LikeCastResource` each carry their own `#[ApiResource]` and one operation.
Resources live in `Ui/Api/Resources/{Membership,Matching}/`
(the scan is recursive) and are held together for a reader by an explicit
`tags:`. A write resource has no item operation, so JSON-LD gives it a skolem
`@id`; that is correct, not a gap.

Resources are read from `.../Resources` and `.../RPC` only, with
`doctrine: false`, so **every collection operation needs a hand-written
provider**. Providers live in `src/Ui/Api/StateProvider/`, processors in
`src/Ui/Api/StateProcessor/`. `UiArchitectureTest` forbids `Ui` from naming any
context's `Infrastructure`, so a provider reaches a read model only through the
query bus. `PATCH` needs `patch_formats` in the API Platform config.

Current write endpoints: `POST /members` (option A),
`PATCH /members/{memberId}`, `PUT /members/{memberId}/matching-preferences`,
`DELETE /members/{memberId}` (202, the erasure saga), `POST /likes` and
`POST /blocks` (option B), `DELETE /pairs/{pairId}`.

## RPC side

**One endpoint, `POST /rpc`, dispatching on a member of the body.** The route
count does not grow with the method count. A route per method was tried and
cannot produce JSON-RPC: the protocol dispatches on `method`, batching needs the
single endpoint, and the error channel is a `200`, for which there is no status.

```
Shared/Infrastructure/ApiPlatform/JsonRpc/   the protocol: envelope, params, errors,
                                          registry, server, rpc.discover, OpenRPC
Shared/Infrastructure/ApiPlatform/JsonRpc/       the single #[ApiResource]
Ui/Api/JsonRpc/                          one class per exposed method
Ui/Api/StateProcessor/JsonRpcProcessor   the connection with API Platform
```

The placement **is** the statement and `MethodRegistry` reads it as one.

The operation turns `deserialize`, `validate` and `serialize` off on purpose:
each owns a failure channel and JSON-RPC has one. `status: 200` because API
Platform's `POST` default is `201`, which JSON-RPC never sends. `output: false`
so the schema factory does not mint a schema from an empty anchor class. What
stays on is `Accept` negotiation, so a wrong `Accept` is a `406`.

**Everything this endpoint decides is a `200`**, and `204` is the only other
answer, except one case ADR 008 amends: **a `401` leaves in the status line**,
because RFC 9110 requires a `WWW-Authenticate` challenge and a JSON-RPC response
object has nowhere to put one. A **forbidden** call stays inside the `200` as
`-32005`.

**A notification is a valid request with no `id` member**: it runs and nothing
comes back, `204`. The envelope is checked *first*, so an invalid envelope is
answered with `id: null` whether or not an `id` was there. `"id": null` is not a
notification. A notification's failure is silent towards the caller and logged
at error level. Notifications dispatch synchronously.

**A batch is N transactions**, capped at 50, refused whole when oversized, never
presented as atomic. A caller needing all-or-nothing needs a command that means
all-or-nothing, which is one method.

**The `id` is echoed byte for byte** and is never an identifier, a deduplication
key or an aggregate's identity. An earlier version forwarded it to the bus as
`aggregate.id`, which let a caller choose which aggregate their command
addressed.

**`rpc.` is the protocol's namespace, not a forbidden word.** §4 reserves those
names *from applications*. A class in `Shared/Infrastructure/ApiPlatform/JsonRpc/`
may claim one (`DiscoverMethod` serves `rpc.discover`); a class under
`Ui/Api/JsonRpc/` may not, and trying throws at wiring time.

**Exposure is a declaration, never a consequence.** A routing key already *is* a
method name, so letting `method` route straight onto the bus would expose every
handler in the container the moment somebody adds one, including
`membership.advanceErasure`.

### Adding a method

1. Run the three tests. If it passes all three it is a REST resource.
2. One class in `src/Ui/Api/JsonRpc/` implementing `RpcMethod`. The tag on the
   interface does the wiring: no map to edit, no configuration.
3. Name it **from the routing key constant**, never a literal, so the wire name
   and the bus name cannot drift.
4. Parse with `Violations`, naming the pointer the domain would name, calling
   the same constructors the REST surface calls. Do not restate a rule.
5. Declare `parameters()` as one `Param` each, **in the order a positional call
   should use** - that order exists nowhere else and is what breaks clients when
   it changes. Schemas cite domain constants (`Color::values()`,
   `Param::identifier()` for anything holding an `Id`). **A parameter is a
   declaration, never a gate**: nothing reads a schema at request time, and
   `required` says the document should mark it required, not that anything
   enforces it. An absent parameter arrives as `''` and comes back as the
   violation its own constructor raised.
6. Add `example()` and a `result()` schema. **A method declares its result
   shape and maps into it**, so no aggregate reaches the wire.
7. If it can fail in a way no marker covers, add the marker, then add it to
   `RpcErrorFactory` **and** to `exception_to_status`.
8. Call `rpc.discover` and read your method back.
9. Declare the guard: `RpcMethod::permission()`, or `#[Unguarded(because:)]` if
   it genuinely has no actor, then `bin/console app:access`.

### The error table

Application codes live in `-32000` to `-32099`; a new marker takes the next free
number below `-32005`. `-32602` carries the **identical `violations` array** a
REST `422` does. Nothing below `-32000` carries one: those answers were decided
by asking the database and are never lists. `-32603` says nothing unless
`APP_DEBUG` is on.

## The generated documents are deliverables

`bin/console api:openapi:export`. Four rules, each because the default did
something wrong:

- **every resource carries `description:` on `#[ApiResource]`.** Without it API
  Platform falls back to the class PHPDoc and publishes the teaching docblock as
  the public tag description. Tag descriptions themselves are declared once in
  `Shared/Infrastructure/ApiPlatform/OpenApi/TagDescriptions`, because
  `OpenApiFactory` otherwise lets whichever resource is processed last supply
  one for the whole tag; the `openapi.tags` config option cannot be used (the
  container refuses to dump an object parameter).
- **operation prose goes in `openapi: new Operation(summary:, description:)`.**
  A `description:` on the operation feeds the *schema*, leaving the operation
  reading "Retrieves a Foo resource."
- **collection operations set `paginationEnabled: false`,** which does not
  disable pagination. It only stops `OpenApiFactory` inventing a `page`
  parameter that wins the merge against the declared ones.
- **document defaults in prose, never as `schema['default']`,** which
  `ParameterProvider` reads at request time, turning documentation into
  behaviour decided in the delivery layer. Use `examples`.

Two rules the generated documents learned the hard way:

- **every `$ref` resolves inside its own document.** An external URL is a JSON
  Pointer that cannot resolve; one of them took Redoc down with
  `Invalid ref JSON pointer` before a single endpoint rendered. Cite external
  schemas in a `description`.
- **a `oneOf` needs something that recovers the branch**, a `discriminator` or a
  difference no generator can merge (object versus array). Otherwise generators
  flatten the branches into one type with every field optional, which accepts
  payloads that cannot exist. `JsonRpc.Request` discriminates on `method`;
  `params` is documented by name only and the response is one schema, because
  neither has anything to discriminate on. Prefer a type array for a union of
  primitives.

**OpenRPC is pinned to `1.3.2` deliberately:** the published meta-schema's
generated `enum` stops there, so a 1.4.x document fails validation everywhere.
`OpenRpcFactory`'s docblock says how to re-check. `OpenRpcFactory` and
`JsonRpcDocumentation` are two projections of the same registry and neither is
derived from the other, so adding a method updates both.
