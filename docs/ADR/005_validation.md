# Validation

Validation is deciding whether what arrived is allowed to become what it claims to be.

That sentence hides the whole problem, because "what arrived" is not one thing. A JSON body, a query
string, a console option and a message pulled off a queue all arrive; a caller waits for three of
them and nobody waits for the fourth. And "allowed" is not one thing either: a handle of two
characters is not allowed because of a rule you can read off the payload, while cancelling a
subscription that is already cancelled is not allowed because of something that happened last
Tuesday.

This ADR is about handling both in one vocabulary, and about the fact that they cannot be handled by
one *mechanism*.

## Flow

A payload arrives at a delivery: an HTTP body, an `InputArgument`, a form.

Each field is parsed into a domain type. Every field is attempted, whether or not the previous one
worked, and everything that refuses to parse is collected. If anything was collected, the caller
gets all of it at once and nothing further happens.

The parsed fields are assembled into a command. The command's own constructor checks whatever spans
two fields. It can only run once every field exists, so its failure arrives alone.

The command goes on the bus. The handler loads what it needs, and the aggregate decides. Those
failures need the store, so they arrive one at a time, and that is a property of the questions rather
than a limitation of the code.

Notice what is not in that list: there is no step where a delivery asks the domain a business
question on the domain's behalf, and no step where the domain re-checks something it was handed as a
type.

## Architecture

### The line is purity, not layer

The tempting split is "shape at the edge, business rules inside". It is the wrong split, and it
fails in both directions.

It fails outward because plenty of business rules are pure. "A badge size is between 20 and 50" is a
rule the business owns, and it is a function of the payload; refusing to evaluate it at the edge
buys nothing and costs the caller a round trip per field. It fails inward because "shape" sounds
like it could live anywhere, which is how a `#[Assert\Length(max: 20)]` ends up on a DTO while a
`mb_strlen($value) > 20` sits in the value object, and six months later they disagree.

The line that predicts everything is:

> **Can this failure be known from the payload alone, without consulting a store?**

Two things fall out of it rather than having to be decreed.

**Accumulation is a consequence.** Payload facts are independent: a handle being too short says
nothing about whether a size is out of range, so they compose and you can report all of them. State
facts are dependent: you cannot ask whether a subscription is already cancelled until you know the
member exists. The first set is an applicative; the second is a monad. This ADR keeps that
distinction and spends nothing on encoding it in types, for reasons in "What this replaced".

**The duplication worry dissolves.** "A business rule at the edge" is not the problem. *A second copy
of the rule* at the edge is the problem. The predicate lives in the domain and never moves; only the
*invocation* moves outward. The edge does not restate the rule, it calls it.

So the answer to "does importing domain code at the edge make sense" is yes, and only this: value
object and command constructors. Never a repository, never an application service, never a query.
`Ui` is already allowed to name every context's `Domain`
(`tests/phpstan/Architecture/UiArchitectureTest.php`), which is exactly the permission this needs and
no more.

### One home per predicate

> A pure invariant is expressed once, in the **smallest type that can hold it**: value object, then
> command, then aggregate.

Everything at or below the command level then accumulates for free, because constructing the command
is what an edge does.

`Matching\Domain\ValueObject\MemberPair` is the worked example. It refuses to exist when its two
identifiers are equal, which is a domain invariant, decided from the payload, needing no `Member`
loaded. Any edge can evaluate it.

The corollary is the one people resist, and it is load-bearing:

> **An aggregate does not re-check what its own value objects guarantee.** Holding the value object
> is the proof.

Without that, "smallest type that can hold it" quietly becomes defence in depth, which is the
duplication this ADR exists to prevent wearing a respectable name. `Matching\Domain\Model\Like`
currently violates it: `Like::cast()` re-tests `$fromMemberId->equals($toMemberId)` and throws the
same `CannotLikeSelfException` that `MemberPair` throws. The fix is not deleting the guard, because
a `Like` is directional and a `MemberPair` is unordered, so `Like` cannot simply hold one; it is a
directional value object that does not exist yet. That is debt this ADR creates and names.

### Three stages, and accumulation happens within one

1. **Fields.** Each one parsed independently. Accumulated.
2. **Cross-field.** Whatever the command's constructor checks. Reachable only once every field
   parsed, so it is a second stage; accumulated among other stage-two checks, which in practice
   means a list of one.
3. **State.** The handler and the aggregate. Always one at a time.

```php
$parsed = (new Violations())
    ->at('fromMemberId', fn () => Id::fromString($body['from'] ?? ''))
    ->at('toMemberId', fn () => Id::fromString($body['to'] ?? ''))
    ->orThrow();                          // stage 1

return new CastLike(                      // stage 2, and its failure arrives alone
    $parsed->get('fromMemberId', Id::class),
    $parsed->get('toMemberId', Id::class),
);
```

It is worth being exact about why stage 2 cannot join stage 1, because the intuition says it should:
"these two members are the same person" is a pure check, so surely it collects alongside "this is not
a ULID"? It does not, because it cannot be *evaluated* until both identifiers exist. The staging is
forced by the data, not chosen.

### A command carries value objects; an event carries primitives

This is the rule that makes everything above possible, and it was missing from the codebase rather
than argued against. Every command took `string $handle`, `string $fromMemberId`, and the handler
parsed. Nothing recorded a decision to that effect: no ADR mentions it, no command DTO carried a
docblock, and the commit that introduced them (`c620ce9`) is about Doctrine XML mapping and argues
four mapping decisions in detail without mentioning the command shape. They were scaffolding, and
they were inherited from the event next door.

The two are not the same thing.

An **event** is a published fact. It crosses a context boundary, it may be read by a subscriber that
does not share your value objects, and it may outlive the class that emitted it. Primitives are
correct, and `MemberRegistered(string, string, string, string)` is correct.

A **command** is an intention, addressed to one handler, inside one context, never published. None of
the reasons that make an event primitive apply to it.

The tree says so, and it is worth checking rather than believing: every command dispatch originates
in `Ui`, the composition point, or inside the sending context itself (`ErasureService` sending itself
`ADVANCE_ERASURE`). **No context sends a command into another one.** What does cross is events;
`Matching`'s feed projection consumes `Membership`'s `MemberRegistered`, `MemberProfileChanged` and
`MemberStatusSwitched`, and `MatchingErasureSubscriber` consumes `MemberErased`.

So the usual defence of primitives, that a context might one day split into its own project, points
the other way: if `Matching` leaves, its commands go with it and nothing serializes them. The wire
contract left behind is those four events, and they are already primitive. Keeping commands primitive
insures against a migration those classes will never face.

**Serialization is the transport's problem, not the message's.** Synchronous or asynchronous is a
decision about delivery, flipped by an attribute, and a message must not be designed around which one
it got. In a single deployable a local message is passed by reference and never serialized at all;
where a channel does serialize, the converter belongs in `Shared/Infrastructure/Ecotone` next to the
channel that needs it. `CloseOneExpiredSubscription` is the one command in the tree that crosses an
`#[Asynchronous]` channel, and it now carries an `Id` and a `\DateTimeImmutable` rather than two
strings it was re-parsing on arrival.

What did *not* change is `#[TargetIdentifier]` on ADR 003's option A commands. `TrackedAggregateRepository::identity()`
already branched on `$value instanceof Id` before falling back to `Id::fromString()`, and
`Subscription`'s own `#[Identifier]` was always an `Id`. The command was the odd one out.

Three things fell out of the conversion that were bugs rather than style:

- `Color::from()` and `Motif::from()` throw `\ValueError`, which implements no marker, so an unknown
  colour on a request body was a **500**. They have `parse()` now, throwing
  `InvalidBadgeDesignException`;
- `Id::fromString()` delegated to `Ulid::fromString()`, which throws a bare
  `\InvalidArgumentException`, so `/members/banana` was a **500**. That was the most reachable defect
  of its kind in the tree, because every endpoint takes an identifier;
- `LikeService::block()` did `BlockReason::tryFrom($raw) ?? BlockReason::Other`, so a caller who
  typed `harasment` got a 200 and a harassment report filed under "other". ADR 004 rule 11 already
  refuses a silent default for filters; a request body deserves the same answer.

None of those were findable while the parse lived in a handler, which is the practical argument for
the rule and not just the tidy one.

### The command is a proof

> Once a command is constructed, its payload is well formed and internally consistent.

That is the contract, and it buys a sentence a caller can hold in their head: a 422 with a list means
your payload is wrong and here is all of it; anything else means your payload was fine and the world
is not in the state your request assumed.

It also means a command arriving at a handler, including one deserialized off a queue months later,
needs no re-parsing. If a malformed one ever arrives, that is a schema-versioning bug and should be
loud, not absorbed.

## What the failure is

There are two vocabularies here, and conflating them is the most common way this design goes wrong.

**Kind of failure** is the marker interfaces in `Shared/Domain/Exception/`, and it drives the HTTP
status. **Disposition** exists only on the asynchronous path, and it drives whether a message is
retried, dropped or compensated. *Kind never determines disposition*; the async section explains why.

ADR 005 adds the sixth marker, and its value is in the line it draws against its two neighbours:

| Marker | Means | Answers with |
| --- | --- | --- |
| `BadRequestThrowable` | I could not work out what you asked | 400, one failure |
| `UnprocessablePayloadThrowable` | Understood, and the content is unacceptable | 422, a list |
| `DomainUnprocessableThrowable` | Your payload was fine; the world is not in the state you assumed | 422, one failure |

`BadRequestThrowable` was doing two jobs before this ADR. The audit moved three exceptions off it,
all of them pure: `InvalidHandleException`, `CannotLikeSelfException`, `CannotBlockSelfException`.
What remains on it is `Shared\Application\ReadModel\BadFilterInput`, which is ADR 004's query
parameters and exactly the right sole implementor. A malformed `?page=0` genuinely is "I could not
work out what you asked": the request line itself is unintelligible. A body field that parsed as JSON
and then failed a rule is not.

`DomainUnprocessableThrowable` is state-level, and therefore never collected and never raised at an
edge. `MemberIsBlockedException` is the shape of it.

### The carrier, and why it is Symfony's

Exceptions are the only failure channel in this codebase, because PHP has exactly one channel that
composes with constructors and constructors are where invariants belong. `Handle::fromString()`
has to throw; there is no way to return a failure from something whose job is to refuse to produce an
invalid object.

Given that it throws, accumulation is a `catch` loop at the edge. That loop is
`Shared\Application\Validation\Violations`, and it produces a
`Symfony\Component\Validator\ConstraintViolationList`.

Using Symfony's type here is deliberate, and the reasoning is worth spelling out because the obvious
objection is that we have reimplemented it. `ConstraintViolation` already carries every field a
violation needs, including `propertyPath`, a `messageTemplate`/`parameters` pair for translation, and
a string `code`. API Platform's `AbstractConstraintViolationListNormalizer` emits exactly
`propertyPath`, `message` and `code`. Inventing a lighter copy would have produced a *different*
422 body from the one option A produces, in a repository where the generated OpenAPI document is a
deliverable. So:

> **Both of this ADR's designs produce a `ConstraintViolationList`.** They differ only in who
> decided.

What is genuinely not a reimplementation is the operation. `ValidatorInterface::validate($object,
$constraints)` presupposes the object; its failure mode is "the thing you are holding is wrong".
`Violations::at()` attempts to *build* one; its failure mode is "the thing cannot exist". Symfony has
no "attempt to construct", so the accumulating loop and the typed success channel have no equivalent
there, and those are the two classes that survive.

Three files, and each earns its line:

- `Violations`, the parse loop. Catches only `UnprocessablePayloadThrowable`, which is the set that
  may be collected by definition; anything else escaping a parse closure means that closure touched
  a store, and it bubbles.
- `ParsedPayload`, the typed success channel. It exists so PHPStan can see that nothing is null, and
  the class says so. The shorter design returns `T|null` from `at()` and narrows via a side effect on
  another object, which PHPStan cannot follow; suppressing that at level 6 would be arguing against
  ADR 002, whose entire bet is that `Id<A>` holds because PHPStan checks it.
- `InvalidPayload`, the container. It implements the same marker as its contents, which is what keeps
  `exception_to_status` to one new entry: five bad fields and one bad field are the same kind of
  thing.

`Symfony\Component\Validator` moved *down* a ring for this: out of `ArchitectureTestCase::miscellaneous()`,
where it was allowed all the way into a Domain, and into `application()`. A domain exception says what
is wrong; turning that into a violation is the edge's job, and no Domain names the component any more.

## Expressing validation

Two designs, both shipped, the way ADR 004 ships two filter designs. The axis is the same one: does
the delivery own the vocabulary, or does the domain declare it once for every delivery?

### Option A, the constraint on the resource

Put Symfony constraints on the API resource and let API Platform do what it does by default.

```php
#[Assert\Length(min: Handle::MIN_LENGTH, max: Handle::MAX_LENGTH)]
#[Assert\Regex(pattern: Handle::PATTERN)]
public string $handle;
```

You get accumulation, a 422, RFC 9457 rendering and the JSON Schema keywords, for nothing.
`ValidatorPropertyMetadataFactory` decorates the property metadata factory chain, so the constraints
feed the generated document; `ValidateProvider`/`ValidateProcessor` read `canValidate() ?? true`, so
they also enforce at request time. Both, by default, from one declaration.

Three costs, and they are what push some payloads to option B.

**The vocabulary is closed.** Only the fourteen classes under
`api-platform/symfony/Validator/Metadata/Property/Restriction/` project into JSON Schema: `Length`
becomes `minLength`/`maxLength`, `Regex` becomes `pattern`, `Range` and the comparisons become
`minimum`/`maximum`, `Choice` becomes `enum`, `Count` becomes `minItems`/`maxItems`, plus `format`
and `required`. Every one of them is about a single property. "These two fields must differ" is not
expressible, at all.

**It is HTTP only.** A console command gets nothing from it and would have to run the validator by
hand or restate the rules.

**The constraint is a second evaluator** of a rule the domain owns. That is acceptable; a second
*owner* is not. Hence the rule:

> A constraint's arguments MUST be domain constants, never literals.

`Handle::MIN_LENGTH` and `Handle::PATTERN` are public for exactly this, and say so. One home for
each value, the delivery still advertises it, and drift stops being a matter of discipline: a literal
inside an `Assert` is grep-able as the violation.

### Option B, the domain parses

The value object's constructor is the authority, and `Violations` runs it against raw input.

```php
$parsed = (new Violations())
    ->at('handle', fn () => Handle::fromString($body['handle'] ?? ''))
    ->at('preferences', fn () => MatchingPreferences::fromArray($body['preferences'] ?? []))
    ->orThrow();

return new RegisterMember(
    $parsed->get('handle', Handle::class),
    $parsed->get('preferences', MatchingPreferences::class),
);
```

Fully expressive, cross-field invariants included. One implementation serves every delivery, so the
console and the API answer with the same violations. The cost is the mirror of A's: the OpenAPI
keywords have to be produced deliberately rather than harvested, by hand in
`#[ApiProperty(openapiContext:)]`.

### Which goes where

`Membership` demonstrates A and `Matching` demonstrates B, and the examples are chosen so the boundary
is visible in the tree rather than only described here. Every rule in `Handle` except the trim
projects cleanly into the document, so a handle is what option A is for. `CannotLikeSelfException`
is a relation between two fields and no restriction class expresses it, so a like is what option B is
for. A reader who tries to write the like in option A discovers the limit themselves, which is worth
more than this paragraph.

A third option was considered and rejected: generating the schema keywords from a domain declaration,
the way `DeclaredFiltersFactory` generates `QueryParameter`s in ADR 004. It is correct, and it builds
machinery to replace fourteen classes API Platform already ships. If a third delivery ever needs the
same declarations, reopen it; the ADR 004 precedent is right there.

## What the two designs actually produced

Both are built now, so the comparison is measured rather than predicted. `POST /members` is option A
and `POST /likes` is option B; both answer 422 and both carry a `violations` array, which was the
whole point of choosing `ConstraintViolationList` as the shared carrier.

Five differences survived, and only the first was expected.

**The `code` vocabulary is not shared.** Option A emits Symfony's constraint UUIDs, because that is
what a `Constraint` carries:

```json
{"propertyPath": "handle", "message": "This value is too short…", "code": "9ff3fdc4-b214-49db-8718-39c315e33d45"}
```

Option B emits the declared code, which is the published contract lock 13 asks for:

```json
{"propertyPath": "from", "message": "\"banana\" is not an identifier this application issued.", "code": "shared.malformed-identifier"}
```

A caller routing on `code` therefore has to know which endpoint they are talking to, which is a cost
option A cannot be argued out of.

**A code may not contain a slash.** The first version of this ADR wrote `membership/handle-too-short`,
in the RFC 4151 style, and every option B response was a 500. `ValidationException::getId()` composes
the problem's identifier out of each violation's code and API Platform generates
`/validation_errors/{id}` from it, so a slash breaks route generation. The separator is a dot, which
also matches the routing-key convention already in the tree (`membership.registerMember`). The tag URI
keeps the slash between authority and specific part and nowhere else.

**A write is its own resource, not an `input:` on a read one.** The first version used API
Platform's `input:`/`output:` to alias a DTO onto an existing resource's operation, and the generated
document said what was wrong with it: the request schema was named `Member.RegisterMemberInput`, a
name describing the mechanism rather than the thing. A registration is not a member; it has different
fields, a different lifecycle, and it happens once. Each write now has its own `#[ApiResource]`, and
the schemas are called `MemberRegistration`, `MemberHandle`, `MatchingPreferences`, `LikeCast`,
`Block` and `Pair`. Nothing in the tree uses `input:` or `output:` any more.

Two consequences follow. Resource classes are grouped into `Resources/Membership/` and
`Resources/Matching/`, because a flat directory of eleven classes stops saying anything. And the
operations are held together for a *reader* by an explicit `tags:` rather than by their class, so
`/members` is served by five classes all tagged `Member`.

That last part has a trap, and it is the fifth member of the OpenAPI family in `CLAUDE.md`.
`OpenApiFactory` writes `$tags[$name] = new Tag(description: $resource->getDescription())` once per
resource, keyed by name, so whichever class the metadata factory reaches last supplies the
description for the whole group, silently and in class-iteration order. The descriptions cannot be
dropped from the resources either, because the first rule exists precisely to stop the teaching
docblock being published in their place. `openapi.tags` in config looks like the answer and is not:
API Platform turns those arrays into `Tag` objects in the extension and the container refuses to dump
a parameter holding an object. So it is a factory decorator,
`Shared/Infrastructure/ApiPlatform/OpenApi/TagDescriptions`, next to the one ADR 004 already added for
the same class of reason.

A write resource has no item operation, so in JSON-LD it gets a skolem `@id`
(`/.well-known/genid/…`). That is the correct answer rather than a gap: a registration has no URL of
its own, and the member it created does.

**The seam is a factory, not a subclass.** Extending `ApiPlatform\Validator\Exception\ValidationException`
reads naturally and fails with `Operation "" not found for resource "ValidationException"`: the parent
carries `#[ErrorResource]` metadata declared on *that* class, and the metadata factory looks up the
subclass. API Platform's error resources are configuration keyed by class name, so they are extended
by being used.

**Option A is worse at lists than expected.** `Choice` with `multiple: true` reports one violation
against `colors`, reading "One or more of the given values is invalid" without saying which, and
without listing the palette. Its *schema* is better (`items.enum` documents the palette properly);
its *message* is worse than the domain's, which names the offending value and what would have parsed.
Neither design is good at lists, and they are bad in different places.

**A request-body schema must not carry `default`.** `public string $handle = ''` puts
`"default": ""` in the generated schema, telling a client that omitting a required field means empty
string. Promoted constructor parameters with no defaults fix it, and missing fields then arrive as
422 violations anyway because `collect_denormalization_errors` is on. This is `CLAUDE.md`'s fourth
OpenAPI rule, which was written about query parameters and turns out to be about request bodies too.

One thing the build changed in the design rather than merely confirming: **stage 2 is reported in
stage 1's shape.** Left alone, a cross-field failure reaches a caller as a bare problem document with
a `detail` and no `violations`, while a failure one keystroke away comes back as a list. Two shapes,
both 422, for what is from the outside the same sentence. So an edge converts, through
`Violations::of()`, and every 422 this application sends carries `violations` with the offending
field's pointer or an empty one when the failure belongs to the relationship rather than to either
field. A client writes one branch.

## Rendering the failure

One error object, two renderers, which is `BadFilterInput`'s shape generalised.

**HTTP.** `InvalidPayload` crosses into API Platform at
`Shared/Infrastructure/ApiPlatform/Validation/ValidationException`, which is the only place that may know
about both ends: `Shared\Application` may name `Symfony\Component\Validator` and may not name
`ApiPlatform`. API Platform's `ValidationException` implements
`ConstraintViolationListAwareExceptionInterface`, which `ErrorListener` checks for by hand, so the
list renders natively from there. Option A throws the same base class without needing the adapter,
which is the convergence the shared carrier bought.

**Console.** `InvalidPayload::EXIT_CODE` is 22, the same EINVAL `BadFilterInput` returns. Not a
duplicated decision: a caller who typed something unacceptable should get one answer whether they
typed it into a query string or a request body, and a script should not have to tell those apart.

**The type URI.** `UnprocessablePayloadThrowable::code()` is declared, never derived from the class
name. Deriving it would publish our namespace as public contract and make every rename a breaking
change for callers who never asked how we file our exceptions. The code includes the context, which
is the only part a caller can usefully route on:

```
tag:gheb.dev,2026:membership/handle-too-short
```

That is an RFC 4151 tag URI, which is the right shape for an RFC 9457 `type`: it needs to be a stable
identifier and it does not need to resolve. The specific part is `code()` verbatim. There is a bonus
in having made the codes real: `ValidationException::getId()` composes the problem's identifier out
of each violation's `getCode()`, so a caller sees the published names rather than an
`spl_object_hash`.

## Asynchronous, where nobody is waiting

The unifying question for the whole document is not "which layer" but:

> **Who is waiting for an answer?**

Synchronous, a caller is waiting, so the failure is **rendered**: a 422 with a list, another 4xx, an
exit code. Asynchronous, nobody is waiting, so the failure is **recorded**. Same taxonomy, two
terminals.

Four things can be wanted when an async handler fails, and most of them turn out not to be error
handling.

**Retry.** The only genuinely infrastructural one, and the default: backoff, then Messenger's
`failed` transport, then a human. `EcotoneDbalConfiguration` deliberately leaves retry and DLQ to
Messenger.

**Poison.** A message that can never succeed, such as a v1 payload reaching a v2 handler. It needs its
own path because retrying it burns the queue. By "the command is a proof" this should be
unreachable, so its arrival is a schema-versioning bug and must be loud.

**Drop.** Not a failure. A message that is no longer wanted is answered by the handler *returning
normally*. The moment a transport is configured to interpret domain exception classes and swallow
some of them, you have built a config file that silently discards real errors, and eventually it
will. Duplicate delivery never gets this far: `EcotoneDbalConfiguration` deduplicates for RabbitMQ's
at-least-once redelivery. Staleness is a domain question with a domain answer, which is `return`.

> **A dropped message leaves a domain trace.** Returning is how a handler says "no longer wanted";
> publishing why is how the business finds out. Silence toward the caller is not silence in the
> system.

**Compensate.** Also not failure handling. It is a process manager, and its input is a domain event
rather than a caught exception. Accepting a transaction and later rejecting it for fraud is two
facts, not an undo of the first. ADR 003's path 5 erasure saga is the shape.

Which leaves the mechanism:

> **Disposition is declared by catching, in the handler.** An uncaught throwable means one thing,
> preserve the message, and that is the only default. There is no table mapping exception classes to
> transport behaviour.

It has to work that way because the same exception means opposite things to different consumers.
`SubscriptionNotFoundException` in one handler means "the projection is behind, a retry will find
it"; in another it means "the member was erased, no retry ever will". Nothing in the class can tell
you which, and only the handler knows.

```php
try {
    $subscription = $this->subscriptions->get($command->subscriptionId);
} catch (SubscriptionNotFoundException) {
    // Erased between the read model listing it and this message being consumed.
    // Nothing left to close, and nothing a retry can find.
    $this->eventBus->publish(new SubscriptionCloseAbandoned($command->subscriptionId));

    return;
}
```

A sibling handler meeting the same exception lets it bubble and gets retry. A third publishes a
failure event its saga subscribes to. Three dispositions, one exception class, and the difference is
visible at the only place that knows.

## The rules this obeys

1. **Two vocabularies, not one.** Kind of failure drives the HTTP status; disposition exists only on
   the async path. Kind never determines disposition.
2. **The axis is purity, not layer.** A check may be accumulated if and only if it is a pure function
   of the payload.
3. **One home per predicate: the smallest type that can hold it.** An aggregate does not re-check
   what its own value objects guarantee.
4. **Accumulation happens within a stage.** Fields, then cross-field construction, then state.
5. **Exceptions are the only failure channel**, and accumulation is a `catch` loop rather than a
   monad.
6. **A command is a proof** that its payload is well formed and internally consistent. Nothing
   downstream re-parses it.
7. **Three answers to three questions.** 400 is "I could not work out what you asked"; 422 is
   "understood, and the content is unacceptable", possibly a list; 404, 409 and 424 are "your payload
   was fine, the state refuses".
8. **`DomainUnprocessableThrowable` is state-level.** Payload-level failures carry
   `UnprocessablePayloadThrowable`, which declares `code()`.
9. **Both designs ship.** In option A the constraint decides, and its arguments MUST be domain
   constants, never literals. In option B the value object's constructor decides, and one
   implementation serves every delivery.
10. **An async handler has one infrastructural failure mode: preserve the message.** Dropping and
    compensating are domain decisions, declared by catching, never by a transport-level exception
    table.
11. **A dropped message leaves a domain trace.**
12. **One error object, two renderers**: a marker for the HTTP status, exit code 22 for the console.
13. **The RFC 9457 `type` comes from a declared `code()`**, never from a class name, and that code
    may not contain a `/`, because API Platform renders it as a URI path segment.
14. **A command carries value objects; an event carries primitives.** A command is an intention
    addressed to one handler inside one context. An event is a published fact that crosses one.
    Serialization is the transport's problem either way.
15. **Every 422 carries a `violations` array**, stage 2 included, so a client parses one shape.
16. **A write is its own resource.** No `input:`, no `output:`; a registration is not a member.
    Operations are grouped for the reader by an explicit `tags:`, and tag descriptions are declared
    once in a factory decorator rather than raced for by whichever resource is processed last.

## If you are adding validation

1. Can the failure be known from the payload alone? If yes it is collectable and belongs at or below
   the command; if it needs the store it belongs in the handler or the aggregate and will arrive
   alone.
2. Which is the smallest type that can hold the rule? Put it there, once. If you are about to write
   the same guard in two places, one of them is wrong, and it is usually the aggregate.
3. Is it expressible as one of the fourteen JSON Schema restrictions, about a single property? Then
   option A, and cite the domain constant. Otherwise option B.
4. Does the failure need a new exception, or a new `code()` on an existing one? A caller routes on the
   code, so "too short" and "has a space in it" are two codes even when they are one class.
5. Which marker? Pure means `UnprocessablePayloadThrowable`. Needing the store means one of the
   state-level three. "I could not work out what you asked" means `BadRequestThrowable`, and in
   practice that is only ADR 004's query parameters.
6. Does the command carry the parsed type, or a string the handler will re-parse? If the second,
   the edge cannot accumulate, and you are about to write the bug this ADR was opened over.
7. Is anyone waiting? If the handler is `#[Asynchronous]`, decide the disposition by catching, and if
   you are dropping the message, publish why.

## What this replaced

The first version of this ADR argued for a functional applicative validator, and
`src/Shared/Domain/Validation/` implemented it: `Validation::validate()` returning a
`ValidationResult` whose contents were reachable only through `process($onValid, $onErrors)`, composed
with globally-loaded `curry`, `atomicValidation`, `sequentialValidation` and
`sequentialAccumulativeValidation`. Both are deleted. The reasoning is worth keeping.

**The insight was right.** Independent failures accumulate and dependent ones short-circuit; that is
the applicative/monad distinction and it is the correct model of the problem. This ADR keeps it, as
rules 2 and 4.

**The machinery was not worth its price in PHP.** No do-notation, no higher-kinded types, no pattern
matching, so every call site pays ceremony for a distinction that two stages express for free.

**And it only answered half the question.** It had nothing at all to say about
`SubscriptionAlreadyCancelledException`, which is where the hard failures live. It was not a
unification; it was an expensive treatment of the easy half.

The decisive evidence was in the tree. Nothing outside that directory ever returned a
`ValidationResult`. The only thing anyone imported from it was `Equality`, which is not validation:
comparing two values that already exist is the opposite of deciding whether one may be built. It now
lives in `Shared/Domain/Models/` next to `Id`. `Handle` carried the confession in its own docblock,
that the composition was "a later iteration once the functional validator API is worth the ceremony
here", and `Shared/Infrastructure/ApiPlatform/Validation/ValidationException` carried the other half, a
`// TODO make something better` over a converter from a `string[]` nothing produced. That TODO was
really asking where the violations were supposed to come from, and `Violations` is the answer.

Per the standing rule in `CLAUDE.md`, the ADRs lead and the code follows; when the code cannot
follow, that is a finding worth reopening the earlier ADR over. This is that.

The deletion also removed the `services.php` exclusion, the unresolvable `Validators\HasKey` import
that the `phpat` rules had been reporting, and about two thirds of the PHPStan level 6 backlog: 97
errors before, 30 after, none of them in the code this ADR added.

It removed both `autoload.files` entries too, and those were the only ones. They are what
`ecotone.php`'s `loadSrcNamespaces => false` was working around ("Ecotone has its own attribute
loader and psr-4 autoload which is not compatible with functions files"), so the workaround has
nothing left to work around. The container compiles cleanly with the flag flipped, which exercises
`EcotoneExtension::load()` and therefore the PSR-4 scan; that is evidence rather than proof, and
turning it on deserves its own commit and a look at `ecotone:list`.

## What this ADR still owes

Named here rather than discovered later:

- **`Like` duplicates `MemberPair`'s invariant**, against rule 3. The fix is a directional value
  object, because a `Like` is directional and a `MemberPair` is unordered, so `Like` cannot simply
  hold one.
- **Queries carry `Id` but no other parsed identifier work is done.** ADR 004 had already given query
  DTOs their enums; this pass gave them their identifiers, so no handler parses any more. Nothing
  else on the read side changed.
- **The `#[Asynchronous]` converter is unwritten and untested.** `CloseOneExpiredSubscription` now
  carries an `Id` and a `\DateTimeImmutable` across the `async` channel. Under `APP_ENV=test` the
  channel is in-memory and passes objects by reference, so it proves nothing, and in dev the
  transport does not work at all (`symfony/amqp-messenger` is not installed). When the async story
  matters, that converter is the first thing to write.
- **RPC.** `Shared/Infrastructure/ApiPlatform/JsonRPC/` still references a deleted exception class and
  has no operations. Whether an intention is better expressed as RPC than as REST is its own
  argument, and ADR 008 is where it belongs.
- **Translation.** `ConstraintViolation`'s `messageTemplate`/`parameters` pair is the seam and
  `Violations` currently fills the template with the interpolated message. When messages need
  translating, that is the pair to populate, and the domain exceptions will need to carry parameters
  the way `AbstractDomainUnprocessableException` already does.
