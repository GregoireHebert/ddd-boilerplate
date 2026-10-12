# DDD + CQS boilerplate

A starting point for Domain-Driven Design with Command/Query Separation on Symfony 7.4 and
API Platform 4, with Ecotone for the buses, Doctrine ORM 3, PostgreSQL and RabbitMQ.

**It ships no bounded context**, because which contexts exist is the one thing a boilerplate cannot
decide for you. What it ships is everything those contexts would otherwise each reinvent, plus the
arguments for why it is shaped this way.

## What is actually in here

| | |
|---|---|
| `docs/ADR/` | nine decision records, and the reason this repository exists |
| `src/Shared/` | the machinery: identifiers, read vocabulary, validation, failures, security, JSON-RPC, pagination, transactions, the aggregate tracker, the outbox |
| `src/Ui/` | delivery scaffolding: the JSON-RPC processor, console query and write helpers, console security commands, login and logout |
| `.claude/` | ten task-shaped skills and an advisory guard hook, so an agent working here reaches the same decisions the ADRs took |
| `config/` | PHP, never YAML, with every non-obvious option carrying the argument for itself |
| `tests/phpstan/Architecture/` | the phpat rules that make the layering a build failure rather than a convention |

`CLAUDE.md` at the root is the index. Read it first, whether or not you use Claude Code.

## Getting started

```shell
composer install                              # add --ignore-platform-req=ext-amqp if you lack it
docker compose up -d                          # postgres + rabbitmq, management UI on :15672
php bin/console lexik:jwt:generate-keypair    # REQUIRED: no keypair is committed
php bin/console doctrine:database:create
php bin/console doctrine:schema:create --em=default
php bin/console doctrine:schema:create --em=read_model
symfony serve                                 # or: php -S localhost:8000 -t public
```

Then check it is sound, which on an empty tree it already is:

```shell
make phpstan                     # level 6 over src/, phpat included. Expect: no errors.
php bin/console app:messages     # 19 shared failure codes, complete in en and fr
php bin/console app:access       # every operation named a permission or declared itself unguarded

curl -s localhost:8000/rpc -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"rpc.discover","id":1}' | jq .result
```

That last one returns an OpenRPC document with no methods, which is correct: a method is wired by
existing, and there are none yet.

## The decisions, in one line each

Each of these is a document in `docs/ADR/` with the argument, the options that were rejected, a
numbered rule list and a checklist for adding to it.

| | |
|---|---|
| **001** architectural organization | clean architecture, and `Ui/` beside the contexts rather than inside them |
| **002** identifying everything | one `Id` class with a covariant phantom type, never one class per entity |
| **003** store the aggregate | five storage paths, and where Doctrine's ceiling actually is |
| **004** filter | a repository answers by identity, a read model answers by criteria, and there is no shared filter abstraction |
| **005** validation | the line is purity, not layer; a command carries value objects, an event carries primitives |
| **006** REST versus RPC | three tests decide, every write is its own resource, and the generated documents are deliverables |
| **007** translation | a failure carries a declared code and a parameter map, never a sentence |
| **008** security | four questions, four homes; ask for a permission, never for a role |
| **009** one URL, two representations | a URL is not a delivery; `Accept` chooses the representation |

**The ADRs lead, the code follows.** When an ADR moves, the code owes it a commit. When the code
cannot follow, that is a finding worth reopening the ADR over, and ADR 008 rule 19 is an example of
exactly that having happened.

### They argue with examples that are not here

The ADRs were written against a reference application: a `Membership` context and a `Matching` one.
Named classes like `Member`, `BadgeDesign` or `CandidateFeedInterface`, and commands like
`app:paths`, belong to that application rather than to this repository.

That is deliberate. A rule with no example is a rule nobody can check, so the examples stay and
stop being the tree. Every mechanism they lean on is here.

## Starting your first context

`src/<Context>/{Application,Domain,Infrastructure}/`, and then five wiring steps that each fail
quietly rather than loudly if you miss them. `CLAUDE.md`'s "Current state of the tree" has the
table, and `.claude/skills/wiring-a-class/` has the detail:

1. the root namespace in `config/packages/ecotone.php`, or no handler in it is ever found;
2. a Doctrine mapping entry in `config/packages/doctrine.php`, one per namespace prefix;
3. a phpat rule set in `tests/phpstan/Architecture/` plus an entry in `phpstan.dist.neon`;
4. a `FailureCode` enum in the context, and its entries in `translations/`;
5. a `Permission` enum in `Shared/Infrastructure/Security/Permission/`, and a grant in `RoleMap`.

`app:messages` and `app:access` are what tell you when 4 and 5 are incomplete.

## The one thing to change first

`Shared/Domain/Models/PrincipalAggregate` is an empty marker interface. It exists so `Shared` can
hold an `Id<PrincipalAggregate>` without naming a bounded context, which is what keeps the security
vocabulary reusable at all.

**Implement it on whatever your application authenticates as**, a `Member`, a `Player`, a
`Customer`, a `User`, and pass that class to `Actor::subject()`:

```php
final class Customer implements PrincipalAggregate { /* ... */ }

$customerId = $actor->subject(Customer::class);   // an Id<Customer>, narrowed once, at the edge
```

Nothing under `Shared/` changes. ADR 008 rule 19 and ADR 002's covariance section argue for the
whole arrangement.

## Conventions worth knowing before you write anything

- **Symfony config is PHP, not YAML.** `config/reference.php` is the generated array-shape
  reference that types it. The YAML equivalents were deleted on purpose.
- **Domain classes are not services; Application ones are.** Ecotone resolves every handler out of
  the container and throws at compile time when one is missing.
- **A domain class carries no Doctrine attributes.** Mapping is XML under `Infrastructure/`.
- **No repository flushes.** `EcotoneDbalConfiguration` owns the transaction.
- **No em dashes or en dashes in prose.** The guard hook will mention it.

## Licence

MIT.
