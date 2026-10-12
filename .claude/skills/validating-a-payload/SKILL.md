---
name: validating-a-payload
description: Validate incoming data, add a constraint or a value-object invariant, or choose which exception marker and HTTP status a failure gets in this DDD/CQS tree, following ADR 005 - the purity-not-layer rule, the three accumulation stages, Violations/ParsedPayload/InvalidPayload, option A Symfony constraints versus option B domain parsing, and the marker interfaces in Shared/Domain/Exception. Use before writing any check on a request body, a console argument or a message payload, and before adding any exception class.
---

# Validating a payload

The argument is `docs/ADR/005_validation.md`.

## The line is purity, not layer

> **Can this failure be known from the payload alone, without consulting a
> store?**

Not "shape at the edge, business rules inside", which fails both ways: "a badge
size is between 20 and 50" is a business rule *and* a function of the payload,
and "shape" sounds like it could live anywhere, which is how an
`#[Assert\Length(max: 20)]` and an `mb_strlen($value) > 20` end up disagreeing
six months later.

Two things fall out rather than being decreed:

- **Accumulation is a consequence.** Payload facts are independent and compose;
  state facts are dependent and arrive one at a time.
- **The duplication worry dissolves.** A business rule at the edge is not the
  problem; a second *copy* of it is. The predicate lives in the domain and never
  moves. **Only the invocation moves outward: the edge calls the rule, it never
  restates it.**

So importing domain code at an edge is correct, and exactly this much: value
object and command constructors. Never a repository, never an application
service, never a query.

## One home per predicate

> A pure invariant is expressed once, in the **smallest type that can hold it**:
> value object, then command, then aggregate.

And the corollary people resist, which is load-bearing:

> **An aggregate does not re-check what its own value objects guarantee.**
> Holding the value object is the proof.

Without it, "smallest type that can hold it" quietly becomes defence in depth.
`Matching\Domain\Model\Like` currently violates this by re-testing
`MemberPair`'s self-like guard; the fix is a *directional* value object, since a
`Like` is directional and a `MemberPair` is unordered. That is known debt, not
your regression.

## Three stages, and accumulation happens within one

1. **Fields.** Each parsed independently, every one attempted. Accumulated.
2. **Cross-field.** Whatever the command's constructor checks. It cannot be
   *evaluated* until every field exists, so it is a second stage and in practice
   a list of one. The staging is forced by the data, not chosen.
3. **State.** The handler and the aggregate. Always one at a time.

```php
$parsed = (new Violations())
    ->at('fromMemberId', static fn (): Id => Id::fromString($body['from'] ?? ''))
    ->at('toMemberId',   static fn (): Id => Id::fromString($body['to'] ?? ''))
    ->orThrow();                          // stage 1

return new CastLike(                      // stage 2, its failure arrives alone
    $parsed->get('fromMemberId', Id::class),
    $parsed->get('toMemberId', Id::class),
);
```

`Shared/Application/Validation/` holds three classes and each earns its line:
`Violations` (the parse loop; catches **only** `UnprocessablePayloadThrowable`,
so anything else escaping a closure means that closure touched a store and it
bubbles), `ParsedPayload` (the typed success channel, so PHPStan can see nothing
is null), `InvalidPayload` (the container, implementing the same marker as its
contents).

**Use `Violations` even for a single field**, for the pointer: a bare
`Id::fromString()` failure reaches a caller with an empty `propertyPath`.

**Name the pointer the domain would name.** `color` and `motif` arrive as two
parameters and are reported as one violation on `pattern`, because `BadgeDesign`
is the smallest type that holds the rule.

## A command is a proof

> Once a command is constructed, its payload is well formed and internally
> consistent.

So **a command carries value objects; an event carries primitives.** A command
is an intention addressed to one handler inside one context and is never
published; an event is a published fact that crosses one and must stay readable
without sharing our types. Nothing downstream re-parses a command, including one
deserialized off a queue months later; a malformed one arriving is a
schema-versioning bug and should be loud.

Serialization is the transport's problem. Where a channel serializes, the
converter belongs in `Shared/Infrastructure/Ecotone` next to the channel.
**Known gap: `CloseOneExpiredSubscription` carries an `Id` and a
`\DateTimeImmutable` across the `async` channel and no converter exists.**

## Which marker, and therefore which status

`Shared/Domain/Exception/`, mapped in `exception_to_status` in
`config/packages/api_platform.php`. Three of them are easy to confuse:

| Marker                          | Means                                         | Answers          |
|---------------------------------|-----------------------------------------------|------------------|
| `BadRequestThrowable`           | I could not work out what you asked           | 400, one failure |
| `UnprocessablePayloadThrowable` | Understood, content unacceptable              | 422, **a list**  |
| `DomainUnprocessableThrowable`  | Payload fine; the world is not as you assumed | 422, one failure |

`BadRequestThrowable`'s only right implementor is ADR 004's `BadFilterInput`: a
malformed `?page=0` genuinely is an unintelligible request line. A body field
that parsed as JSON and then failed a rule is not.
`DomainUnprocessableThrowable` is state-level, needs the store, and is never
raised at an edge. The others are `DomainNotFoundThrowable` (404),
`ConflictThrowable` (409), `FailedDependencyThrowable` (424), plus ADR 008's
`UnauthenticatedThrowable` (401) and `ForbiddenThrowable` (403).

**All of them extend `TranslatableThrowable`, so all of them declare `code()`.**
Adding a marker means adding it to `exception_to_status` **and** to
`RpcErrorFactory`, or it is answered as `-32603`.

Never throw an HTTP exception from a domain.

## The two designs, both shipped

**Option A, the constraint on the resource** (`Membership`, `Handle`). API
Platform enforces *and* documents it from one declaration, for free.

> A constraint's arguments MUST be domain constants, never literals.

`Handle::MIN_LENGTH` and `Handle::PATTERN` are public for exactly this. A
literal inside an `Assert` is grep-able as the violation. Three costs: the
vocabulary is closed to the fourteen classes under
`api-platform/symfony/Validator/Metadata/Property/Restriction/`, all of them
about a single property, so "these two fields must differ" is inexpressible; it
is HTTP only; and it is a second *evaluator* of a rule the domain owns.

**Option B, the domain parses** (`Matching`). Fully expressive, cross-field
included, one implementation for every delivery. The cost is that OpenAPI
keywords have to be produced by hand in `#[ApiProperty(openapiContext:)]`, and
(per ADR 007) that its messages are ours to translate while option A's arrive
already translated by Symfony in fifty-seven locales.

**Both answer with a `ConstraintViolationList`**, so the 422 body is identical
and only the authority differs. The `code` vocabularies are not shared: option A
emits Symfony's constraint UUIDs, option B the declared codes.

## Rules the 422 body obeys

- **Every 422 carries a `violations` array, stage 2 included**, converted
  through `Violations::of()`, so a client writes one branch. Left alone, a
  cross-field failure arrives as a bare problem document with a `detail` and no
  `violations`.
- **A declared code may not contain a `/`.** `ValidationException::getId()`
  composes the problem IRI out of the violation codes and a slash breaks route
  generation. Dots, matching the routing-key convention.
- **A write is its own resource.** No `input:`, no `output:`; a registration is
  not a member. The one legitimate `output: false` in the tree is on the RPC
  resource, for a different reason.
- **A request-body schema must not carry `default`.** `public string $handle = ''`
  publishes `"default": ""`, telling a client that omitting a required field
  means empty string. Use promoted constructor parameters with no defaults;
  `collect_denormalization_errors` turns missing fields into 422 violations
  anyway.
- The seam into API Platform is a **factory, not a subclass**:
  `Shared/Infrastructure/ApiPlatform/Validation/ValidationExceptionFactory`. Extending
  `ApiPlatform\Validator\Exception\ValidationException` fails with
  `Operation "" not found for resource "ValidationException"`.

## Asynchronous, where nobody is waiting

Synchronous, a caller waits, so the failure is **rendered**. Asynchronous,
nobody waits, so it is **recorded**. Same taxonomy, two terminals.

> **Disposition is declared by catching, in the handler.** An uncaught throwable
> means one thing, preserve the message, and that is the only default. There is
> no table mapping exception classes to transport behaviour.

It has to work that way because the same exception means opposite things to
different consumers. Retry is the only infrastructural mode and belongs to
Messenger's `failed` transport. **Drop is not a failure**: a handler says "no
longer wanted" by *returning normally*, and **a dropped message leaves a domain
trace** - publish why. Compensate is a process manager whose input is a domain
event, not a caught exception.

## Checklist

1. Payload alone? Collectable, at or below the command. Needs the store?
   Handler or aggregate, arriving alone.
2. Smallest type that can hold the rule. Writing the same guard twice means one
   of them is wrong, and it is usually the aggregate.
3. Expressible as one of the fourteen restrictions, about a single property?
   Option A, citing the domain constant. Otherwise option B.
4. New exception, or a new `code()` on an existing one? A caller routes on the
   code, so "too short" and "has a space in it" are two codes even in one class.
5. Which marker? Then the catalogue entry, which is the `failure-messages` skill.
6. Does the command carry the parsed type, or a string a handler will re-parse?
7. `#[Asynchronous]`? Decide disposition by catching, and publish why on a drop.
