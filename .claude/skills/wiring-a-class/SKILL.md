---
name: wiring-a-class
description: Make the container, Ecotone and the static analysers actually see a new class, bounded context, message handler, channel, Doctrine type or architecture rule in this DDD/CQS tree - config/services.php autoregistration and its deliberate exclusions, the Ecotone namespace scan, the phpat rule sets, and the PHP-not-YAML config style. Use when a service is not found, an Ecotone handler throws at container compile time, a phpat rule goes unchecked, or a whole new context or layer is added.
---

# Wiring a class

Two independent discovery mechanisms coexist here, and which one you need
depends on what you added.

## Symfony's container: `config/services.php`

Autoregisters `../src/**/{Api,Application,Console,Infrastructure}/` with these
exclusions, each of which is a decision rather than an oversight:

| Excluded                                                                                                                          | Why                                                                                                |
|-----------------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------|
| `src/**/Application/{Command,Query,Event}/`                                                                                       | serialized payloads, not services                                                                  |
| `src/Ui/Api/Resources/`                                                                                                           | metadata read by `resource_class_directories`, never constructed                                   |
| `src/Shared/Infrastructure/{ApiPlatform,Translation}/`                                                                            | they carry the **vendor's** namespace, so the `App\**` glob would derive a class that is not there |
| all of `src/Shared/Application/`                                                                                                  | ADR 004's read vocabulary and ADR 005's `Validation/` are values built with `new` at an edge       |
| `Shared/Infrastructure/Security/{Actor,AccessToken,SecurityUser,Credential,RevokedToken,Guarded,Unguarded}.php` and `Permission/` | ADR 008's values, two Doctrine entities hydrated by reflection, two attributes and the enums       |
| `Kernel.php`                                                                                                                      |                                                                                                    |

**The `Gate`, `PermissionVoter`, `RoleMap`, `Credentials`, `CredentialProvider`,
`AccessTokens`, `RevokedTokens`, `JwtTokenHandler` and both `CurrentActor`
implementations in that same directory *are* services and stay registered.** The
split is listed rather than left to luck, because "this directory is values" is
wrong about half of it.

**Two extra PSR-4 roots** exist for the vendor-namespaced adapters and they are
registered under the prefix they actually carry:

| prefix          | resource                                  | excluded                                       |
|-----------------|-------------------------------------------|------------------------------------------------|
| `ApiPlatform\`  | `src/Shared/Infrastructure/ApiPlatform/`  | `JsonRpc/JsonRpcResource.php`, being metadata  |
| `Translation\`  | `src/Shared/Infrastructure/Translation/`  | nothing                                        |

> **Domain classes are not Symfony services; Application ones are.** Not a style
> choice: Ecotone's Symfony bundle resolves every `#[CommandHandler]`,
> `#[QueryHandler]` and `#[EventHandler]` class out of the container and throws
> at **compile time** when one is missing (`RequiredReferencesCompilerPass`).
> Nothing in `Domain/` is ever constructed by the container.

`Api` is autoregistered alongside the others because a state provider calls the
query bus, so it is a delivery handler rather than framework plumbing. `Ui/Web`
comes in the same way, and an ADR 009 controller is a service like any other.

**If you add a value type that travels inside a message**, it needs an exclusion
here or the container tries to autowire it. The same goes for an attribute, a
Doctrine entity with a private constructor, and anything else built by
reflection rather than by the container.

## Ecotone: `config/packages/ecotone.php`

Scans `namespaces` for Ecotone attributes. Currently `App\Shared\Infrastructure`,
`App\Membership`, `App\Matching`, `App\Ui`.

> **Adding a bounded context means adding its root namespace here.** A handler
> in an unscanned namespace is simply never found.

`loadSrcNamespaces` is off because Ecotone's PSR-4 loader chokes on function
files loaded through composer's `autoload.files`. ADR 005 deleted the only two
such files, so **the flag now guards nothing**; the container compiles with it
on, but flipping it deserves its own commit and a look at `ecotone:list`.

## phpat: `tests/phpstan/Architecture/`

These are a **PHPStan extension, not PHPUnit**: registered as `services` with a
`phpat.test` tag in `phpstan.dist.neon`, run by `make phpstan`.

> **New context or new layer means a test class in that directory and an entry
> in `phpstan.dist.neon`'s `services` block, or the namespace goes unchecked.**

`ArchitectureTestCase` holds the allow-lists. Each ring adds to the one inside
it:

- `miscellaneous()` - allowed even in a Domain: `Symfony\Component\Uid`
  (backs `Id`), `Psr\Container`, `Psr\Clock`, `Doctrine\Common\Collections`
  (an interface package, for a child collection), `Webmozart\Assert`.
- `domain()` - the above plus `Ecotone\Modelling\Attribute`.
- `application()` - plus `Ecotone\Modelling`, `Ecotone\Messaging\Attribute`,
  `Symfony\Component\Validator`.
- `infrastructure()` - plus every vendor.

Each context's `Domain` may depend only on itself, `Shared\Domain` and that
allow-list. `Shared` may never depend on a context or on `Ui`. `Matching\Domain`
is the one context allowed to depend on another context's `Domain` (`Membership`,
because a `Pair` or a `Like` names a `Member`). `Ui` is the only namespace
allowed to depend on every context, **and it still may not name any context's
`Infrastructure`.**

### Three deliberate widenings, each argued in its own docblock

`Psr\Clock` in a Domain; `Ecotone\Modelling\Attribute` in a Domain for the
single ADR 003 option A aggregate (`Membership\Domain\Model\Subscription`, whose
commands therefore live in `Membership/Domain/Command/`); and `Ui` naming each
context's `Application`, because expressing an intention means putting a command
object on the bus.

### Three deliberate narrowings, the only rules that moved inward

- ADR 005 took `Symfony\Component\Validator` out of `miscellaneous()` into
  `application()`. A domain exception says what is wrong; turning it into a
  `ConstraintViolation` is the edge's job.
- ADR 007 added `no_translator_outside_ui` to the three context rule sets.
  `domain()` and `application()` already excluded translation by omission; the
  rule closes `infrastructure()`, which allows all of `Symfony` through
  `vendors()`.
- ADR 008 added `no_security_outside_ui` in exactly the same shape. The
  violation to be afraid of is `Security::getUser()` autowired into a context's
  infrastructure service: it compiles, works in a request, and returns null in
  the worker and on the console.

`phpat.ignore_built_in_classes: true` keeps PHP's own `Override`, `UnitEnum` and
SPL exceptions out of the vendor graph.

## Config style

> **Symfony config is PHP, not YAML.** Every `config/**.php` returns
> `App::config([...])` (or `Routes::config([...])`) in the
> `Symfony\Component\DependencyInjection\Loader\Configurator` namespace, and
> `config/reference.php` is the generated array-shape reference that types those
> arrays. The YAML equivalents were deleted; do not reintroduce them.

Note one consequence you will meet: **a config parameter cannot hold an object**,
which is why API Platform's `openapi.tags` option is unusable here (the
extension builds `Tag` objects and the container refuses to dump them) and why
tag descriptions are a factory decorator instead.

## Message channels and transports

`Shared/Infrastructure/Ecotone/EcotoneDbalConfiguration` is the source of truth
for transactions: a DB transaction plus ObjectManager clear/flush per async
message and on the command bus, deduplication for RabbitMQ at-least-once
redelivery, and **no** Ecotone dead-letter or consumer-position tracking (retry
and DLQ stay with Messenger's `failed` transport).

**Tests must never publish to RabbitMQ** - the queues are shared with the dev
worker, which would replay test messages against the dev database. Two layers
enforce it and **both must stay in place when you add a channel or a
transport**: `MessageChannelBuilderFactory` swaps Ecotone channels for in-memory
queue channels under `APP_ENV=test`, and `config/packages/messenger.php` points
the Messenger transports at `in-memory://`.

A channel that serializes needs a **converter**, not a primitive command
(ADR 005 rule 14). Put it in `Shared/Infrastructure/Ecotone` next to the channel
that needs it.

## Known wiring breakage, not your regression

- **`#[Asynchronous]` cannot dispatch in dev or prod as installed.**
  `config/packages/messenger.php` points the `async` transport at the AMQP DSN
  and `symfony/amqp-messenger` is not in `composer.json`, so the first dispatch
  dies with "No transport supports Messenger DSN". Under `APP_ENV=test` it works
  because the channel is in-memory. Fix when the async story matters:
  `composer require symfony/amqp-messenger` plus `ext-amqp`, and
  `docker compose up -d`.
- **`CloseOneExpiredSubscription` crosses the `async` channel carrying an `Id`
  and a `\DateTimeImmutable` with no converter.** The in-memory channel passes
  objects by reference, so the test environment proves nothing.
- **`Shared/Infrastructure/Security/` names `App\Membership\Domain\Model\Member`**,
  because a principal is typed `Id<Member>`. `Shared` may never depend on a
  context, and phpat says so in 16 of the 27 PHPStan errors. It owes ADR 008 an
  argument before it owes the code a fix.

Ask before "fixing" any of these: they may be the next teaching step.
