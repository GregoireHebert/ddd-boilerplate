# Where each storage path lives

[ADR 003](ADR/003_store-the-aggregate.md) asks how far Doctrine's mapping should be pushed before
storing an aggregate some other way, and ends on five answers. This file says which feature in this
codebase implements each of them, and which file to open first.

Read the ADR for *why*. Read this for *where*.

If you would rather watch it than read it:

```shell
bin/console app:paths          # walks every path once and reports what each one did
bin/console app:paths --keep   # same, but leaves the data behind to poke at
bin/console app:reads          # the same five paths from the read side (ADR 004)
```

That command goes through the command and query buses, exactly as the API layer would. It never
names a repository, an entity manager or a gateway, which is the claim the whole ADR is making:
from up there, the five paths are indistinguishable.

## The map

```
                             where does the aggregate live?
                                          │
        ┌─────────────────────────────────┴──────────────────────────────┐
        │                                                                │
  in one database                                                 in many places
        │                                                                │
   shape matches ──── no ──── shape diverges ──── mapping needs      reader + writer
   the table                  from the table      isolating          per class,
        │                          │                   │             our tracker
        ▼                          ▼                   ▼                    │
     PATH 1                     PATH 2              PATH 3               PATH 4
   Like, Block             Member, Pair, Badge     discovery feed    Membership Plan, catalogue,
                                                                        reputation
        │                          │                   │                    │
        └──────────────────────────┴───────────────────┘                    │
                          │                                                 │
              Doctrine's UnitOfWork does it all               saved at the message
                                                              boundary, by an interceptor
                                                                            │
                                                         does the write span two
                                                         stores at once?  ──┘
                                                                  │
                                                                  ▼
                                                               PATH 5
                                                     outbox, and the erasure saga
```

## Path 1, direct mapping

**Feature: casting a like, and blocking a member.**

Flat aggregates. Identifiers, a timestamp, a backed enum, and nothing else. The only non-built-in in
either mapping is `Id`, registered once for the whole project, so the per-aggregate mapping cost is
zero. This is the baseline.

|              |                                                                                           |
|--------------|-------------------------------------------------------------------------------------------|
| Aggregates   | `src/Matching/Domain/Model/{Like,Block}.php`                                              |
| Mapping      | `src/Matching/Infrastructure/Persistence/Doctrine/ORM/Mapping/Model.{Like,Block}.orm.xml` |
| Repositories | `src/Matching/Infrastructure/Persistence/Repository/Doctrine{Like,Block}Repository.php`   |
| Handlers     | `src/Matching/Application/LikeService.php`                                                |
| Reading      | `src/Matching/Infrastructure/ReadModel/DoctrineLikeLedger.php` (ADR 004)                 |

The pair two reciprocal likes form is path 2, below. `LikeService` only notices the reciprocity and
publishes `LikeReciprocated`; `PairService` reacts to it. Worth following once, because it is the
only place in the project where one command's writes land through two handlers, and
`PairService::onLikeReciprocated()`'s docblock explains why that is still one transaction and what
breaks it.

**Open first:** `Model.Like.orm.xml`. It is a column list, and that is the whole point.

Two things to notice. The mapping is XML rather than attributes because we chose to
forbid `Doctrine\ORM\Mapping` inside a Domain. And `BlockReason` is a *backed* enum, which is why it needs no mapping
code; declare an enum pure, as `PairStatus` is, and it moves to path 2.

### The same path, one directory outside every context

**Feature: signing in.**

ADR 008's two security tables are path 1 as well, and they are the only mappings in the tree whose
prefix is not a bounded context's. A credential has no business behaviour, no invariant and no
language, so a context for it would be a context with one entity and nothing to decide.

There are two tables and not one, which is [ADR 008](ADR/008_security.md) rule 18 as
[ADR 009](ADR/009_one-url-two-representations.md) appended it: **a credential is identified by
something a human typed, a session is stored nowhere of ours, and a revocable token is a JWT whose
`jti` is the row.** The first implementation made the credential row's primary key the bearer token
itself, which stored the token in clear text and made revoking a token destroy the password login
sharing its row. So the token left the credential, the credential's identifier went back to being an
ordinary surrogate, and what a revocation holds is a `jti` and an instant.

`ui_credential` is written once and read by identity twice: by `username`, which is what a sign-in
form asks for, and by `member_id`, which is what resolving a token's `sub` claim asks for.
`ui_revoked_token` is read by primary key on every request that presents a token.

|            |                                                                           |
|------------|---------------------------------------------------------------------------|
| Entities   | `src/Shared/Infrastructure/Security/Credential.php`, `.../RevokedToken.php`    |
| Mapping    | `.../Security/Mapping/{Credential,RevokedToken}.orm.xml` (default EM)     |
| Repository | `.../Security/Credentials.php`, `.../Security/RevokedTokens.php`           |
| Writers    | `app:credentials:issue`, `app:tokens:revoke`. Nothing else writes either   |
| Readers    | `CredentialProvider` and `ActorTokens` (credentials), `JwtTokenHandler` (revocations) |
| Reading    | none. A repository answers by identity and nothing lists either table      |

A session is in neither table, on purpose: Symfony manages a session, expires it and
garbage-collects it, so a row beside it would be a second lifetime to keep in step with the first.

The trigger that would move it is ADR 008's and it is written down so it is recognised later: when
credentials grow invariants (lockout after N failures, password history, MFA enrolment, recovery
codes) they have a state machine and a language, and at that point they are an `Identity` context and
the table moves with them. Not before.

## Path 2, XML mapping with custom types and an embeddable

**Feature: a member's profile, their badge collection, and the pair two reciprocal likes form.**

`Member` diverges from its table on every single field, and one class still represents them:

| field         | storage                                                     |
|---------------|-------------------------------------------------------------|
| `handle`    | a value object wrapping a string, through a custom type     |
| `status`      | a *pure* enum with no backing value, through `PureEnumType` |
| `preferences` | two lists and a flag, collapsed into one json column        |
| `badgeDesign` | two enums, spread over two columns by an embeddable         |
| `badges`      | a child collection, cascade-persist and orphan-removal      |

No shadow entity, no mapper, it is still Doctrine's `UnitOfWork`.

|               |                                                                                                                                  |
|---------------|----------------------------------------------------------------------------------------------------------------------------------|
| Aggregates    | `src/Membership/Domain/Model/{Member,Badge}.php`, `src/Matching/Domain/Model/Pair.php`                                              |
| Value objects | `src/Membership/Domain/ValueObject/{Handle,BadgeDesign,MatchingPreferences}.php`                                                 |
| Custom types  | `src/Membership/Infrastructure/Persistence/Doctrine/Type/`, `src/Shared/Infrastructure/Persistence/Doctrine/Type/PureEnumType.php` |
| Mapping       | `src/Membership/Infrastructure/Persistence/Doctrine/ORM/Mapping/`                                                                  |
| Handlers      | `src/Membership/Application/{MemberService,BadgeCollectionService}.php`                                                                     |
| Reading       | `src/Membership/Infrastructure/ReadModel/DoctrineMemberDirectory.php` (ADR 004, and the option B declaration next to its port)     |

**Open first:** `Model.Member.orm.xml`, then `MatchingPreferencesType.php`.

The badge collection is the part of path 2 worth spending time on. Working out which rows in a collection are new,
gone or changed, and emitting the INSERT, UPDATE and DELETE in an order that does not violate a
foreign key, is the one job ADR 003 says to keep the ORM for. `Member::discardBadge()` is one line and
orphan-removal turns it into the DELETE.

## Spectrum stage 3, a shadow entity and a "handwritten" mapper

**Feature: profile history, and "put my profile back the way it was on Tuesday".**

I would try to find a reason not to use this approach, first because it is just extra code for the same result, and fewer automatics.
Also, I came into conclusion that path 2 can absorb almost everything, and we can rarely say "the mapping got too complex". 
It is showcased here because I think seeing it is more convincing than just reading about it.

`ProfileJournal` has no identifier of its own (it is keyed by the member), it is append-only, and its
state is a fold. So it gets a row class shaped by the table, and a mapper.

|               |                                                                                            |
|---------------|--------------------------------------------------------------------------------------------|
| Aggregate     | `src/Membership/Domain/Model/ProfileJournal.php`                                             |
| Shadow entity | `src/Membership/Infrastructure/Persistence/Doctrine/ORM/Entity/MemberProfileRevisionRow.php` |
| Mapper        | `src/Membership/Infrastructure/Persistence/Mapper/ProfileJournalMapper.php`                  |
| Handlers      | `src/Membership/Application/{ProfileJournalRecorder,ProfileHistoryService}.php`              |
| Reading       | `src/Membership/Infrastructure/ReadModel/DoctrineProfileHistory.php`, which never calls the mapper (ADR 004) |

**Open first:** `ProfileJournalMapper.php`, and read it as the argument for paths 1 and 2 rather than
as a template. Then `ProfileHistoryService::revert()`, where the asymmetry shows up in one
method: the `Member` needs no save because it is managed, while the journal has to be handed back.

## Path 3, a second `EntityManager`

**Feature: the discovery feed, "who could I show next".**

Per ADR 001, a context never reads another context's tables; it subscribes to events and keeps a
local projection. Matching's projection lives on its own entity manager, over the *same* DBAL
connection.

Sharing the connection is the point I want to showcase: two managers on two connections cannot
commit together. On one connection the second flush nests inside the first transaction through
savepoints, so a feed update and a `Pair` insert either both succeed or neither does.

|               |                                                                                    |
|---------------|------------------------------------------------------------------------------------|
| Configuration | `config/packages/doctrine.php`, the `entity_managers` block                        |
| Projection    | `src/Matching/Infrastructure/ReadModel/{MemberFeedEntry,MemberFeedProjection}.php` |
| Read port     | `src/Matching/Application/ReadModel/CandidateFeedInterface.php`                    |
| Handlers      | `src/Matching/Application/DiscoveryService.php`                                    |
| Reading       | `src/Matching/Infrastructure/ReadModel/{DoctrineCandidateFeed,SentLikeProjection}.php` (ADR 004) |

**Open first:** the `entity_managers` block in `config/packages/doctrine.php`, then
`DoctrineCandidateFeed.php`, which put the following case in evidence: "candidates I have not already liked" could be one
`NOT EXISTS` if DQL could join across managers. But it cannot, even on the same connection.

> **`doctrine:schema:update` is not usable with two managers.** It diffs one manager's metadata
> against the whole database, so each manager drops the other's tables. Use
> `doctrine:schema:create --em=default` and `--em=read_model`, and migrations everywhere.

## Path 4, a reader and a writer per aggregate

**Features: the Membership Plan subscription, the badge catalogue, and reputation.**

Three aggregates whose data does not all live in one database, and three different shapes of that
problem:

|                     | Membership Plan                        | catalogue model                    | reputation                    |
|---------------------|----------------------------------|------------------------------------|-------------------------------|
| where the facts are | billing API + one local row      | catalogue API + one *optional* row | two APIs, no table            |
| who leads the read  | the local row                    | the catalogue                      | neither, both are asked       |
| what the write does | PATCH split from UPDATE          | POST a correction, upsert curation | POST to one, never the other  |
| which option        | **A**, handlers on the aggregate | **B**, handlers in Application     | **B**                         |

The shared machinery is four capabilities that ADR 003 takes a Unit of Work apart into, of which it
keeps two and a half: an identity map, which aggregates were touched, field-level changesets, and
*not* cross-store atomicity.

|                                  |                                                                                      |
|----------------------------------|--------------------------------------------------------------------------------------|
| Machinery                        | `src/Shared/Infrastructure/Persistence/Aggregate/`                                   |
| Saving                           | `src/Shared/Infrastructure/Ecotone/Interceptor/SaveTrackedAggregatesInterceptor.php` |
| Option A wiring                  | `src/Shared/Infrastructure/Ecotone/Repository/TrackedAggregateRepository.php`        |
| Readers and writers              | `src/Membership/Infrastructure/Persistence/{Subscription,BadgeModel,Reputation}/`       |
| The outside world                | `src/Membership/Infrastructure/{Billing,Catalogue,Moderation,Identity}/`               |
| The provider's own store         | `var/billing.db`, its own SQLite file, created and owned by `PretendBillingGateway`  |
| Schema for tables no entity owns | `src/Membership/Infrastructure/Persistence/Schema/ProviderOwnedTablesListener.php`     |
| Reading                          | `src/Membership/Infrastructure/ReadModel/Doctrine{Subscription,BadgeModel}{Projector,Directory}.php` |

**Where the provider keeps its half, in dev and test.** `PretendBillingGateway` holds its
subscriptions in `var/billing.db`, a separate SQLite file with its own connection, built inside the
class and never handed a transaction. It is a separate file and not a second connection to
`var/data.db` for a measured reason: a SQLite write lock covers the whole file, so a second
connection writing while our command's transaction is open blocks for sixty seconds and then answers
`database is locked`. And it is not the injected `Doctrine\DBAL\Connection` for a worse reason: that
one joins our transaction, so a rollback after `open()` would erase the provider's state too and the
partial write this path exists to show would stop happening. Nothing of ours creates, migrates or
drops that table; the gateway issues `CREATE TABLE IF NOT EXISTS` on first use, because a real
provider does not ship us its DDL. Nothing of ours deletes from it either, so a cancelled membership
leaves a row there forever, exactly as it would at a real provider; `rm var/billing.db` is the reset.

**Reading is where path 4 costs the most, and ADR 004 is the document for it.** No gateway here has a
list verb, so there is no query that could produce "the subscriptions expiring this month" or "the
green argyle models, page 2", at any speed. The answer is a projection, fed one provider call at a
time by a scheduled command, and a list query that never touches the provider at all. There are two
of them now, `membership_subscription_feed` fed by `app:subscriptions:project` and
`membership_badge_model_feed` fed by `app:catalogue:project`, and the pair is worth reading together
because they differ in the one place that matters.

**Who knows which aggregates exist.** A subscription exists because we opened one, so
`membership_subscription` lists them and `DoctrineSubscriptionProjector` reads identifiers out of our
own table. A badge model exists because a manufacturer made a badge, and `membership_badge_model_curation`
holds a row only for the few we have disagreed with, so the set itself is the provider's:
`DoctrineBadgeModelProjector` walks `CatalogueGateway::references()`, which answers identifiers in the
provider's order with no filter, no sort, no page and no total. That verb is a **feeding** concern
living next to the gateway, which is ADR 004's own phrase for it, and it is not the list verb the
catalogue does not have: no screen can be built from it. Everything else in the table above is about
writing; these two files are what make the aggregates answerable.

**Open first:** `SubscriptionWriter.php`. It is the most representative of the PATH 4: one changeset, two
stores, one partial write each. Muting a reminder issues no HTTP call at all; changing a plan issues
no UPDATE.

Then put `ReputationService` next to `MemberService` and cover the class names. Nothing in either
says whether the aggregate came from a table, from two HTTP calls, or from both. That is the goal.

**A note on the two options.** Option A puts `#[CommandHandler]` on the aggregate and lets Ecotone
drive `LoadAggregate → CallAggregate → SaveAggregate`. this approach is used once, for `Subscription`,
so the alternative stays visible by contrast, and it means two things: the phpat
Domain allow-list gains `Ecotone\Modelling\Attribute`, and that aggregate's commands had to move into
`Membership/Domain/Command/` (an aggregate whose handlers reference Application commands would reverse
the dependency arrow). Everything else in the project uses option B.

**A note on the gateways.** Each of the gateways exists twice. `Http*Gateway` is the real adapter over
a scoped client from `config/packages/http_client.php`; `InMemory*Gateway` is what dev and test
uses, and the container cannot tell them apart beyond a `#[When]` and an `#[AsAlias]`.

The in-memory ones are not stubs. Only the *transport* is faked: they are still considered as separate stores
outside every transaction, they still enforce `If-Match`, and they still assign values we did not
send, which are the three properties the exercise turns on. What they cannot fake is latency,
which is worth remembering when reading a reader: in dev a remote read is free, in production it
is a round trip inside a command.

Open `HttpBillingGateway` so you can compare where the ETag comes from, which 404s are states rather than failures, 
and which status codes mean something a domain exception should carry.

## Path 5, outbox and saga

**Features: telling two members a pair formed, and erasing a member.**

The rule is that there is no such thing as a `flush()` across stores. Let's showcase two features, because there are
two answers depending on whether the second store has to be reached *eventually* or *in order*.

**The outbox** is for "this second thing also has to happen". A command that inserts a `Pair` writes two rows
saying notifications are owed, in the same transaction, and a relay turns them into calls afterward.
What the outbox gives us is not the absence of failure, it is: it will be done at-least-once *with a record*.

|           |                                                                          |
|-----------|--------------------------------------------------------------------------|
| Machinery | `src/Shared/Infrastructure/Persistence/Outbox/`                          |
| Relay     | `bin/console app:outbox:relay` → `src/Ui/Console/OutboxRelayCommand.php` |
| Feature   | `src/Matching/Infrastructure/Notification/`                              |

*Why not using Ecotone outbox feature?* It has one, and in production you should probably prefer it. 
`DbalBackedMessageChannelBuilder` makes an async channel a database table, so the message commits with the write; 
keeping RabbitMQ and putting an outbox in front of it is `OutboxForwardingMessageChannel`, which needs an Enterprise license. 
Note also that the destination is an HTTP provider rather than another handler; 
Ecotone's outbox answers "get this onto the broker atomically", ours answers "call this third party eventually, with a record".

**The saga** is for "these four things have to happen, in this order, to four different stores".
Erasing a member reaches a read model, a billing provider, a moderation provider and our own tables.
One message per step, one transaction per message, and a persisted record of how far it got.

|                          |                                                                     |
|--------------------------|---------------------------------------------------------------------|
| Saga state               | `src/Membership/Domain/Model/{MemberErasure,ErasureStep}.php`         |
| Driver                   | `src/Membership/Application/ErasureService.php`                       |
| Steps                    | `src/Membership/Infrastructure/Erasure/ErasureSteps.php`              |
| The other context's half | `src/Matching/Infrastructure/Erasure/MatchingErasureSubscriber.php` |

Path 5 is also where ADR 004's projections are torn down, which is the half people forget. Every
filter that cost a projection costs a line in one of those two files: `matching_sent_like` for the
feed's "not already liked", `membership_subscription_feed` for path 4's list. Deleting the originals
does not touch a copy.

**Open first:** `ErasureService::advance()`, and read the `catch`. Only the first step is reversible.
After it there is no compensating action, only forward recovery, and `ErasureStep::pastPointOfNoReturn()`
says where that line falls. A saga that claimed to undo a closed subscription would be wrong.

The saga state itself is a plain aggregate in one table (PATH 2), which is kind of funny and could be confusing.
The object coordinating four stores that share nothing is the most ordinary in the project.

## The rules all of this obeys

1. **One aggregate, one owner.** Either Doctrine tracks it end to end, or our tracker does. Never
   both. Two change trackers over one object is how a single mutation becomes two writes, in an
   order nobody chose.
2. **The Application layer asks the same question regardless.** Every path ends at an interface in
   `Domain/Repository/` (or, for the read side, `Application/ReadModel/`), and no handler can tell
   which implementation it got.
3. **No context reads or writes another context's tables.** Events in, projections local, and
   erasure fans out through `MemberErased` rather than through a `DELETE`.
4. **Nothing pretends to be atomic that is not.** A writer that touches two stores says so. A saga
   that cannot compensate says so. The interceptor's docblock says which half of its work rolls back.

## If you are adding a feature

Start at path 1 and stop as soon as it fits. The order of the questions is the order of the paths:

1. Is it one table whose shape matches the aggregate? Path 1.
2. Does the shape diverge, or does it have value objects or a child collection? Path 2. This is where
   almost everything belongs, including most of what feels like it does not.
3. Do you want the mapping *itself* isolated, a separate naming strategy, a projection? Path 3.
4. Does the data genuinely not all live in one database? Path 4, and start from the reader.
5. Does one change have to reach two stores? Path 5, and pick outbox or saga by whether order
   matters.
