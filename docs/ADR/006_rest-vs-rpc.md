# REST versus RPC

REST is a way of exposing *things*. RPC is a way of exposing *doing*. Almost every argument about
the two is really an argument about an application that has both and admits to only one.

This project has both, and they sit next to each other in the same document: nine resource paths, and
`POST /rpc`. Nothing is exposed twice.

If you would rather poke at it than read about it:

```shell
# the endpoint describes itself, over itself
curl -s localhost:8000/rpc -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"rpc.discover","id":1}' | jq .result

curl -s localhost:8000/rpc -H 'Content-Type: application/json' -d '
  {"jsonrpc":"2.0","method":"membership.viewReputation","params":{"memberId":"..."},"id":1}'

bin/console api:openapi:export | jq '.paths["/rpc"].post.description' -r   # the same, for a human
```

## Flow

A caller sends one request object, or an array of them, to one URI.

The envelope is checked against JSON-RPC 2.0 §4: the version, a method name, a structured `params`,
an `id` of an allowed type. Anything the envelope refuses is answered and the method is never looked
up.

The method name is resolved against a registry of declared methods. Not against the command bus, and
not against the path.

The method parses `params` through the domain's own constructors, exactly as a REST state processor
does, and puts one command or one query on a bus.

What comes back is mapped into a shape the method declares. What was thrown is mapped into an integer
error code. Both of them leave inside a `200`.

Unless the call had no `id`, in which case nothing leaves at all.

## Architecture

### They are not two styles, they are two questions

The useful distinction is not "resources versus verbs", because every RPC method operates on
something and every REST resource has behavior. It is this:

> **Is the thing the caller names in the request the thing the answer is about?**

`GET /members/{id}` names a member and answers with that member. `DELETE /pairs/{pairId}` names a
pair and the pair stops existing. The URI is the subject, the verb is the modifier, and the whole
protocol is built around that: caching keys on the URI, conditional requests key on the URI,
`Location` points at a URI.

`membership.acquireBadge` names a member and a pattern, and what changes is a child entity inside an
aggregate that has no address. There is no URI whose representation is "a badge being acquired".

### The test, and it is a short one

A capability belongs on the REST surface when **all** of these hold:

1. there is a noun with an identity, and this application issues that identity (ADR 002);
2. a `GET` on it would return something, now or one day;
3. the operation is one of the five or six HTTP verbs without the verb having to be explained.

If any of them fails, it is a method on `/rpc`. Not because RPC is a fallback, but because a
capability that fails one of those tests is being described by a protocol that cannot see it.

The four methods registered today all fail test 1:

| Method                    | What has no address                                                                                                                                                                                                                                                                    |
|---------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `membership.acquireBadge`    | a `Badge` is a child of the `Member` aggregate (ADR 003, path 2). There is no `BadgeRepositoryInterface`, deliberately, and `BadgeCollectionService`'s docblock says why: adding one turns a child entity into a second aggregate root and that is how a badge collection ends up holding badges nobody owns |
| `membership.loseBadge`       | same, plus the verb. "Lost" and "discarded" are different facts about the same badge and HTTP has one `DELETE`                                                                                                                                                                          |
| `membership.discardBadge`    | same                                                                                                                                                                                                                                                                                   |
| `membership.viewReputation` | a `Reputation` is ADR 003 path 4 in its third form, assembled from a moderation provider and an identity provider, stored nowhere. It has an identity but this application does not issue it                                                                                           |

A fifth is registered and is not in that table, because the test does not apply to it: `rpc.discover`
is the protocol describing itself and would be identical in a project with a different domain. It
lives on the other side of the line below.

Note what is *not* on `/rpc`: nothing that has a REST endpoint. A method that duplicated
`POST /likes` would give a caller two ways to do one thing, two sets of failures to handle, and two
places to change a rule.

### Where it lives

```
src/Shared/Infrastructure/ApiPlatform/JsonRpc/     the protocol: envelope, params, errors, registry,
                                               server, and rpc.discover with its OpenRPC factory
src/Shared/Infrastructure/ApiPlatform/JsonRpc/         the single #[ApiResource]
src/Ui/Api/JsonRpc/                            one class per exposed method
src/Ui/Api/StateProcessor/JsonRpcProcessor.php the connection with API Platform
```

The first directory is what JSON-RPC does and the third is what this application does, which is why
`DiscoverMethod` sits in the first one despite implementing the same interface as the other four: it
touches no bus, no command and no aggregate. The placement is the statement, and
`MethodRegistry` reads it as one.

All of it in `Ui`, which ADR 001 puts beside the bounded contexts rather than inside them, for the
same reason the REST surface is there: a delivery is not a context. `UiArchitectureTest` already
allows `Ui` to name every context's `Application` and forbids it to name any context's
`Infrastructure`, and the RPC surface needs exactly that permission and no more. No new phpat rule
was required, which is the useful signal: a second delivery that needed a new architectural
exemption would be evidence it was not a delivery.

## One endpoint

`POST /rpc`. One route, for every method, and the route count does not grow when a method is added.

The alternative was tried first: an operation attribute extending `HttpOperation`, one route per
method under `/rpc`, keeping API Platform's router, serializer, validator and schema generation. It
produces a usable API. It does not produce JSON-RPC, and the three reasons are not closeable by
configuration.

**The protocol dispatches on a member of the body.** With a route per method, `method` is either
redundant with the path or contradicts it, and there is no honest answer to which wins. A client
written against the specification sends the member; a server that ignores it is not implementing the
specification, it is implementing an API that happens to accept its envelope.

**Batching needs the single endpoint.** §6 sends an array of calls to one URI and expects an array of
answers, possibly out of order, possibly shorter than the request. A router that has already chosen
one operation from the path cannot fan out.

**The error channel is a `200`.** API Platform renders failures as RFC 9457 problem documents at 4xx
and 5xx statuses. That is right for the REST surface and wrong for every JSON-RPC error, and it is
not a formatting difference: a batch can contain one success and one failure, and there is no status
code for that.

So the framework keeps what it is good at (routing, `Accept` negotiation, the generated document, a
place for a processor to plug in) and the protocol keeps the dispatching. API Platform 4.3 makes the
same split for MCP, which is JSON-RPC too: `ApiPlatform\Metadata\McpTool` is an `HttpOperation`
subclass used as metadata, and a registry and a server do the work.

### The operation is almost entirely switched off

```php
new Post(
    uriTemplate: '/rpc',
    status: 200,
    processor: JsonRpcProcessor::class,
    output: false,
    read: false,
    deserialize: false,
    validate: false,
    serialize: false,
    hideHydraOperation: true,
)
```

Each flag is a failure channel being closed, and that is the point rather than a way of avoiding the
framework. Left on, a malformed body would be a `SerializerException` rendered as a `400`, a missing
`method` would be a `ValidationException` rendered as a `422` with a `violations` array, and an
unknown method would be a `404` from the router: three shapes, none of them JSON-RPC, for three
failures the specification names `-32700`, `-32600` and `-32601` and requires inside a `200`.

Two of these have to be said out loud because the default is actively wrong:

- **`status: 200`,** because API Platform's default for `POST` is `201 Created`
  (`HttpResponseStatusTrait::METHOD_TO_CODE`), which is not a status JSON-RPC ever sends. It is also
  documentation only: the processor returns a `Response` object, `RespondProcessor` passes those
  through untouched, and that is what makes `204` reachable;
- **`output: false`,** so the schema factory does not mint a `JsonRpc` schema out of an empty anchor
  class. The shapes on the wire are the protocol's.

What stays on is `Accept` negotiation: asking a protocol endpoint for `application/ld+json` is a
mistake about the transport rather than about JSON-RPC, and `406` says so better than any error code
could. The request `Content-Type` is *not* checked, which follows from `deserialize: false` and is
left that way: a body either decodes as JSON or it does not, and "it does not" already has an answer
in `-32700`.

## The protocol

### Everything this endpoint decides is a `200`

```json
{"jsonrpc":"2.0","error":{"code":-32000,"message":"Member \"01M35G5RKN7ST0K16W80CJQ8KK\" could not be found."},"id":8}
```

That is a `200`. So is a parse error, so is an unknown method, and so is a batch where one call
worked and one did not. The status line describes the transport; `error.code` describes what
happened.

This is the most visible difference between the two surfaces and the easiest one to resent. It is
also forced: a batch has no single outcome, so there is no status to put it in. Once the status line
cannot carry the answer for a batch, having it carry the answer for a single call would mean two
rules, and a client would have to know which case it was in before it could know where to look.

### A notification is a *valid* request with no `id`

Omit the `id` member and the call runs and nothing comes back: no result, no error, `204 No Content`.
`membership.loseBadge` is the method meant to be used this way.

The ordering matters and costs one line. §4.1 forbids replying to a notification, but §6's own
example answers `[1, 2, 3]` with three `-32600`s, none of which had an `id` to be a notification
with. So the envelope is checked first: what fails validation is answered with `id: null` whether or
not an `id` was there, and only something that passed and still has no `id` member is silent.

`"id": null` is therefore **not** a notification. It is a request whose id is null, it gets a reply
with `"id": null`, and §4 discourages exactly that because it collides with the id an unreadable
request comes back with.

The price is real and belongs in the open: a notification that fails, fails silently towards the
caller, so a wrong identifier in one produces nothing at all. `JsonRpcServer` logs it at error level
with the method name, because silence towards the caller is the specification's decision and silence
towards us would be ours.

Notifications dispatch **synchronously**. They look exactly like a case for `#[Asynchronous]`, and
they are not, for two reasons: the async transport does not work outside the test environment (see
CLAUDE.md), and queueing one would move that log line into a dead letter nobody reads.

### A batch is not a transaction

`EcotoneDbalConfiguration` opens a transaction per command-bus dispatch, so a batch of five writes is
five transactions and the third failing leaves the first two committed. §6 asks for nothing more, and
five separate REST calls behave identically. Nothing in this project pretends a multi-write operation
is atomic; ADR 003's path 5 exists precisely because that pretense is where correctness goes to die.

A caller who needs all-or-nothing needs a command that means all-or-nothing, which is one method, not
a batch.

Batches are capped at 50 members. That is implementation-defined, the specification sets no limit,
and an oversized batch is refused whole as a single `-32600` rather than half honored.

### `params` may be positional, and a parameter is a declaration

§4.2 allows by-name and by-position and a server that takes only one of them is not conformant. The
positional list is folded onto the order `RpcMethod::parameters()` declares, which is the only place
that order exists and therefore the only place that can break a client by changing. Both documents
publish it.

Each entry is a `Param`: a name, a sentence, a JSON Schema, and whether it is required. Those four
fields are OpenRPC's Content Descriptor, exactly, which is not a coincidence so much as a discovery:
this started as a name mapped to a sentence, and that was a Content Descriptor with three quarters
of it missing. Filling the rest in is what made `rpc.discover` possible, and it improved the OpenAPI
document on the way past, where `params` had been "an object, or an array" with nothing said about
what was in it.

The schema cites the domain, under the same rule ADR 005 locks for option A's constraints:
arguments are domain constants, never literals.

```php
new Param('color', 'The colour half of the pattern.', ['type' => 'string', 'enum' => Color::values()]),
```

`Color::values()` is the callback `#[Assert\Choice(callback:)]` already calls, so one palette
reaches the REST constraint, the OpenAPI schema and the OpenRPC document. Identifiers go through
`Param::identifier()`, because ADR 002 gave every aggregate in every context the same ULID-backed
`Id` and the shape is therefore written once.

**Nothing reads a schema at request time.** It is published and never evaluated, and `required` is
the same kind of declaration: it says the document should mark the parameter required, not that
anything enforces it. An absent parameter reaches the method as `''` and comes back as the violation
its own constructor raised. A schema that decided anything would be a second place to change a rule,
answering in a vocabulary with no `propertyPath` and no published code.

### The `id` member is not an identifier

It is a correlation token the caller chose, it means nothing to this application beyond matching a
response to a request inside a batch, and it is echoed back byte for byte. It is deliberately not an
`Id`: §4 lets a client send `1` or `"abc"`, and rendering the token into any canonical form breaks
the one rule the member has.

It is also not a deduplication key and not an aggregate identity. An earlier version of this code
forwarded it to the command bus as `aggregate.id`, which let a caller choose which aggregate their
command addressed by choosing a correlation token. ADR 002 issues identifiers; callers do not.

## What the two surfaces share

### One parse, two envelopes

An RPC method class is the RPC spelling of a state processor, and the resemblance is not a
coincidence, it is ADR 005 option B doing what it was chosen for:

```php
// ADR 008 rule 5. The subject is a conclusion, not a claim, so it is never a parameter and never a
// pointer: a missing one is a missing credential and answers 401, not a violation.
$memberId = $this->actors->actor()->subject();

$parsed = (new Violations())
    ->at('pattern', static fn (): BadgeDesign => BadgeDesign::fromStrings($params->string('color'), $params->string('motif')))
    ->at('condition', static fn (): BadgeCondition => BadgeCondition::parse($params->string('condition')))
    ->orThrow();
```

That is the same three-stage shape as `CastLikeProcessor`, calling the same constructors and letting
the same exceptions out. Stage 1 accumulates across fields, stage 2 is whatever the command's own
constructor checks, stage 3 needs the store and arrives alone. Neither the value objects nor the
commands know which delivery called them, and a rule fixed in the domain is fixed on both surfaces at
once.

The `condition` line is conditional in the real method, because an absent optional parameter asks for
the default rather than failing; it is unfolded here so the block still shows two pointers. That
matters more than it looks: `memberId` used to be the first of them, and it left when ADR 008 rule 5
moved the subject off the payload. The accumulation demonstration is genuinely smaller for it, and
the half that survives is the better half, since `color` and `motif` are two wire parameters reported
as one violation on `pattern`.

Two details carry across unchanged. The pointer names are the domain's, not the wire's: `color` and
`motif` arrive as two parameters and are reported as one violation on `pattern`, because
`BadgeDesign` is the smallest type that holds the rule and ADR 005 says the violation belongs where
the rule does. And a single-field method still uses `Violations`, for the pointer rather than for the
accumulation: a bare `Id::fromString()` failure reaches a caller with an empty `propertyPath`.

### The error table

`config/packages/api_platform.php` maps the marker interfaces in `Shared/Domain/Exception/` onto HTTP
statuses. `RpcErrorFactory` maps the same markers onto error codes. One domain, one set of
exceptions, two vocabularies:

| Code     | Meaning             | Marker                                            | REST  |
|----------|---------------------|---------------------------------------------------|-------|
| `-32700` | Parse error         | the protocol's                                    | `400` |
| `-32600` | Invalid Request     | the protocol's                                    | `400` |
| `-32601` | Method not found    | the protocol's                                    | `404` |
| `-32602` | Invalid params      | `UnprocessablePayloadThrowable`, `InvalidPayload` | `422` |
| `-32603` | Internal error      | anything unnamed                                  | `500` |
| `-32000` | Not found           | `DomainNotFoundThrowable`                         | `404` |
| `-32001` | Conflict            | `ConflictThrowable`                               | `409` |
| `-32002` | Failed dependency   | `FailedDependencyThrowable`                       | `424` |
| `-32003` | Unprocessable state | `DomainUnprocessableThrowable`                    | `422` |
| `-32004` | Bad request         | `BadRequestThrowable`                             | `400` |

`-32768` to `-32000` is reserved by §5.1 for the protocol, so the application-defined codes live at
the top of `-32000` to `-32099`. A new marker interface takes the next free number below `-32004`,
and it is added to both tables or it is answered as `-32603`, loudly, in the logs.

ADR 005's published failure names are strings (`membership.handle-too-short`) and cannot be the code,
which the specification requires to be an integer. They travel where they already were:

```json
{"code":-32602,"message":"2 field(s) of the payload could not be accepted.","data":{"violations":[
  {"propertyPath":"condition","message":"\"soggy\" is not a condition a badge can be in. Expected one of: pristine, worn, threadbare, holey.","code":"membership.unknown-badge-condition"},
  {"propertyPath":"pattern","message":"\"tartan\" is not a colour we stock. Expected one of: red, blue, green, yellow, purple.","code":"membership.unknown-color"}
]}}
```

That array is what `AbstractConstraintViolationListNormalizer` emits for a REST `422`, key for key. A
client that parses one parses the other, which is ADR 005's "every 422 carries a `violations` array"
surviving a change of protocol. Nothing below `-32000` carries one: those answers were decided by
asking the database and are never lists.

`-32603` says nothing at all unless `APP_DEBUG` is on, matching what `ErrorTypingProvider` does to
the RFC 9457 `detail` on a `5xx`.

### The result is a declared shape

`result` is `mixed` in the envelope, so without a per-method declaration the document degrades to
"an object" and the surface publishes whatever the handler happened to return. Each method declares
its result schema and maps into it, which is also how no aggregate reaches the wire:
`ViewReputationMethod` receives a `Reputation`, an aggregate with a derived `trustLevel()` and no
table behind it, and hands back five scalars.

### Exposure is a decision

A routing key already *is* a method name (`membership.acquireBadge`), so it is tempting to let `method`
be one and route straight onto the bus. That exposes every handler in the container the moment
somebody adds one, including `membership.advanceErasure`, which the erasure saga calls and nobody else
may. One class per exposed method keeps the surface a list somebody wrote.

### `rpc.` is a namespace, not a forbidden word

§4 reserves method names beginning `rpc.` "for rpc-internal methods and extensions and MUST NOT be
used for anything else". It reserves them *from applications*, which is narrower than forbidding
them, and the difference stops being academic the moment a JSON-RPC extension is implemented:
OpenRPC's discovery method is named `rpc.discover` and that is not negotiable.

So the rule `MethodRegistry` enforces is not "no reserved names", it is **"no reserved names from
outside the protocol"**, and where the class lives is how the two are told apart. A class in
`Shared/Infrastructure/ApiPlatform/JsonRpc/` may claim one; a class under `Ui/Api/JsonRpc/` may not, and
trying throws at wiring time naming the class, the name and the reason. Both kinds arrive through
the same tag and land in the same map, so there is still one enumeration of everything callable.

## Documenting it

Two documents, both projected from the registry, neither derived from the other. OpenRPC is this
protocol's native IDL and is the machine-readable truth; the OpenAPI operation is so that a reader
browsing the whole API sees that `/rpc` exists and what it does.

### OpenRPC, served as a method

`rpc.discover` answers with an [OpenRPC](https://spec.open-rpc.org/) document describing every
method on the endpoint, itself included.

```shell
curl -s localhost:8000/rpc -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"rpc.discover","id":1}' | jq .result
```

Three judgment calls, none of them forced by the specification:

- **it lists itself**, because a client that just called it should see it among what it got back,
  and it is the same enumeration the dispatcher answers from;
- **every method advertises the whole error table**, because `RpcErrorFactory` maps the markers
  globally and narrowing per method would be a claim about a handler that nothing checks;
- **`examples` carries the params half only.** OpenRPC's `ExamplePairing` requires `name` and
  `params` and leaves `result` optional, and a method declares a result *schema* rather than a
  result *value*.

**The version is pinned to 1.3.2.** The specification repository has
tagged 1.4.0 and 1.4.1, but the published meta-schema at `https://meta.open-rpc.org/` carries a
generated `enum` of accepted values for the `openrpc` field and that enum still stops at 1.3.2, so a
document claiming 1.4.x is rejected by the schema every tool actually fetches. Nothing emitted here
uses anything newer than 1.3.2 anyway. The `master` URL the specification names is worse still:
that file's `openrpc` enum holds the literal string `GENERATED FIELD: Do Not Edit`, so validating
against it fails on every document ever written.

### OpenAPI, as far as the format honestly goes

OpenAPI's unit of description is a path plus a verb. JSON-RPC has one of each, and everything a
caller needs is in a member of the body. Left alone, API Platform publishes one `POST /rpc` taking an
object and returning an object: true, and useless.

The trap on the way out of that is `oneOf`. It reads like a union and it is not treated as one:
most code generators flatten the branches into a single class and mark every non-shared field
optional, so `Cat | Dog` becomes one type carrying an optional `meows` and an optional `barks`. The
result accepts objects that cannot exist, a cat that barks, and throws away the branch a payload
came from. **A `oneOf` earns its place only when something recovers the branch**, and the rest of
this section is that rule applied three times.

`JsonRpcDocumentation` decorates the factory and carries what the format can:

- **one request schema per method**, `JsonRpc.Request.<name>`, with `method` as a single-value
  enum, gathered into a `oneOf` that carries a **`discriminator` on `method`** with a mapping to
  every branch. That is what makes this union real rather than flattenable: `method` is the property
  that recovers the branch, a validator picks the schema off it, and a conforming generator emits a
  union instead of a merged bag of optional fields;
- **`params` by name only.** It is genuinely an object *or* an array (§4.2) and those two have no
  property to discriminate on, so expressing both would be the same trap one level down. The
  positional form is described in prose here and typed, in order, by OpenRPC, which has somewhere to
  put it;
- **one response schema, not one per method.** A Response object carries `result` or `error` and an
  `id`; it does not carry the method it answers, so there is nothing to discriminate on and a
  per-method union would flatten. Both members are declared optional with the constraint in prose,
  which is the strongest thing a flattening-proof schema can say, and the per-method result shapes
  live in OpenRPC attached to the method, which is the only place the pairing exists;
- a `oneOf` for single-or-batch, in both directions. This one is an object against an array, which
  no generator can merge into one class, so it is a union that survives contact with tooling;
- `id` as `type: ["string", "number", "null"]` rather than a three-branch `oneOf`. It is a union of
  primitives, not of shapes, and 3.1 has a keyword for exactly that;
- one **example per method**, which is the part a reader copies;
- the `204` a notification answers, which nothing in the metadata could have inferred, and the `406`
  from `Accept` negotiation;
- the method table and the error table above, generated, ending in a pointer to `rpc.discover`.

It also **replaces** the response list rather than adding to it. API Platform documents a
`400 Invalid input` and a `422` on every `POST`, inferred from the deserialization and validation
steps this operation switched off, and neither status is reachable here.

Two JSON Schema 2020-12 keywords were tried and removed. `prefixItems` with `items: false` types a
positional call element by element and is correct 3.1, and it sat inside the undiscriminated
`params` union above, so it was precision attached to something that had to go; nothing outside a
2020-12 validator reads it anyway. The lesson is the one the whole section keeps repeating: this
document is written to be *consumed*, and a construct that only the specification supports is worth
less than a plainer one everything supports.

## The rules this obeys

1. **A capability belongs to REST when its subject has an address this application issues.**
   Otherwise it is an RPC method. Nothing is exposed on both.
2. **One endpoint, dispatching on the body.** The route count does not grow with the method count.
3. **The framework routes, the protocol dispatches.** `deserialize`, `validate` and `serialize` are
   off because each owns a failure channel and JSON-RPC has one.
4. **Every failure this endpoint decides is a `200`**, and `204` is the only other answer.
5. **A notification is a valid request with no `id` member.** An invalid envelope is always answered,
   `"id": null` is not a notification, and a notification's failure is logged.
6. **The `id` is echoed byte for byte** and is never an identifier, a deduplication key, or an
   aggregate's identity.
7. **A batch is N transactions**, capped, refused whole when oversized, and never presented as
   atomic.
8. **Both parameter styles are accepted**, and the positional order is the declared order.
9. **A parameter is a declaration, never a gate.** `Param` publishes a schema and cites domain
   constants; nothing evaluates it, and the value object's constructor stays the only authority.
10. **One parse, two envelopes.** RPC methods call the same domain constructors as the state
    processors, and `-32602` carries the identical `violations` array a REST `422` does.
11. **One error table per delivery, derived from the same markers.** Application codes live in
    `-32000` to `-32099`; a new marker is added to both tables.
12. **A method declares its result shape** and maps into it. No aggregate is normalised onto the
    wire.
13. **Exposure is a declaration**, never a consequence of a handler existing.
14. **`rpc.` is the protocol's namespace.** A method in `Shared/Infrastructure/ApiPlatform/JsonRpc/` may
    take a reserved name, an application method may not, and the difference is enforced at wiring
    time.
15. **Two documents, one source.** OpenRPC is the machine-readable truth and is served by
    `rpc.discover`; OpenAPI describes the endpoint for a reader of the whole API. Both are projected
    from the registry and neither is derived from the other.
16. **Every `$ref` resolves inside its own document.** An external URL in a `$ref` is a broken
    document, whoever recommends it; the URL belongs in a `description`.
17. **A `oneOf` needs something that recovers the branch**, a `discriminator` or a difference no
    generator can merge. Without one it flattens into a single type with every field optional, which
    accepts payloads that cannot exist. Prefer a type array for a union of primitives, and prose for
    a union nothing can tell apart.
18. **A resource answers in as many representations as it declares, at one address.** Passing the
    three tests makes something a REST resource; it does not make it a JSON one. Added by
    [ADR 009](009_one-url-two-representations.md).

### What rule 18 changes about the three tests

Nothing, and that is the point of saying it. The three tests at the top of this document ask whether
a capability has a subject with an address, whether the address is stable, and whether the operation
is one of the verbs. They are questions about the *resource*, and I wrote them without ever asking
what a caller reads it as, which turns out to have been correct by accident rather than by design.

The member directory passes all three and has an HTML representation and a JSON-LD one. A login form
fails all three, has only an HTML representation, and lives in `Ui/Web/` as a controller. `rpc.`
methods fail the first test, live on `POST /rpc`, and have no HTML representation at all, because a
protocol is not a page.

So the tests sort capabilities into REST, RPC and neither, exactly as they did; ADR 009 then asks a
second and independent question of whatever landed in the REST pile. **Do not let a capability into
the REST pile because it needs a page, and do not keep one out because it does not.**

## If you are adding an RPC method

1. Run the three tests at the top. If it passes all three it is a REST resource and belongs in
   `Ui/Api/Resources/`.
2. Add one class in `src/Ui/Api/JsonRpc/` implementing `RpcMethod`. The tag on the interface does the
   wiring; there is no map to edit and no configuration to add.
3. Name it from the routing key constant, never a literal, so the wire name and the bus name cannot
   drift.
4. Parse with `Violations`, naming the pointer the domain would name, and call the same constructors
   the REST surface calls. Do not restate a rule.
5. Declare `parameters()` as a `Param` per parameter, in the order a positional call should use,
   with a schema built from domain constants (`Param::identifier()` for anything holding an `Id`).
   Add an `example()` that would actually work and a `result()` schema. All of it is published, to
   both documents, without anybody regenerating anything.
6. If the handler can fail in a way no marker in `Shared/Domain/Exception/` covers, add the marker,
   then add it to `RpcErrorFactory` **and** to `exception_to_status`.
7. Call `rpc.discover` and read your method back. If the document does not describe what you meant,
   the declaration is wrong, not the document.

## What this ADR still owes

- **A console delivery.** ADR 005's argument for option B is that one parse serves every delivery, and
  `app:members:register` proves it for REST. Nothing proves it for these four methods, because
  nothing but HTTP calls them.
- **Authorization.** With one endpoint, per-method rules cannot use an operation's `security:`
  expression and have to move into the dispatcher. That is ADR 006 on security's problem and it should
  say so.
- **Tests.** `tests/` still holds no cases. The protocol's decision tree (batch, notification, the
  four envelope refusals, the error table) is the most testable thing in this repository and the least
  tested, and `rpc.discover` hands a test its own oracle: the document it returns either validates
  against `https://meta.open-rpc.org/` or it does not.
- **Per-method `errors`.** Every method currently advertises the whole table. Narrowing it to the
  codes a given handler can actually raise would be worth having and needs something that checks the
  claim, or it becomes a comment that lies.
