# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

**A boilerplate, not an application.** DDD + CQS on Symfony 7.4 / PHP 8.5, with API Platform 4,
Ecotone (CQS buses, async messaging), Doctrine ORM 3, PostgreSQL and RabbitMQ. It ships the shared
machinery every decision below rests on, the architecture rules that keep it honest, and the ADRs
that argue for all of it. **It ships no bounded context**, because which contexts exist is the one
thing a boilerplate cannot decide for you.

So `src/` has two directories and both are deliberate:

- **`src/Shared/`** is the machinery: `Id` and its Doctrine type, the read vocabulary, the
  validation loop, the failure markers and codes, the security port and its adapters, the JSON-RPC
  protocol, the pagination adapters, the Ecotone transaction configuration, the aggregate tracker
  and the outbox;
- **`src/Ui/`** is the delivery scaffolding: the JSON-RPC processor, the console query and write
  helpers, the console security commands, the login and logout controllers, `app:messages` and
  `app:access`.

Everything else is yours. `src/<Context>/` does not exist yet and the "If you are adding" checklist
at the end of each ADR is how you start one.

### How to read the ADRs

`docs/ADR/` is the reasoning, and it is the point of this repository. **The ADRs lead, the code
follows.** When an ADR moves, the code owes it a commit, and when the code cannot follow, that is a
finding worth reopening the earlier ADR over.

> **The ADRs argue with worked examples from a reference application**, a `Membership` context
> (members, their badge collection, profile history, a subscription, a catalogue, reputation,
> erasure) and a `Matching` one (likes, blocks, pairs, a discovery feed). **None of that code is in
> this repository.** A named class like `Member`, `BadgeDesign` or `CandidateFeedInterface` is an
> illustration, not a file you can open, and a command like `app:paths` or `app:reads` belongs to
> that application too. What *is* here is every mechanism those examples lean on.
>
> That is deliberate rather than a gap. A rule with no example is a rule nobody can check, so the
> examples stay; they just stop being the tree.

Read `001_architectural-organization.md` before moving anything between layers, and
`002_identifying-everything.md` before touching identifiers. `003_store-the-aggregate.md` decides
how an aggregate is stored, and **`docs/storage-map.md` says which of its five answers fits what**;
read that before adding persistence of any kind. `004_filter.md` decides where filtering and
pagination live (read models, never repositories); read it before adding anything that returns a
list. `005_validation.md` decides what may be accumulated and which exception marker a failure
takes. `006_rest-vs-rpc.md` decides what is a REST resource and what is a method on `POST /rpc`;
read it before adding an endpoint of either kind. `007_translation.md` decides where a string
becomes words a human reads, and **no exception anywhere carries a sentence**; read it before
writing any message a caller could see. `008_security.md` splits security into four questions with
four different homes and decides that we **ask for a permission, never for a role**; read it before
adding any endpoint at all. `009_one-url-two-representations.md` decides that a resource has **one
URL whose representation is chosen by `Accept`**, so a page is not a delivery with routes of its
own; read it before adding a controller, a template or a second URL for something that already has
one.

Two of those have a command that walks them end to end here, `app:messages` and `app:access`. Both
pass on an empty tree and keep passing as you fill it, which is the point: they prove nothing is
*missing*, never that anything is right.

## Commands

```shell
composer install                              # needs ext-amqp; see the note below
docker compose up -d                          # postgres + rabbitmq (management UI on :15672)
php bin/console lexik:jwt:generate-keypair    # REQUIRED first run: no keypair is committed
php bin/console doctrine:database:create
php bin/console doctrine:schema:create --em=default
php bin/console doctrine:schema:create --em=read_model
symfony serve                                 # or: php -S localhost:8000 -t public

php bin/console app:messages                  # every declared failure code vs every enabled locale (ADR 007)
php bin/console app:access                    # every operation and what guards it (ADR 008)
php bin/console app:credentials:issue         # issue a credential so somebody can sign in
php bin/console app:tokens:issue              # mint a bearer token; app:tokens:revoke withdraws one

make phpstan                                  # level 6 over src/, needs --memory-limit=1G. Clean.
vendor/bin/phpunit                            # config: phpunit.dist.xml, bootstrap tests/bootstrap.php

php bin/console api:openapi:export            # the document is a deliverable (ADR 006)
php bin/console ecotone:run async             # async consumer (see ecotone:list)
```

**Generate the JWT keypair before anything else.** `config/jwt/*.pem` is not committed and
`.gitignore` keeps it that way, so until you run `lexik:jwt:generate-keypair` the `api` firewall
cannot verify a token and `app:access` and `make phpstan` both refuse with a message saying so.

**`ext-amqp` is a hard requirement**, because `symfony/amqp-messenger` is. On a machine without it,
`composer install --ignore-platform-req=ext-amqp` works and everything except a real async consumer
runs; under `APP_ENV=test` the channels are in-memory and the extension is never reached.

**The two walk commands pass on an empty tree.** `app:messages` reports the shared catalogue
complete; `app:access` reports that every operation names a permission or declares itself unguarded,
of which there are currently none. Both keep passing as you add contexts, and both fail loudly the
first time you add a code with no translation or a write with no declaration. ADR 003's `app:paths`
and ADR 004's `app:reads` are the reference application's and are not here; the checklists at the
end of those two ADRs are what to follow instead.

**`doctrine:schema:update` is not usable and there are two `--em` above for the same reason.** Two
entity managers share one connection (path 3), and `schema:update` diffs one manager's metadata
against the whole database, so each one drops the other's tables. `schema:create` only ever creates.

`.env` ships `DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"` even though the Doctrine and
compose config target PostgreSQL; override it locally when you want the real platform (the `IdType`
column type and `identity_generation_preferences` differ per platform).

## Configuration style

**Symfony config is PHP, not YAML.** Every `config/**.php` file returns `App::config([...])` (or
`Routes::config([...])`) in the `Symfony\Component\DependencyInjection\Loader\Configurator` namespace;
`config/reference.php` is the generated array-shape reference that types those arrays. The YAML
equivalents were deleted; do not reintroduce them.

Two independent service-discovery mechanisms coexist, and this matters when adding a class:

- `config/services.php` autoregisters `../src/**/{Api,Application,Console,Infrastructure}/`, excluding
  each context's `Application/{Command,Query,Event}/` (those are serialized payloads, not services),
  all of `src/Shared/Application/` (ADR 004's read vocabulary and ADR 005's `Validation/` are values
  that travel inside a query or are built with `new` at an edge) and the Kernel. **Domain classes are
  not Symfony services; Application ones are**, because Ecotone's bundle resolves every handler class
  out of the container and throws at compile time when one is missing.
- `config/packages/ecotone.php` scans `namespaces` (`App\Shared\Infrastructure`, `App\Membership`,
  `App\Matching`, `App\Ui`) for Ecotone attributes. **Adding a bounded context means adding its root
  namespace here.**
  `loadSrcNamespaces` is off because Ecotone's PSR-4 loader chokes on function files loaded via
  composer's `autoload.files`. ADR 005 deleted the only two such files, so the flag now guards
  nothing; the container compiles with it on, but flipping it deserves its own commit.

## Layout

Per ADR 001, `UI` sits at the *same level* as the bounded contexts rather than inside them, so the API
surface stays consistent across contexts:

```
src/
├── Ui/                      # Api/{Resources,JsonRpc,StateProvider,StateProcessor}, Console/, Web/
├── Shared/                  # Domain/ + Infrastructure/ reusable across contexts
└── <Context>/               # Application/ (Command, Query, Event + handlers), Domain/ (Model,
                             # Repository interfaces, Exception), Infrastructure/ (Persistence, ...)
```

**There is no `Ui/Infrastructure/` any more**, and that is a recent move rather than the original
shape. The framework adapters that used to live there (the API Platform metadata factories, the
OpenAPI decorators, the JSON-RPC protocol, the pagination adapters, the security vocabulary, the
translation renderer) are now under `src/Shared/Infrastructure/`, and most of them carry the
namespace of the library they extend (`ApiPlatform\Metadata\GuardedOperationsFactory`,
`ApiPlatform\JsonRpc\JsonRpcServer`, `Translation\FailureMessages`) rather than `App\`. Two extra
PSR-4 roots in `composer.json` map those two prefixes into that directory, and `config/services.php`
registers them under their own prefixes because the `App\**` glob cannot derive their class names.
[ADR 001](docs/ADR/001_architectural-organization.md)'s reference tree still shows the old shape and
says so at its end; the move owes that document an argument.

Dependencies point inwards only. Cross-context reads go through domain events replicating into a local
read model, or an Open Host Service call placed in the Application/UI layer, never a direct call into
another context's services or tables.

### Current state of the tree

**No bounded context exists yet.** `src/` is `Shared/` and `Ui/`, and `src/<Context>/` is the thing
you add. The shape every context takes is the one in the diagram above: `Application/` (Command,
Query, Event plus their handlers), `Domain/` (Model, Repository interfaces, Exception) and
`Infrastructure/` (Persistence, ReadModel, ...).

Adding one touches five places and missing any of them fails quietly rather than loudly. The
`wiring-a-class` skill walks all five; the short list is:

| add | where |
|---|---|
| the root namespace | `config/packages/ecotone.php`, or no handler in it is ever found |
| a Doctrine mapping entry | `config/packages/doctrine.php`, one per namespace prefix |
| a phpat rule set | `tests/phpstan/Architecture/` plus `phpstan.dist.neon`, or the namespace goes unchecked |
| a `FailureCode` enum | your context's `Domain/Exception/`, and its entries in the catalogue |
| a `Permission` enum | `Shared/Infrastructure/Security/Permission/`, and a grant in `RoleMap` |

Nothing is autoregistered by being present, which is deliberate: the container, Ecotone and phpat
each discover differently, and the "Configuration style" section above says how.

**Before deciding how to store anything, open `docs/storage-map.md`** rather than copying whichever
neighbour you landed on. In the reference application the neighbours deliberately differ, and the
map is the index of which answer goes with which shape of problem.

## Key mechanisms

> Everything below is a decision an ADR takes and this repository implements. Where a paragraph
> names a class under `Shared/` or `Ui/`, that file is here and you can open it. Where it names a
> `Membership` or `Matching` class, an endpoint path or a context-owned enum, that is the reference
> application illustrating the rule, and the "If you are adding" checklist in the matching ADR is
> what to follow when you want one of your own.


**CQS through Ecotone.** Application services carry `#[CommandHandler]` / `#[QueryHandler]` methods keyed
by string routing constants declared on the class (`const REGISTER_MEMBER = 'membership.registerMember'`),
and callers use `sendWithRouting($routingKey, $payload)`. Commands/queries are DTOs under
`Application/Command` and `Application/Query`.

**A command carries value objects; an event carries primitives** (ADR 005). `RegisterMember` takes a
`Handle` and a `BadgeDesign`, `CastLike` takes two `Id<Member>` and refuses equal ones in its own
constructor; `MemberRegistered` takes four strings. A command is an intention addressed to one
handler inside one context and is never published, so no handler parses any more and the edge can
accumulate; an event crosses a context boundary and must stay readable without sharing our types.
Verified: no context sends a command into another, and four `Membership` events are what `Matching`
consumes. Sync versus async is the transport's concern, so a channel that serializes needs a
converter rather than a primitive command.

**Storing an aggregate**, the subject of ADR 003. Five paths, mapped to
features in `docs/storage-map.md`. The short version:

- paths 1 to 3 are Doctrine's own `UnitOfWork`, with the mapping doing progressively more work
  (column list → custom types, embeddable, child collection → a second entity manager);
- path 4 is `src/Shared/Infrastructure/Persistence/Aggregate/`: an `AggregateReader` and an
  `AggregateWriter` per class, an `AggregateTracker` holding an identity map plus which aggregates
  were touched plus field-level changesets, and `SaveTrackedAggregatesInterceptor` writing them at
  the command-bus boundary. **That interceptor's pointcut is the bus, not `CommandHandler::class`**;
  the latter wraps only Ecotone's CallAggregate step and silently never writes;
- path 5 is the outbox (`Shared/Infrastructure/Persistence/Outbox/`) and the erasure saga, for
  anything that spans two stores. Nothing anywhere pretends a cross-store write is atomic.

**One aggregate, one owner.** Either Doctrine tracks it end to end or the path 4 tracker does, never
both.

**Transactions.** `Shared/Infrastructure/Ecotone/EcotoneDbalConfiguration` is the source of truth: a DB
transaction plus ObjectManager clear/flush per async message and on the command bus, deduplication for
RabbitMQ at-least-once redelivery, and no Ecotone dead-letter/consumer-position tracking (retry/DLQ stay
with Messenger's `failed` transport). **No repository flushes**, because that configuration already
does; the one exception is `DoctrineMemberErasureRepository`, and its docblock says why.

**Identifiers.** `Shared/Domain/Models/Id` is a single ULID-backed `final readonly` class with a
`@template-covariant A` phantom type: annotate `Id<Member>` in PHPDoc rather than subclassing per
entity; that choice is argued at length in ADR 002. `IdType` registers it as the Doctrine `Id` type
and picks GUID-vs-binary(16) storage per platform.

**`A` is covariant, and `Shared/Domain/Models/PrincipalAggregate` is why.** `Shared` may not name a
bounded context, so the security vocabulary holds an `Id<PrincipalAggregate>` and `Member`
implements that marker. Two consequences, in opposite directions:

- **widening is free.** An `Id<Member>` is an `Id<PrincipalAggregate>`, which is what covariance
  buys and what an invariant `@template` refused. `A` appears in no parameter position, so the
  variance is sound; the one place it did, `Id::sort()`, takes `self<*>` and says why;
- **narrowing is a declaration, made once.** `Actor::subject(Member::class)` takes a
  `class-string<T>` it never reads, purely so PHPStan resolves `Id<T>`. The alternative was
  `@var Id<Member>` at twenty three call sites, which is the same unchecked assertion made twenty
  three times rather than declared once. `Ownership::orNotFound()` takes the context's own
  `::unknown(...)` as a third argument for the same reason: the rule is shared, the failure code is
  not.

**Reusing this tree means implementing `PrincipalAggregate` on your own principal and passing it to
`subject()`.** Nothing under `Shared` changes.

**Validation**, ADR 005. The line is **purity, not layer**: a check may be accumulated if and only if
it is a pure function of the payload, which includes construction invariants like
`MemberPair`'s "not yourself". Predicates live in the smallest type that can hold them and are
*called* from the edge, never restated there; an aggregate does not re-check what its value objects
guarantee. Accumulation happens within a stage: fields, then cross-field construction, then state.
Exceptions are the only failure channel, and `Shared/Application/Validation/Violations` is the
`catch` loop that collects them into a `ConstraintViolationList`.

Two designs ship, mirroring ADR 004's two filter designs. **Option A** puts Symfony constraints on the
API resource and lets API Platform enforce *and* document them (`Membership`/`Handle`); its arguments
**must be domain constants, never literals**, and its vocabulary is limited to the fourteen classes
under `api-platform/symfony/Validator/Metadata/Property/Restriction/`. **Option B** parses in the
domain and serves every delivery from one implementation (`Matching`, whose self-like rule option A
cannot express at all). Both answer with a `ConstraintViolationList`, so the 422 body is identical
and only the authority differs.

Every `422` carries a `violations` array with a `propertyPath` and a `code`, stage 2 included
(converted through `Violations::of()`), so a client parses one shape. **A declared code may not
contain a `/`**: `ValidationException::getId()` composes the problem's IRI out of the violation
codes, and a slash breaks route generation, so the separator is a dot (`membership.handle-too-short`),
matching the routing-key convention. The two designs do not share a code vocabulary: option A emits
Symfony's constraint UUIDs, option B the declared codes.

`Shared/Domain/Validation/` (a functional applicative validator) was deleted by this ADR; it was
never used outside itself. `git show e8831f0:...` has it, along with the earlier ADR text.

**Errors → HTTP.** Domain code throws exceptions implementing the marker interfaces in
`Shared/Domain/Exception/` (`BadRequestThrowable`, `UnprocessablePayloadThrowable`,
`DomainNotFoundThrowable`, `ConflictThrowable`, `DomainUnprocessableThrowable`,
`FailedDependencyThrowable`, plus ADR 008's `UnauthenticatedThrowable` and `ForbiddenThrowable`);
the mapping to status codes is the `exception_to_status` block in
`config/packages/api_platform.php`. Add a marker interface there rather than throwing HTTP exceptions
from the domain. Three of them are easy to confuse and ADR 005 draws the line: `BadRequestThrowable`
is "I could not work out what you asked" (400, and in practice only ADR 004's query parameters);
`UnprocessablePayloadThrowable` is "understood, content unacceptable" and is the only collectable
one; `DomainUnprocessableThrowable` is state-level, so it needs the store and is never raised at an
edge. All six extend `TranslatableThrowable` (ADR 007), so all six declare a `code()`.

**Translation**, ADR 007, and `app:messages` checks it. **A failure carries a declared code and a
named parameter map, never a sentence**, so `getMessage()` returns `membership.handle-too-short` and
the English lives in `translations/failures+intl-icu.en.xlf` and nowhere else. The code is the
catalogue key, which is what ADR 005 bought without knowing it: stable, published, dot-separated,
declared rather than derived.

Codes are enum cases, one enum per context (`SharedFailure`, `MembershipFailure`, `MatchingFailure`,
all implementing `FailureCode`), because that is what makes the set *enumerable* and therefore
checkable; `app:messages` walks `cases()` against every `framework.enabled_locales` entry and exits
non-zero on a gap, which no AST extractor could do. `Shared/Domain/Exception/DeclaredFailure` is the
trait every one of the twenty nine implementations uses, and
`Membership/Domain/Exception/MemberNotFoundException` is where the "no identifiers" argument is
written down in full.

**Only `Ui` renders.** `Shared/Infrastructure/Translation/FailureMessages` is the one class in the tree
that calls `trans()`, and phpat enforces it. It has no fallback logic on purpose: Symfony's fallback
catalogue is the fallback, and `trans()` returns the raw key when nothing resolves at all. Its
`translate()` asks the catalogue whether it owns a violation's template before touching it, because
option A's violations arrive **already** translated by `ConstraintViolationBuilder` from the
`validators` domain while option B's arrive carrying a code.

**Nothing carries a locale.** `framework.set_locale_from_accept_language` plus
`set_content_language_from_locale` plus `enabled_locales` is the whole of resolution, so no signature
anywhere takes one, and `Accept-Language` is in `api_platform.php`'s `vary`. `Accept-Language` is a
*preference*, never a parameter: it is negotiated down to `default_locale` rather than answering 400,
which is the one place ADR 007 and ADR 004 deliberately differ. The console renders at
`default_locale` and there is no `--locale`. No aggregate holds a locale and there is no `Locale`
value object, by decision: the notification gateway hands a recipient reference to a provider that
knows its own users' languages, so the only candidate had no consumer.

**A parameter is a value the sentence talks about, never a piece of the sentence.** This is the rule
that catches the next bug: `BadFilterInput::notA($key, $raw, 'an identifier')` used to pass English
prose as an argument and rendered as *« banana » n'est pas an identifier*. It is now eight declared
codes. If a parameter's value would have to change with the locale, it is a code of its own.

**An identifier is a value the reader cannot use, so no message interpolates one.** Thirteen entries
did, and *La paire « 01JB4K9T5N8P1QDE2FGHIJKLMN » est introuvable* helps nobody: a ULID is not a
name, it is on no screen, and the caller typed it a moment earlier. Pass the handle when one exists
(`membership.handle-taken` names the handle, every filter failure names `{value}`, and
`membership.unknown-revision` keeps `{sequence}` because that is what the caller will change) and pass
nothing when one does not. The identifiers left `parameters()` and not just the catalogue, because
**a failure can only leak what it hands over** and the XLIFF is edited by people who never open the
exception. Five not-founds are now `::unknown()` and three conflicts `::again()`, for want of an
argument to take.

**Reading, filtering, paginating**, ADR 004, and `app:reads` walks all of it. A repository answers by
identity; a read model answers by criteria. Every list goes through a port in a context's
`Application/ReadModel/` returning DTOs, never through a `Domain/Repository/` interface and never
returning an aggregate. Filter values and the page window are exposed parameters carried together
inside the query object, parsed by the delivery layer, and a parse failure is a 400 or an exit code
22 (`Shared/Application/ReadModel/BadFilterInput`, which every console command catches).

The shared read vocabulary is `Shared/Application/ReadModel/`: `Page`/`Slice` for what is asked,
`PageOf`/`SliceOf` for what comes back, `Total` for how hard to count. **A port offers one mechanism
and says so in its signature**, and nothing implements both; `Shared/Infrastructure/ReadModel/Cursor`
is the opaque token, opened only by the adapter that minted it. API Platform's paginator interfaces
are adapted *outward* in `Shared/Infrastructure/ApiPlatform/State/Pagination/`, never copied inward.

Two filter designs coexist on purpose: option A, where the query DTO's constructor is the whole
vocabulary (`Matching/Application/Query/ListCandidates`), and option B, one declaration generating
every delivery's surface (`Membership/Application/ReadModel/MemberDirectoryFilters` plus
`Shared/Infrastructure/ApiPlatform/Metadata/DeclaredFiltersFactory` and `Ui/Console/Query/FilterSetOptions`).
Generated `QueryParameter`s carry `constraints: []` deliberately: without it API Platform validates
them itself and answers 422, which breaks the one-failure-rule above.

A write that starts from a filter is two steps: identifiers from a read model, then one command per
aggregate, asynchronous, one transaction each. `Membership/Application/SubscriptionMaintenanceService`
is the worked example and the only `#[Asynchronous]` handler in the tree.

**There is no shared filter abstraction, deliberately; no `Criteria` travelling from UI to store,
no expression tree, no interface promising both offset and keyset. Read the ADR before adding one back.

**Write endpoints.** There are none yet, and the scaffolding for them is. Processors go in
`src/Ui/Api/StateProcessor/`, providers in `src/Ui/Api/StateProvider/`, resources in
`src/Ui/Api/Resources/`, and RPC methods in `src/Ui/Api/JsonRpc/`; all four directories exist and
three are empty. `patch_formats` is already in the API Platform config, which ADR 005 put back,
so a `PATCH` works the day you declare one.

The reference application's shape is worth knowing before you add the first one. Its writes are
`POST /members` (option A), `PATCH /members/{memberId}`,
`PUT /members/{memberId}/matching-preferences`, `DELETE /members/{memberId}` (202, ADR 003's
erasure saga), `POST /likes` and `POST /blocks` (option B), `DELETE /pairs/{pairId}`. Two console
writes exist alongside them mainly to show that option A is HTTP only: they solve the same problem
with option B and render the same violations to exit code 22. The writes that have no resource to
be a write *of* are RPC methods instead, which is the line ADR 006 draws.

**Every write is its own resource; `input:` and `output:` are not used.** A registration is not a
member, so `MemberRegistrationResource`, `MemberHandleResource`, `MatchingPreferencesResource`,
`MemberErasureResource` and `LikeCastResource` each carry their own `#[ApiResource]` and one
operation. Resources are grouped into `Resources/{Membership,Matching}/` (the directory scan is
recursive) and held together for a reader by an explicit `tags:`. **Tag descriptions are declared
once in `Shared/Infrastructure/ApiPlatform/OpenApi/TagDescriptions`**, because `OpenApiFactory` otherwise
lets whichever resource is processed last supply the description for a whole tag, and the
`openapi.tags` config option cannot be used (API Platform builds `Tag` objects in the extension and
the container refuses to dump an object parameter).

**API layer.** API Platform resources are read from `src/Ui/Api/Resources` and
`.../RPC` only (`doctrine: false`, so no Doctrine state providers, so **every collection operation needs a
hand-written provider**). State providers live in `src/Ui/Api/StateProvider/` per ADR 001's layout, which
is why `config/services.php` autoregisters `Api` alongside `Application`, `Console` and `Infrastructure`;
resource classes are excluded from that, being metadata rather than services. `UiArchitectureTest` forbids
`Ui` from naming any context's `Infrastructure`, so a provider reaches a read model only through the query
bus. The read endpoints are the five in ADR 004.

**RPC is one endpoint, `POST /rpc`, and ADR 006 decides what goes on it.** `.../RPC` holds the single
`#[ApiResource]`; the protocol lives in `Shared/Infrastructure/ApiPlatform/JsonRpc/` and each exposed
method is one class under `Ui/Api/JsonRpc/`, tagged by the interface and therefore wired by existing.
The operation turns `deserialize`, `validate` and `serialize` off on purpose: each of those owns a
failure channel and JSON-RPC has one, so the whole error channel is `JsonRpcServer`'s. Failures are
`200`s carrying an integer code, `-32602` repeats ADR 005's `violations` array verbatim, and a call
with no `id` is a notification answering `204`. Methods parse with `Violations` and the domain's own
constructors, exactly as the state processors do; nothing is exposed both here and over REST.

**`rpc.` is the protocol's namespace, not a forbidden word.** `MethodRegistry` refuses a reserved
name from a class under `Ui/Api/JsonRpc/` and allows one from `Shared/Infrastructure/ApiPlatform/JsonRpc/`,
which is where `DiscoverMethod` serves OpenRPC's `rpc.discover`. Each parameter is a `Param` (name,
prose, JSON Schema, required) whose schema cites domain constants and is **published, never
evaluated**; `OpenRpcFactory` and `JsonRpcDocumentation` are two projections of the same registry,
so adding a method updates both documents and neither is derived from the other. The `openrpc`
version is pinned to `1.3.2` deliberately: the published meta-schema's generated `enum` stops there,
so a 1.4.x document fails validation everywhere. `OpenRpcFactory`'s docblock says how to re-check.

Two rules the generated documents learned the hard way, both in ADR 006. **Every `$ref` points
inside its own document**: an external URL is a JSON Pointer that cannot resolve, and one of them
(the meta-schema, which OpenRPC's own spec tells you to `$ref`) took Redoc down with
`Invalid ref JSON pointer` before a single endpoint rendered. External schemas are cited in a
`description`. And **a `oneOf` needs a `discriminator`** or a difference no code generator can merge
(object versus array), because generators otherwise flatten the branches into one type with every
field optional, which accepts payloads that cannot exist. `JsonRpc.Request` discriminates on
`method`; `params` is documented by name only and the response is one schema, because neither has
anything to discriminate on.

**The generated OpenAPI document is a deliverable, not a by-product** (`bin/console api:openapi:export`).
Four rules keep it honest, and each one exists because the default did something wrong:

- **every resource carries `description:` on `#[ApiResource]`.** Without it API Platform falls back to
  the class PHPDoc, and `OpenApiFactory` publishes that as the OpenAPI tag description, so the teaching
  docblocks were shipping to callers as the public reference, `{@see}` tags and all. The docblock is for
  maintainers; `description:` is for callers;
- **operation prose goes in `openapi: new Operation(summary:, description:)`.** A `description:` on the
  operation itself feeds the *schema*, leaving the operation reading "Retrieves a Foo resource.";
- **collection operations set `paginationEnabled: false`,** which does not disable pagination. The hydra
  `view` comes from the `PaginatorInterface` the provider returns and is untouched. The flag only stops
  `OpenApiFactory::getPaginationParameters()` inventing a `page` documented as "The collection page
  number" with `default: 1`, which was added before the declared parameters and won the merge, silently
  replacing the accurate descriptions on `/likes`, `/subscriptions` and `/members`;
- **document defaults in prose, never as `schema['default']`,** which `ParameterProvider` reads at
  request time. A default written for documentation would become behaviour, decided in the delivery
  layer instead of in the handler or in `Window`. Use `examples` instead.

A response property whose PHP type is a collection of an Application DTO (`list<Candidate>`) cannot be
resolved by the schema factory and silently degrades to `array<string|null>`, so the two cursor feeds
spell `members` out in `#[ApiProperty(openapiContext:)]`. `Shared/Infrastructure/ApiPlatform/OpenApi/BadFilterInputResponses`
decorates the factory to add ADR 004's 400 to every operation that takes a query parameter, once,
because "a parse failure is a 400" is a fact about the whole API rather than about any one endpoint.

**One URL, two representations**, ADR 009, and it is the most recent decision in the tree. **A URL is
not a delivery.** A page is not a fourth surface with routes of its own; it is a second
*representation* of an address API Platform already owns, chosen by `Accept` exactly as `application/
ld+json` is. So there is no `/members/{id}/page`, and the Twig delivery adds templates rather than
routes. Sixteen numbered rules; these are the ones that bite first:

- **an operation declares its representations in `outputFormats:`, and `html` is never in the global
  `formats` list.** A global one let `SwaggerUiProvider` answer the documentation page for every
  `text/html` request. A media type an operation does not declare is a `406`, never a degraded
  rendering; do not parse `Accept` yourself and do not add a `_format` suffix (rule 3);
- **exactly one class of ours turns state into a representation, and it is the rendering processor**
  (rule 2). Reading the request format to decide *how to refuse* or *whom to ask* is not rendering,
  which is why `SecurityEntryPoint` and a firewall's request matcher may read it;
- **a resource page has no controller** (rule 4). `Ui/Web/` holds only what no resource represents:
  an empty form, login and logout, and assets that are a pure function of their path. A `Ui/Web`
  controller never writes a resource and never calls an RPC method class (rule 5);
- **a template is named for the resource and the operation**, at
  `templates/<context>/<resource>/<operation>.html.twig` (rule 7). Templates are not source;
- **no write operation has an HTML representation** (rule 6). `error_formats` is status-agnostic, so
  the `html` that sends a browser's 403 to an error page would send a 422 there too, and a 422 has
  to come back to the form with the typed values intact. So **every form is submitted by the script,
  never natively**: `public/form.js` is the one script, the form carries `data-action` and
  `data-method` rather than `action` and `method`, and a `409` is a page-level notice because ADR
  005 gives a conflict no `propertyPath`;
- **two firewalls, chosen by the credential and never by the path** (rule 8), which is why
  `BearerTokenRequestMatcher` exists and `pattern` does not appear on the API firewall. The session
  is PHP's, the bearer token is ours, and a revocable one is a JWT whose `jti` is the row (rule 9).
  `POST /login` selects on `Content-Type`, the one place in the document that negotiates on the
  request (rule 16);
- **a page composes; a resource does not grow** (rule 12). A member added to a representation so a
  template can draw something is the failure the rule exists to name;
- **one route, one action, one controller file, and the guard is a class attribute** (rule 14), so
  `app:access` walks `Ui/Web/` the way it walks resources and RPC methods. An operation with no
  actor is unguarded **and says so**, with `#[Unguarded(because: '...')]`, which is how `app:access`
  tells a decision from an oversight (rule 13);
- **a representation need not be a document**: `image/svg+xml` on `/badge-designs/{color}/{motif}`
  is the proof, produced in `Ui/Web/Rendering/`.

ADR 009 amends ADR 006 and supersedes part of ADR 001's "a page is a delivery" section; both say so.

**Security**, ADR 008, decided and largely built. `Shared/Infrastructure/Security/` holds the
vocabulary and the port: `Actor` (who is asking, plus an `Authority` saying how we know),
`Permission` with one enum per context, `Gate` with its single `AccessDecisionGate` adapter,
`CurrentActor` with three implementations and no alias, `PermissionVoter`, `RoleMap`, `Ownership`,
and the authentication half (`Credential`, `CredentialProvider`, `AccessTokens`, `JwtTokenHandler`,
`SecurityUser`, `SecurityEntryPoint`, `AccessTokenGrant`). Four rules govern all of it:

- **ask for a permission, never for a role.** A role is somebody's answer to the question, and it is
  what a Keycloak or a Casbin would replace. `RoleMap` is the only file that names one;
- **the actor is an argument, never ambient.** `Gate::isGranted(Actor, Permission)` takes it, which
  is why the adapter depends on `AccessDecisionManagerInterface` (it takes a token) and not on
  `AuthorizationCheckerInterface` (it reads one from storage, and two of the four deliveries have no
  storage). Nothing but a `CurrentActor` implementation may read an ambient token;
- **four questions, four homes.** Authentication at the firewall (401); permission at the gate (403);
  **ownership at the aggregate or a scoped provider (404, never 403)**; entitlement at a local
  projection (422 with what would lift it, or a narrower representation). The gate takes no subject
  precisely so it cannot be asked the last two;
- **a command is authorized once, at the edge; an event is never authorized.** There is no
  `Actor::system()`, an operation with no actor is *unguarded* rather than privileged, and the
  command bus is deliberately not the firewall. Where freshness matters a handler re-reads the
  **state** a decision depended on, never the grant.

The first four steps of ADR 008's closing section have landed: the vocabulary and the port;
authentication (`CredentialProvider`, `JwtTokenHandler`, `SecurityUser`, a stateless bearer firewall
beside the stateful form one); the declaration and its factories (`#[Guarded]`, its ADR 009 rule 13
counterpart `#[Unguarded(because:)]`, `GuardedOperationsFactory`, `RpcMethod::permission()`,
`bin/console app:access`); and the subject move. The subject now comes from the principal on `POST /likes`, `POST /blocks` and on
`membership.acquireBadge`, `membership.loseBadge` and `membership.discardBadge`, which was a breaking change
to both published documents and is why the ADR preceded the endpoints.

Still open: moderators and therefore `Trust`, the device authorization grant, the RFC 9457 `401`
body, the fact that no test proves any of it, and **the principal's type**, which is `Id<Member>`
throughout and therefore makes `Shared` depend on a context (see "Known in-flight breakage"). `app:access` proves that every operation names a
permission and that every permission is both granted to somebody and asked by something, which is
exactly as much as `app:messages` proves about the catalogue: that nothing is missing, never that
anything is right.

## Testing

`tests/` holds two bootstraps, two helper traits and the `phpat` rules. **There are no test cases**,
so `vendor/bin/phpunit` reports "No tests executed", which is the true state of an empty boilerplate
and not a broken install. PHPUnit runs with `failOnDeprecation`, `failOnNotice` and `failOnWarning`.

Two traits exist because they are the awkward half to write from scratch, and both work against a
context you have not written yet:

- `Tests\Functional\AsyncMessageChannelTrait::consumeAsyncMessages()` drains a channel through the
  real consumer on the application container, so the same handlers, interceptors and one transaction
  per message;
- `Tests\Functional\EcotoneEventDispatcherTrait` gives `sendCommand`, `sendQuery` and
  `publishEvent` through Ecotone's `FlowTestSupport`, where `#[Asynchronous]` collapses to inline
  execution.

**Tests must never publish to RabbitMQ**; a real queue is shared with whatever worker is running,
which would replay test messages against a development database. Two layers enforce it and **both
must stay in place when you add a channel or a transport**: `MessageChannelBuilderFactory` swaps
Ecotone channels for in-memory queue channels under `APP_ENV=test`, and
`config/packages/messenger.php` points the Messenger transports at `in-memory://`.

## State of the checks

**`make phpstan` is clean at level 6**, and it should stay that way: there is no documented error
baseline here, so any error is yours. `phpstan.dist.neon` carries exactly one `ignoreErrors` entry,
for `RenderViolations`, a trait with no user until you write your first console write command, and
the comment beside it says to delete the entry at that point.

`app:messages` and `app:access` both exit 0 on the empty tree. Neither proves anything is *right*;
they prove nothing is *missing*, which is the only thing a walk over a declared set can prove.

PHPStan needs a warm container, because `symfony.containerXmlPath` points at
`var/cache/dev/App_Shared_Infrastructure_Symfony_KernelDevDebugContainer.xml`. If analysis fails to
boot, run `php bin/console cache:warmup` first, and check the JWT keypair exists.

`banned_code` is on: no `dump`, `dd`, `var_dump`, `print_r`, `echo`, `print`, `exit`, `eval`,
`assert`, `shell_exec` and no backticks. Do not leave a debug call in.

## Architecture rules (phpat)

`tests/phpstan/Architecture/` holds `phpat` rules (a PHPStan extension, not PHPUnit, registered as
`services` with a `phpat.test` tag in `phpstan.dist.neon`, run by `vendor/bin/phpstan analyse`/`make
phpstan`) encoding the layering from "Layout" above: each context's `Domain` can only depend on itself,
`Shared\Domain`, and a short allow-list of framework-free vendor conveniences (`ArchitectureTestCase`);
`Shared` may never depend on a context or `Ui`; `Matching\Domain` is the one context allowed to depend on
another context's `Domain` (`Membership`, since a `Pair`/`Like` names a `Member`; see
`MatchingArchitectureTest`'s docblock); `Ui` is the only namespace allowed to depend on every context.
`phpat.ignore_built_in_classes: true` keeps PHP's own `Override`/`UnitEnum`/SPL exceptions out of the
vendor graph. New context or new layer → give it a test class in that directory and register it in
`phpstan.dist.neon`'s `services` block, or the namespace goes unchecked.

Three deliberate widenings, each argued in the docblock that makes it: `Psr\Clock` is allowed in a
Domain; `Ecotone\Modelling\Attribute` is allowed in a Domain for the single aggregate on ADR 003's
option A (`Membership\Domain\Model\Subscription`, whose commands therefore live in
`Membership/Domain/Command/`); and `Ui` may name each context's `Application`, because expressing an
intention means putting a command object on the bus. `Ui` still may not name any context's
`Infrastructure`.

Three deliberate **narrowings**, the only rules that have ever moved inward. ADR 005 took
`Symfony\Component\Validator` out of `miscellaneous()`, where it had been allowed all the way into a
Domain, and put it in `application()`. A domain exception says what is wrong; turning that into a
`ConstraintViolation` is the edge's job (`Shared/Application/Validation/`), so no Domain names the
component any more. ADR 007 added `no_translator_outside_ui` to the three context rule sets:
`Symfony\Component\Translation` and `Symfony\Contracts\Translation` may be named under `App\Ui` and
nowhere else. `domain()` and `application()` already excluded them by omission; the rule closes
`infrastructure()`, which allows all of `Symfony` through `vendors()`. ADR 008 added
`no_security_outside_ui` in exactly the same shape, for `Symfony\Component\Security` and
`Symfony\Bundle\SecurityBundle`: the violation to be afraid of is `Security::getUser()` autowired
into a context's infrastructure service, which compiles, works in a request, and returns null in the
worker and on the console.

## Licence, and the one thing to get right about it

**Two licences, split by kind of work rather than by directory convenience.**

- **`LICENSE`, MIT**, covers everything under `src/`, `config/`, `tests/`, `bin/`, `public/`,
  `templates/` and `translations/`;
- **`docs/LICENSE`, CC BY 4.0**, covers `docs/ADR/`, `docs/storage-map.md` and `docs/images/`.

Copyright (c) 2026 Grégoire Hébert.

The split exists because MIT reads badly over prose: it grants rights to "the Software" and asks for
the notice to be kept in "copies or substantial portions", neither of which maps onto an essay. CC
BY 4.0 is built for prose and asks for the one thing worth asking: attribution.

Two practical consequences when adding files:

- **a new ADR or any other document under `docs/` is CC BY 4.0 by being there.** Do not add an MIT
  header to it, and do not copy documentation prose into a source file's docblock expecting the
  licence to follow it; it does not;
- **do not paste third-party prose or diagrams into `docs/`** without checking their licence first.
  CC BY 4.0 is a licence *you* grant, and you can only grant it over work you hold. The two
  diagrams in `docs/images/` are the author's own.

