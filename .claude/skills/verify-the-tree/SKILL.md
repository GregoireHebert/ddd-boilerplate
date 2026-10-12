---
name: verify-the-tree
description: Check work in this DDD/CQS tree before calling it done - make phpstan at level 6 against its known error baseline, the four app:* walk commands that exercise the storage paths, read models, catalogue and access surface, the OpenAPI and OpenRPC exports, and which failures are deliberate teaching examples rather than regressions. Use after any change to src/, config/ or translations/, and before reporting a change complete.
---

# Verifying a change

**`make phpstan` is clean and there is no error baseline**, so any error is yours. That is the
single most useful fact in this file, and it is a property worth keeping: the moment a baseline
exists, every later reader has to decide which errors were already there.

There are **no test cases** in `tests/`. `vendor/bin/phpunit` reports "No tests executed", which is
the true state of an unstarted boilerplate and not a broken install. `tests/` holds two bootstraps,
two helper traits and the phpat rules.

## The loop

```shell
make phpstan                       # level 6 over src/, needs --memory-limit=1G. Expect: no errors.
php bin/console app:messages       # every declared code vs every enabled locale (ADR 007)
php bin/console app:access         # every operation and what guards it (ADR 008)
php bin/console api:openapi:export # the document is a deliverable (ADR 006)
```

Run the ones your change touches, not all of them reflexively:

| you changed                                                | run                                      |
|------------------------------------------------------------|------------------------------------------|
| anything in `src/`                                         | `make phpstan`                           |
| a layer, a context, an import across rings                 | `make phpstan` (phpat runs inside it)    |
| an exception, a failure code, a catalogue entry            | `app:messages`                           |
| a permission, a `#[Guarded]`, a `#[Unguarded]`, a firewall | `app:access`                             |
| a resource, an operation, a `Param`, a provider            | `api:openapi:export`, and `rpc.discover` |
| a template, an `outputFormats:`, a `Ui/Web` controller     | `app:access`, and curl with `Accept:`    |

**Both walk commands pass on an empty tree and that is not a free pass.** `app:messages` reports the
19 shared codes complete in `en` and `fr`; `app:access` reports that every operation names a
permission or declares itself unguarded, of which there are none yet. They prove nothing is
*missing*, never that anything is right, and they start doing real work the first time you add a
context.

## The one thing that is deliberately silenced

`phpstan.dist.neon` carries exactly one `ignoreErrors` entry, for `trait.unused` on
`Ui/Console/Write/RenderViolations`. It is a trait with no user until you write your first console
write command, and the comment beside it says to delete the entry at that point. **If you find
yourself adding a second entry, read the error twice first**: a baseline that grows is a baseline.

## Before analysis will run at all

PHPStan boots the container, so two things have to be true:

- **the JWT keypair exists.** `config/jwt/*.pem` is not committed. Without it the security services
  refuse to build and the analysis reports an internal error naming the missing file. Run
  `php bin/console lexik:jwt:generate-keypair`;
- **the container is warm.** `symfony.containerXmlPath` points at
  `var/cache/dev/App_Shared_Infrastructure_Symfony_KernelDevDebugContainer.xml`. If analysis fails
  to boot, `php bin/console cache:warmup` first.

`banned_code` is on: no `dump`, `dd`, `var_dump`, `print_r`, `echo`, `print`, `exit`, `eval`,
`assert`, `shell_exec`, backticks. Do not leave a debug call in.

## There are no deliberate failures here

The reference application keeps several on purpose, to demonstrate limits: an `Id<A>` mismatch that
only PHPStan can see, a predicate duplicated against ADR 005's one-home rule, a message built with
`ucfirst` against ADR 007. **None of them are in this repository**, so nothing here is "expected to
fail" and you should not go looking for a list to compare against.

## Running the app

```shell
composer install                     # add --ignore-platform-req=ext-amqp without the extension
docker compose up -d                 # postgres + rabbitmq, management UI on :15672
php bin/console lexik:jwt:generate-keypair   # REQUIRED first run, nothing is committed
php bin/console doctrine:database:create
php bin/console doctrine:schema:create --em=default
php bin/console doctrine:schema:create --em=read_model
symfony serve                        # or: php -S localhost:8000 -t public
```

**`doctrine:schema:update` is not usable**, and the two `--em` above are the
same reason: two entity managers share one connection, and `schema:update`
diffs one manager's metadata against the whole database, so each one drops the
other's tables. `schema:create` only ever creates.

`.env` ships a SQLite DSN while Doctrine and compose target PostgreSQL; override
it locally for the real platform, because `IdType`'s column type and
`identity_generation_preferences` differ per platform.

## Poking the surfaces

Only one surface answers on an empty tree, and it is the one worth checking first because it proves
the whole JSON-RPC protocol is wired:

```shell
curl -s localhost:8000/rpc -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"rpc.discover","id":1}' | jq .result
```

That returns an OpenRPC document with no methods in it, which is correct: `MethodRegistry` is tagged
by interface, so a method is wired by existing and there are none yet.

Once you have an endpoint, the check that matters is the cross-delivery one, because it is the thing
ADR 007 exists to make true and the thing that silently stops being true:

```shell
# same mistake, three deliveries, one sentence in one language
curl -s -i localhost:8000/<your-collection>/banana -H 'Accept-Language: fr' | grep -i '^content-language'
curl -s localhost:8000/<your-write> -X POST -H 'Content-Type: application/ld+json' \
  -H 'Accept: application/problem+json' -H 'Accept-Language: fr' -d '{...}' | jq '.violations'
php bin/console <your:list> --page=0; echo $?   # expect 22
```

**A parse failure must be a `400` over HTTP and exit code 22 on the console**, and the two must say
the same thing in the same language.

## If you wrote a test

There is no precedent to copy, so use the two traits that exist:
`Tests\Functional\AsyncMessageChannelTrait::consumeAsyncMessages()` drains a
channel through the real consumer on the application container (same handlers,
interceptors, one transaction per message), and
`Tests\Functional\EcotoneEventDispatcherTrait` gives `sendCommand`, `sendQuery`
and `publishEvent` through Ecotone's `FlowTestSupport`, where `#[Asynchronous]`
collapses to inline execution.

PHPUnit runs with `failOnDeprecation`, `failOnNotice` and `failOnWarning`.
