---
name: storing-an-aggregate
description: Decide how an aggregate, entity, value object or table is persisted in this DDD/CQS tree, following ADR 003's five storage paths and docs/storage-map.md - direct Doctrine mapping, XML plus custom types, a second entity manager, a reader/writer pair with our own tracker, or the outbox and saga. Use before adding any persistence, repository, Doctrine mapping, entity manager, migration or gateway, and before deciding where a new feature's data lives.
---

# Storing an aggregate

The argument is `docs/ADR/003_store-the-aggregate.md`. Where each answer already
lives is `docs/storage-map.md`. **Open the map before copying whichever neighbour
you landed on: the neighbours deliberately differ.**

`php bin/console app:paths` walks all five end to end and is usually faster than
reading. It is a demo, not a test, and asserts nothing.

## The question this answers

Not "can Doctrine store this" (it can store anything) but **at what point does
bending Doctrine to the aggregate's shape cost more than not using it**. The
ceiling is not a mapping ceiling, it is a *scope* ceiling: ORM-manageable
relational storage against everything else.

## Pick a path, in this order. Stop at the first that fits.

| #  | When                                                                 | What it is                                                      | Lives in                                                            |
|----|----------------------------------------------------------------------|-----------------------------------------------------------------|---------------------------------------------------------------------|
| 1  | one table whose shape matches the aggregate                          | direct XML mapping, nothing else                                | `Matching`: `Like`, `Block`                                         |
| 2  | the shape diverges, or there are value objects or a child collection | XML plus custom types plus an embeddable                        | `Membership`: `Member`, `Badge`; `Matching`: `Pair`                    |
| 3  | the mapping *itself* needs isolating, or it is a projection          | a second `EntityManager` on the **same** connection             | the discovery feed; `ProfileJournal` uses the shadow-entity variant |
| 4  | the data genuinely does not all live in one database                 | an `AggregateReader` + `AggregateWriter` per class, our tracker | Membership Plan, badge catalogue, reputation                               |
| 5  | one change has to reach two stores                                   | outbox (eventually) or saga (in order)                          | pair notifications; member erasure                                  |

**Path 2 absorbs almost everything that feels like it does not.** Reach past it
only for the reason in the table, not because the mapping felt long.

## The rules that break things when ignored

- **One aggregate, one owner.** Either Doctrine's `UnitOfWork` tracks it end to
  end or the path 4 tracker does. Never both. Two change trackers over one
  object is how one mutation becomes two writes, in an order nobody chose.
- **The Application layer asks the same question regardless.** Every path ends
  at an interface in `Domain/Repository/`, and no handler can tell which
  implementation it got. If a handler can tell, the seam is in the wrong place.
- **No context reads or writes another context's tables.** Events in,
  projections local. Erasure fans out through `MemberErased`, never a `DELETE`.
- **Nothing pretends to be atomic that is not.** A writer touching two stores
  says so in its docblock. A saga that cannot compensate says where the point of
  no return falls (`ErasureStep::pastPointOfNoReturn()`).
- **No repository flushes.** `Shared/Infrastructure/Ecotone/EcotoneDbalConfiguration`
  already opens a transaction and flushes per async message and on the command
  bus. The single exception is `DoctrineMemberErasureRepository`, and its
  docblock says why.
- **A domain class carries no Doctrine attributes.** Mapping is XML under the
  context's `Infrastructure/Persistence/Doctrine/ORM/Mapping/`, which is what
  keeps the phpat domain rules intact.

## Per path, what to actually write

**Path 1.** An `.orm.xml` that is a column list, plus a repository with `get()`
and `add()`. A *backed* enum needs no mapping code; declare one pure and it
becomes path 2 (`PureEnumType`). Open `Model.Like.orm.xml`.

**Path 2.** Same, plus a custom `Type` per coercion and an `<embedded>` per
embeddable. A renamed column is a `column` attribute, not a reason for a shadow
entity. The child collection is the part worth the ORM: working out which rows
are new, gone or changed and ordering the statements is the one job to keep it
for. Open `Model.Member.orm.xml`, then `MatchingPreferencesType.php`.

**Path 3, shadow entity.** Only when the aggregate has no identifier of its own
or its state is a fold (`ProfileJournal`). It costs a row class, a mapper and
replication on every call. Read `ProfileJournalMapper.php` as the argument for
paths 1 and 2 rather than as a template.

**Path 3, second entity manager.** Configure it in the `entity_managers` block
of `config/packages/doctrine.php` **sharing `connection: default`**, so the
second flush nests inside the first transaction through savepoints. Two managers
on two connections cannot commit together. Note the limit before you rely on it:
**DQL does not join across managers even on one connection**, so a `NOT EXISTS`
against the other manager's table is not available; project what you need.

**Path 4.** Start from the reader, not from a Unit of Work. A reader wants rows,
so prefer DBAL over a `ServiceEntityRepository` or the `EntityManager` (both
need something managed, which means a shadow entity and a mapper again). Keep an
XML mapping purely as schema metadata so `schema:create` and migrations still
know the table exists. Hydrate through an explicit named constructor whose
signature documents that it skips validation, never through the invariant-
enforcing one and never quietly through reflection. The writer takes a
*changeset*, which is what makes a partial write possible:
`Connection::update($table, $changed, $id)` and `PATCH /x/{id}` take the same
array. Open `SubscriptionWriter.php`.

Two options exist here and both ship. **Option A** puts `#[CommandHandler]` on
the aggregate and lets Ecotone drive load/call/save; used once, for
`Subscription`, which is why the phpat domain allow-list carries
`Ecotone\Modelling\Attribute` and why that aggregate's commands live in
`Membership/Domain/Command/`. **Option B**, everything else, keeps the handler in
`Application` and relies on `SaveTrackedAggregatesInterceptor`. **That
interceptor's pointcut is the bus, not `CommandHandler::class`** - the latter
wraps only Ecotone's CallAggregate step and silently never writes.

**Path 5.** Outbox for "this second thing also has to happen": a row written in
the same transaction, a relay afterwards, at-least-once *with a record*. Saga
for "these steps, in this order, across four stores": one message per step, one
transaction per message, persisted progress. Never a `flush()` across stores.
Remember the half people forget: every projection a filter cost has to be **torn
down** here too. Open `ErasureService::advance()` and read the `catch`.

## Commands

```shell
php bin/console app:paths                                  # walk all five
php bin/console app:paths --keep                           # leave the data behind
php bin/console doctrine:schema:create --em=default
php bin/console doctrine:schema:create --em=read_model
```

**`doctrine:schema:update` is unusable here.** Two managers share one connection
and it diffs one manager's metadata against the whole database, so each one
drops the other's tables. `schema:create` only ever creates.

`.env` ships a SQLite DSN while Doctrine and compose target PostgreSQL. Override
it locally for the real platform: `IdType`'s column type and
`identity_generation_preferences` differ per platform.

## Before you finish

Reading is a different document: a repository answers by identity, a read model
answers by criteria, and every list goes through `Application/ReadModel/`. See
the `reading-and-filtering` skill and ADR 004 rule 1.

If you added a path or changed which one a feature uses, `docs/storage-map.md`
owes a line. The ADRs lead and the code follows; when the code cannot follow,
that is a finding worth reopening ADR 003 over.
