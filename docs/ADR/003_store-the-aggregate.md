# Store the aggregate

Disclaimer: this is probably the biggest part we will cover.

While we want to isolate the domain, we still need to store, load, update, delete, filter the data.
We usually cannot expect to lose everything between each usage.

In the project where we use Symfony, the main tool for it is Doctrine.

Doctrine's promise is that your entity *is* your domain model: annotate or map a class, and the
`UnitOfWork` will hydrate it, track it, diff it and flush it for you. That promise holds for a while.
Then the aggregate grows a private constructor, collections that can only be mutated through methods
that enforce invariants, a shape that has nothing to do with the columns it's stored in, or (worse) a
lifecycle that touches more than one storage technology at once.

At that point we're not "using Doctrine" anymore, we're kind of negotiating with it: custom types, embeddables,
a shadow entity plus a hand-written mapper, XML mapping kept deliberately out of the domain namespace.

That bein said, Doctrine's mapping layer is, in principle, infinitely customizable, there is no shape of data
you technically **cannot** eventually coerce it to store, the interesting question isn't "can Doctrine do
this?". It's: **at what point does the effort of bending Doctrine's for the aggregate's shape
exceed the effort of just... not using it**, and tracking / storing the aggregate's changes differently?

## The limit spectrum

Let's consider a shape pushing the aggregate a little further from what Doctrine maps comfortably, and answers "is doctrine
mapping still worth it?" differently:

1. **Direct mapping**: the domain model is the Doctrine entity, no real difference with the storage shape. Invariants live in
   methods, Doctrine hydrates through reflection, `UnitOfWork::commit()` diffs and flushes. Zero extra
   code. This is correct for as long as the aggregate is representable as one Doctrine entity graph and
   you can tolerate Doctrine reaching into private state through reflection to hydrate it.
2. **Custom typing**: the storage shape and the domain shape have diverged a little and embeddable are not enough
   (denormalized aggregate vs. normalized tables, or the reverse), that a single class can't really represent both. So you start 
   creating dedicated types that will act as mappers.
3. **Manual mapping**: the storage shape and the domain shape have diverged so much that
   a single class definitely can't represent both. You create a "shadow" Doctrine entity is introduced purely to be
   persisted, a custom mapper translates domain model <-> entity by hand, and the repository does the translating.
   Doctrine still owns identity map but not for the aggregate anymore, dirty checking and the flush cycle, just for the shadow entity,
   not the domain model. The cost is the mapper and replicating changes, creation or deletion from the
   aggregate to the shadow entity when calling the repository methods, plus it grows with every field.
   This is still, structurally, "using Doctrine's `UnitOfWork`", with a bit of manual lifting. 
4. **A second, "`UnitOfWork`"**: the domain model is tracked by a different system from Doctrine:
   `UnitOfWork::track()`/`persist()` takes a snapshot, a `SyncDomainChangesSubscriber::preFlush()` walks
   tracked objects on Doctrine's own `preFlush` event and calls `save()` on whatever changed or is new.
   Doctrine no longer knows the domain model exists at all, we only borrow its `preFlush` hook as a
   convenient place to run our own diff, and its transaction boundary (`EcotoneDbalConfiguration`, one
   transaction per command/message) to make our `save()` calls atomic with everything else in the request
5. **split responsibilities**: We consider a identitymap and a change tracker to follow which aggregate is new, deleted, or has changed,
   and then delegate the providing and writing the data, And the UnitOfWork ensuring a single transaction-like flush could be held by another third party. The technologie is not locked anymore, it still could be Doctrine,
   but it could be a set of APIs to call, files to writes, a combination of the 3, for a single aggregate.

Stage 5 does not say "Doctrine is no longer good enough, throw it out". It's "the *entity mapping* stopped
being sufficient; the *transaction and lifecycle machinery* still is."

## What Doctrine's ceiling actually is

Doctrine has no hard technical limit on data shape. Custom `Type`s (`IdType`), embeddables, 
XML/attribute mapping to an entity that looks nothing like your domain model: all of that is
supported, and none of it requires the domain class to expose anything; no public constructor, no
setters. Doctrine's reflection driver instantiates via `newInstanceWithoutConstructor()` and writes private
properties directly. What Doctrine *cannot* do, no matter how much mapping you write, is:

- Track an object it has **no mapped class for at all**, like a DTO (`SELECT NEW`), a class that is
  deliberately absent from every entity manager's metadata.
- Represent a **single unit of change that spans more than one persistence technology**, e.g. an
  aggregate whose "save" also has to react to an Ecotone message, or be projected across a read model
  that isn't itself in a Doctrine-managed database. A `UnitOfWork`; Doctrine's or a hand-rolled one;
  only ever diffs *its own* identity map; anything outside it is invisible, however good the mapping is.
- Make two independent `EntityManager`s commit atomically **unless they share the same underlying DBAL
  `Connection`**; sharing metadata isn't enough, sharing the connection is what makes their flushes nest
  inside one transaction.

So the ceiling isn't a mapping ceiling, it's a *scope* ceiling: ORM-manageable relational storage vs.
everything else. A domain class staying deliberately ignorant of Doctrine, having a private constructor,
or having a shape that doesn't match its table; none of that is a scope problem. Only "this consistency
boundary includes something outside SQL" is.

## Direct mapping with custom type

This will probably solve most of your problems.

Take a case where a `SimpleAggregate` is stored as `ComplicatedDataShapeEntity`
(`complexeLabel`, `weirdEnabled: int`); a renamed column and a type coercion, etc... That could be
solved with a whole shadow class plus a Mapper. Both are not that necessary yet: a renamed column is a
`column` attribute, and a type coercion is a custom `Type`, the same category as `IdType` already in this
codebase.

```php
// Shared/Infrastructure/Persistence/Doctrine/Type/BoolAsIntType.php
final class BoolAsIntType extends Type
{
    public function convertToPHPValue($value, AbstractPlatform $platform): bool
    {
        return (bool) $value;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): int
    {
        return (int) $value;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }
}
```

This is your mapper of some sort.

```xml
<!-- src/MyModule/Infrastructure/Persistence/Doctrine/ORM/ManualMapping/SimpleAggregate.orm.xml -->
<doctrine-mapping>
    <entity
        repository-class="App\MyModule\Infrastructure\Persistence\Repository\SimpleAggregateRepository"
        name="App\MyModule\Domain\Models\SimpleAggregate"
        table="ComplicatedDataShape">
        <id name="id" type="string"/>
        <field name="label" type="string" column="complexeLabel" length="255"/>
        <field name="enabled" type="bool_as_int" column="weirdEnabled"/>
    </entity>
</doctrine-mapping>
```

`SimpleAggregate` itself gets zero Doctrine attributes or interfaces; the mapping lives entirely under
`Infrastructure/`, so if you have `phpat` domain-purity rules, they are unaffected. And the repository stays short:

```php
class SimpleAggregateRepository extends ServiceEntityRepository implements SimpleAggregateRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SimpleAggregate::class);
    }

    /**
    * @param Id<SimpleAggregate> $id
    * @return SimpleAggregate
    */ 
    public function get(Id $id): SimpleAggregate
    {
        return $this->find($id) ?? throw SimpleAggregateNotFound::withId($id);
    }

    public function add(SimpleAggregate $entity): void
    {
        $this->getEntityManager()->persist($entity);
    }
}
```

## Isolating the mapping concern: a second `EntityManager`

Suppose the domain class really shouldn't or can't carry any mapping metadata of its own; not even XML sitting
next to it in `Infrastructure/`; because either you want it isolated in its own bounded mapping concern (a
different naming strategy, a different proxy directory, a clean separation of "this bounded context's
persistence" from another's).

This does **not** cover the case where forming the data requires contacting one or many APIs. A second
`EntityManager` is still two ORMs over one SQL connection; that case is the next section.

Doctrine's answer to "a second, independent unit of change-tracking" is
already built in: **a second `EntityManager`**, each with its own `UnitOfWork` instance, its own identity
map, its own mapping set; configured to share the *same connection* as the default one so both still
live inside the one transaction `EcotoneDbalConfiguration` opens per command/message.

```php
'doctrine' => [
    'orm' => [
        'entity_managers' => [
            'default' => [
                'connection' => 'default',
                'mappings' => [/* directly-mapped entities */],
            ],
            'domain' => [
                'connection' => 'default',   // same connection service; same DBAL transaction
                'mappings' => [
                    'DomainModel' => [
                        'type' => 'xml',
                        'dir' => '%kernel.project_dir%/src/Catalogue/Infrastructure/Persistence/Doctrine/ORM/DomainMapping',
                        'prefix' => 'App\\Catalogue\\Domain\\Models',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ],
    ],
],
```

Sharing `connection: default` keep DBAL ref-counts `beginTransaction()`/`commit()` (via savepoints,
already enabled with `use_savepoints: true`), so each `EntityManager`'s `flush()` nests inside the outer
transaction instead of committing on its own; the same mechanism that already lets a single EM's flush
live (inside an Ecotone transaction for example). What's left to do not supported out of the box 
is a single call to flush the second manager at the same point the default one flushes. 
It's not a diff or a save, it's just "flush the other one too"; since Doctrine never cascades a flush 
from one `EntityManager` to another on its own.

## An aggregate assembled from several sources

Here is the case that would actually motivate you to split the mechanisms: An aggregate whose data comes from two APIs, 
or from an API and a database. There is no mapping problem here, because there is no table to map to, or only half of one. 
What we would want is that creation, update and deletion of the aggregate turn, behind the scenes, into HTTP calls, 
or into a mix of HTTP calls and SQL, issued at the end of the message in the right order.

### Can Doctrine's `UnitOfWork` be reused for that?

What are our options?

We could **swap the persister so `flush()` issues HTTP**. Short answer: **No.** 
`EntityPersister` is an interface, so it looks pluggable until you read the contract (ORM 3.6.8, `Persisters/Entity/EntityPersister.php`): `getInsertSQL(): string`, `getSelectSQL()`, `getCountSQL()`, `getSelectConditionStatementSQL()`,
`getOwningTable(string $fieldName): string`, `getResultSetMapping()`. It is SQL-shaped to the bone. An
HTTP implementation would have to return SQL strings it never executes, and not be really compatible, we would fake out to DQL, to the hydrators and to the second-level cache. It cannot be injected anyway: `UnitOfWork::getEntityPersister()` hard-codes
`new BasicEntityPersister(...)` in a `match`, `$persisters` is `private`, and `EntityManager` is `@final`.
That is would be more like a fork, not an extension. The same dead end exists one level down: There aren't DBAL driver speaking HTTP, because DBAL's contract is SQL-string in, rows out.

We could **borrow the change detection, do the writing ourselves**. Short answer: **Can work.**
`UnitOfWork::registerManaged(object $entity, array $id, array $data)` is public. Hydrate the aggregate
from the APIs, hand Doctrine the object plus its original field values, and from then on its dirty
checking treats it like any managed entity. At flush time `onFlush` exposes
`getScheduledEntityInsertions()`, `getScheduledEntityUpdates()`, `getScheduledEntityDeletions()` and
`getEntityChangeSet()`: a real, deep, field-level changeset to translate into API calls. **The catch is** that
the class still needs metadata, and once it has metadata... well... Doctrine will also try to write it to SQL.
Suppressing that is awkward, unless we want both as in a way of consolidating the data, like 
an "API call plus local mirror row". This is closer to an outbox pattern or event source approach.
Note also that the `NOTIFY` change-tracking policy is gone in ORM 3, so "the object announces its
own changes" is no longer available, which does not facilitate things either.

We could **reuse `doctrine/persistence`, not `doctrine/orm`.** . Short answer: **Not really.**
`ObjectManager`, `ObjectRepository`, `ManagerRegistry`, `Mapping/`, `Reflection/` and `Proxy` are backend-agnostic. 
But the `UnitOfWork` is not, and is not part of that package. MongoDB ODM and PHPCR-ODM both faced exactly the issue and
both **reimplemented** `UnitOfWork` rather than reusing the ORM's. To my knowledge, nobody has ever reused it against a
non-SQL backend. And it's not an easy task to do.

### Transaction are not the same

Calling `commit()` promises "apply everything, atomically, or nothing". Across two APIs there is no rollback:
API A's POST succeeds, API B's PATCH returns a 500, and the flush is half applied. 
And making a custom `UnitOfWork` does not fix that really, it just relocates it. 
What would fix it is compensation (a saga), or one authoritative store plus an outbox.
We could write locally inside the Ecotone/DBAL transaction, publish async, then push to the APIs from the consumer with
retry, deduplication, and Messenger's `failed` transport as the dead-letter queue.

The Unit of Work question and the consistency question are separate. 
Answering the first never answers the second.

### How much Unit of Work do we actually need?

Under Unit of Work, Four capabilities are distinguishable:

1. **Identity map**: guarantees the same instance per identity within a message.
2. **Which aggregates were touched**: so that something gets written at the end at all.
3. **Field-level changesets**: becomes worth when the remote API is `PATCH`-shaped, or when writes are
   expensive enough that no-op writes must be skipped. Of course a full-document `PUT` needs no diff whatsoever.
4. **Ordering and atomicity across stores**: Like we said in previous paragraph, It is not a Unit of Work concern.

What we need is **Identity Map and Track changes only**. Ok, to avoid overwriting unnecessarily, diffing could be useful, but that would be a performance concern more, that still could be delegated to the storage system. We really only requires knowing which aggregate a handler loaded or created, changed or disappeared.

### Ecotone (but lets call it the command bus) already answers to "what changed"

In a project running Ecotone, its aggregate repository abstraction answers "when" and "which"
directly. `#[Aggregate]` on the domain model plus a `#[Repository]` implementing `StandardRepository`:

```php
public function canHandle(string $aggregateClassName): bool;
public function findBy(string $aggregateClassName, array $identifiers): ?object;
public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void;
```

Ecotone fetches the aggregate through `findBy()`, runs the command handler, then calls `save()` with
whatever the handler touched. That `save()` is ours: it can write through Doctrine, call two APIs, or mix
both. The messaging layer already knows when a message ends and which aggregate it concerned, so nothing
has to reverse-engineer that from object state. `$versionBeforeHandling` (populated when the aggregate
carries `#[Version]`) is the optimistic-locking hook, and it maps cleanly onto conditional requests:
`If-Match` with the ETag we read the aggregate with.

What Ecotone does not give is a changeset, since `save()` receives the whole aggregate. 
But it is not needed here, that is not a loss.

### `marble/entity-manager`, a packaged version of the split idea

There is one PHP library that is precisely this, and it is worth considering:
[`marble/entity-manager`](https://github.com/marble-php/entity-manager), an "ORM-less entity lifecycle
manager". It provides an identity map, automatic change tracking, an ordered flush and `remove()`, and
asks you to implement an `EntityReader` and an `EntityWriter` per entity, with an `EntityIoProvider`
routing per class; so a single aggregate can be read from one source and written to another. The README
sells it for "SQL, NoSQL, or even external APIs" explicitly. It diffs with `sebastian/comparator`.

The caveat is important: 4 GitHub stars and a few hundred installs. Depending on it means being
ready to maintain it. It also documents two limits worth knowing: no circular associations, and changes
made before a flush are not visible to queries.

--

Other libraries in the "neighbourhood", but none of them really caught my eyes: 
    - **Cycle ORM**, whose custom mappers emit generic `queueCreate`/`queueUpdate`/`queueDelete` commands into a storage-agnostic command chain (every shipped driver is still SQL); 
    - **`microsoft/kiota-abstractions`**, has a `InMemoryBackingStore` doing real per-model dirty tracking so a `PATCH` sends only changed fields, but per object, with no identity map and no flush;
    - **`isolate/unit-of-work`** and **`opulence/orm`**, would be the older storage-agnostic concept matches, but both seems to be effectively dead.

### What if we had our own mechanisms?

There are three things that we need to have, in increasing order of effort:

- A **Deep diff**: `sebastian/comparator` is already required by PHPUnit as dependency, so promoting it to
  `require` is not that scary; it is the same tool marble uses too.
- **Deletion tracking**: That would require a bit of work on our side.
- **Reader-side tracking**: There is marble's `EntityReader`, and also a bit of consideration from our repositories.

Which could be solved by having an identity map and use Ecotone's repository for the rest.

## How should it behave, with Ecotone

Two things are wanted. Retrieve the aggregate without caring where it is stored, and have a change to it
saved without asking for it. Ecotone answers the saving part already, but it is worth seeing how before
writing anything.

When a `#[CommandHandler]` is defined on an aggregate method, Ecotone assembles a three steps chain
(`vendor/ecotone/ecotone/src/Modelling/AggregateFlow/`):

```
       command message
             │
             ▼
    ┌─────────────────┐   repository->findBy()     ┌──────────────────┐
    │  LoadAggregate  │ ─────────────────────────► │   your store     │
    └─────────────────┘                            │  SQL, API, both  │
             │ the aggregate                       └──────────────────┘
             ▼
    ┌─────────────────┐
    │  CallAggregate  │   $pair->reject($command)
    └─────────────────┘
             │ the same instance, mutated
             ▼
    ┌─────────────────┐   repository->save()       ┌──────────────────┐
    │  SaveAggregate  │ ─────────────────────────► │   your store     │
    └─────────────────┘                            └──────────────────┘
```

Load, call, save. We do not write a line of code for it, the save is automatic, and a `canHandle()` method picks the
repository. The behavior described above is delivered by the framework.

There is a catch though. We must allow the domain layer to import
`Ecotone\Modelling\Attribute`. So `#[Aggregate]`, `#[Identifier]` and `#[CommandHandler]` may be put on the domain aggregates. 
Fortunately attributes do not have an impact on code execution if the framework were to change. 
It could be something we tolerate. do we want to? The question is as valuable as: do we want Doctrine attribute on the domain?

So really we have 2 options.

### Option A, the handler on the aggregate

Authorize to import the attributes, put them on the aggregates, implement `StandardRepository::findBy()` against whatever
store. No identity map to write, no interceptor, no glue at all. The price is allowing the attributes and having command routing 
living in the Domain.

### Option B, the handler stays in Application

This time, Ecotone's chain does not apply, because `CallAggregate` calls a method on the loaded aggregate and our
handler is not one. So we supply the missing step, with an `#[Around]` interceptor in `Shared/Infrastructure/Ecotone/Interceptor/`:

```
  command message
         │
         ▼
  ┌──────────────────────────────────────────────────────────────┐
  │ transaction interceptor  (EcotoneDbalConfiguration)          │
  │  ┌────────────────────────────────────────────────────────┐  │
  │  │ save-touched interceptor  #[Around]                    │  │
  │  │  ┌──────────────────────────────────────────────────┐  │  │
  │  │  │ PairService::reject()   #[CommandHandler]        │  │  │
  │  │  │   $pair = $repository->find($id);  ─────────────────────► identity map
  │  │  │   $pair->reject();                               │  │  │      tracks it
  │  │  └──────────────────────────────────────────────────┘  │  │
  │  │   then, for each tracked aggregate: save()  ───────────────► the writer
  │  └────────────────────────────────────────────────────────┘  │     for its store
  └──────────────────────────────────────────────────────────────┘
         │
         ▼  commit
```

Note who does the tracking. Not the handler, the repository does, when it reads.

"But that saves everything that was read, including what did not change." 
Yes, and here that is fine, because we do CQS. Reads for display go through query handlers **and read models**.
The aggregate repository would only ever be used inside a command flow.
Arguably, if the store is a paid API and a wasted `PUT` costs real money, add the diff then, as an optimization, not as the mechanism.

Option B is the one I would take to keeps the Application layer, and the aggregate is kept out of knowing what a message is.

## Ecotone and marble together

Option B needs an identity map, possibly a changeset, and one writer per store. That is marble's job
description word for word. So: Ecotone for the "when", marble for the "what" and the "where"?

The responsibilities are clear:

```
   Ecotone                                  marble
   ──────────────────────────────           ──────────────────────────────
   The message                              identity map
   the transaction around it                change tracking (HasChanged)
   routing a command to its handler         ordered flush
   optimistic locking (#[Version])          a reader and a writer per class
                                            routing a class to its IO
```

The option B interceptor would more or less require one line:

```php
// after the command handler returned, still inside the transaction
$this->entityManager->flush();
```

Marble's identity map already knows what was read, its change tracker already knows what moved, its
`EntityIoProvider` already knows which writer each class belongs to. Had we taken option A instead, the
`StandardRepository` would be a six line adapter: `findBy()` delegates, `save()` does `persist()` then
`flush()`, and `canHandle()` answers `getWriter($class) !== null`, leaving one routing registry instead
of two.

The writer side is the part I would try to delegate to dedicated libraries as well:

```
EntityWriter::write(Entity|HasChanged, WriteContext): void
EntityWriter::delete(Entity, DeleteContext): void
EntityIoProvider::getReader(class) / getWriter(class) / getCustomRepository(class)
```

One writer persists through Doctrine, another `PATCH`es an API resource with HttpClient, and the `HasChanged` variant
handed to the writer is what lets that `PATCH` carry only the fields that moved.

So, do we take the dependency? 
AS tempting as it is I probably say No. (Ok maybe for pet project I would) 
And the reason is not the four GitHub stars, though that is worth saying out loud too.

Marble asks the aggregate to `implement Entity` interface, and identity to be a marble `Identifier` (`SimpleId`,
`Uid`, or our own implementation of it). This is where we start coupling our domain with a vendor interface.
This is a no-go.

Compare what the two libraries ask for. At least Ecotone asks for attributes, which are inert; nothing but Ecotone
reads them, and a domain object wearing one behaves identically without it. Marble asks for interfaces,
which are behaviors, and it also owns instantiation, since readers fill a `DataCollector` and marble
builds the entity. That is a second question mark against our private constructors.

I would probably reimplement it with attributes in mind instead if I had the time. 
We already hold three quarters of it under other names, and the fourth is the one to write. 
The single idea worth copying verbatim is that the writer receives the changeset rather than the whole entity, because that is what makes a partial write possible, and it is easy to design badly from scratch.

## What goes inside a reader or a writer?

I would expect many of you object: "Fine, but eight cases out of ten are a
flat aggregate in one table that Doctrine would have handled without any of this. Do we now handwrite SQL
for all of them?"

No, and the rule that prevents it is short.

**One aggregate, one owner.** It is either Doctrine managed, and then Doctrine's `UnitOfWork` tracks it
from end to end, or it is provider managed, and then ours does. Never both. Two change trackers over one
object is how a single mutation becomes two writes, in an order nobody chose.

So this eight times, it never reaches a reader or a writer. They stay ordinary Doctrine entities. The
uniformity we were after was never "every aggregate goes through the same machinery", it was "the
Application layer asks the same question regardless", and that already lives one level up, in
our interfaces. One implementation of it is a Doctrine repository, another is provider backed,
and no handler can tell which.

```
                              an aggregate
                                   │
                ┌──────────────────┴───────────────────┐
                │                                      │
      fits one relational graph,            assembled from several sources,
      invariants survive hydration          or no table at all
                │                                      │
                ▼                                      ▼
        Doctrine owns it                      the provider owns it
        ──────────────────                    ────────────────────────
        XML mapping on the domain             EntityReader + EntityWriter
        class, custom types,                  per class, our tracker,
        its own UnitOfWork                    saved at the message boundary
                │                                      │
                └──────────────────┬───────────────────┘
                                   ▼
                        TheAggregateRepositoryInterface
                    (the Application layer sees only this)
```

Now, for the ones the provider does own whose store happens to be a table, what goes inside?

**I would not recommend a `ServiceEntityRepository`.** It is meant to handle a managed entity class, 
so reaching for one means we already accepted a shadow entity, and we are back in paying for an intermediate mapper.

**Not the `EntityManager` either**, for the same reason: `persist()` needs something managed. That being said I see one
exception. If the storage shape has child collections, working out which rows are new, gone
or changed is exactly what Doctrine's `UnitOfWork` is good at, and buying that back is worth a mapper.

**Probably DBAL, then.** A reader wants rows, not objects (eventually turned as objects later for other reasons),
so hydrating entities only to take them apart again is working backwards. And the DBAL `Connection::update($table,
$data, $identifier)` takes precisely the array a changeset produces:

```
                changeset { status: 'rejected', rejectedAt: '...' }
                                   │
                  ┌────────────────┴─────────────────┐
                  ▼                                  ▼
          SQL writer                          API writer
          Connection::update(                 PATCH /pairs/{id}
            'pair',                           { "status": "rejected",
            $changed,                           "rejectedAt": "..." }
            ['id' => $id]
          )
```

Same input, two writers, one partial write each.

And it is reproducible without a billing account, which took one decision. The dev and test provider
(`src/Membership/Infrastructure/Billing/PretendBillingGateway.php`) keeps its subscriptions in its own
SQLite file, `var/billing.db`, reached through its own connection and never enrolled in a
transaction. Reusing the application's `Doctrine\DBAL\Connection` would have been one line shorter
and would have deleted the exercise: the provider would join the command's transaction, a rollback
after `open()` would take the remote subscription with it, and the two stores would commit together
forever. Nothing would fail; the tree would boot, the endpoints would answer, and the only thing
lost would be the reason this path exists. A fake transport is fine. A fake transaction boundary is
not.

**And the schema?** Ah AH!. Once the aggregate stops being a Doctrine entity,
`doctrine:schema:create` and the migrations no longer know its table exists. 
For the sake of simplicity I would keep the XML mapping anyway, 
purely as schema metadata, even though no `UnitOfWork` will ever manage an instance of that class.
It costs nothing and the tooling keeps working.

And once that mapping is there, [ESQL](https://github.com/soyuka/esql) becomes attractive, because it
reads exactly that metadata: `$esql->table()`, `$esql->columns()`, `$esql->identifier()`, executed over
DBAL, returning rows. We write the SQL, but not the table and column names, so renaming a column in the
mapping does not silently break a reader. It is another small dependency (93 stars), although soyuka
maintains API Platform, which we already depend on. There is a mild irony in keeping a Doctrine mapping so
that a non-Doctrine query layer can read it, but to be honest I am fine with it.

**One last detail, hydration.** Our constructors are private and our named constructors enforce invariants,
which is the point. A reader rebuilding an aggregate from a row must not go through them, because
that row passed those checks once already. You could give it its own named constructor,
reflection would work too, but quietly, and there I think I would rather an explicit one better. 
It's signature documents which path/method skips validation.

## The larger view

All the paths of this ADR, on one picture:

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
  direct mapping            XML mapping +      second EntityManager         │
                            custom types       same connection              │
        │                          │                   │                    │
        └──────────────────────────┴───────────────────┘                    │
                          │                                                 │
              Doctrine's UnitOfWork does it all               saved at the message
              (our tracker does nothing)                      boundary, by Ecotone
                                                                            │
                                                         does the write span two
                                                         stores at once?  ──┘
                                                                  │
                                                                  ▼
                                                        outbox or saga.
                                                        never a flush()
```

Put concretely, do not chose a single option for the entire project.
Each of them are useful, use them all, for the right need.

1. **Stay on direct mapping** while the aggregate is one Doctrine entity graph and its invariants survive
   Doctrine hydrating it through reflection/proxies. Cheapest, most automatic, no reason to leave it.
2. **Map the domain aggregate directly via XML/custom types**, even when the storage shape diverges from
   the domain shape (renamed columns, value-object coercions, embeddables, associations to other
   XML-mapped domain classes). This absorbs almost everything that looks like "the mapping got too
   complex" without introducing a shadow entity, a hand-written mapper, or leaving Doctrine's own
   `UnitOfWork`.
3. **Reach for a second `EntityManager`** (sharing the default connection) only when isolating the
   mapping concern itself is the actual goal; a different naming strategy, a cleanly separate mapping
   set per bounded context; while the underlying storage is still the same reachable database. Still
   Doctrine's own `UnitOfWork`, just a second instance of it, resolved automatically per class through
   `ManagerRegistry`.
4. **For an aggregate assembled from several sources**, do not start from a Unit of Work. Start from
   Ecotone's `StandardRepository`, which already knows when the message ends and which aggregate it
   concerned, and put the API calls in `save()`. Add an identity map if instance identity within a
   message matters. Add field-level changesets only if the remote API is `PATCH`-shaped or writes are
   expensive; and if it comes to that, read `marble/entity-manager` before writing a fourth
   implementation of the same three-part split.
5. **Never expect `flush()` semantics across stores.** The moment the consistency boundary spans two
   systems that do not share a connection, the answer is an outbox or a saga. No Unit of Work, ours or
   Doctrine's, makes a cross-store write atomic; it only makes the partial failure harder to see.

## Extra: when the "second store" is another project's broker

Both path 5 features in this repo end in an HTTP call, which makes the outbox look like an HTTP
concern. It is just because of the use cases we chose to demonstrate. It could have had a file storage.
Let's consider another use case: we register a member, and another project entirely, owned by another team, 
with its own RabbitMQ, has to learn about it. Say this codebase is the sales team's, 
and the client facing website needs an event for every new member.

Start with what does not change, which is almost everything. `Outbox::record()` writes a row in the
command's transaction and knows nothing about what will happen to it; `OutboxHandler` does its job, and
a handler that publishes a message is no different to the relay than one that POSTs. The guarantee is
the same sentence it always was: the row commits with the `Member` insert, delivery happens afterward,
at least once, with a record.

Four things do change, and only the last one is about RabbitMQ.

**The payload stops being the domain event.** `MemberRegistered` is Membership's own event, and it changes
when Membership changes. What crosses a project boundary is a contract we now owe someone:
`membership.member.registered.v1`. Notice the topic names the event and not the destination, which
matters for the choice below. `record()` taking an `array` rather than an object helps here because 
there is no way to lazily serialize the domain event into the row, so the wire shape has to be written out by hand, 
and that is the moment we notice we are designing a published language instead
of exporting ours. When they need another field, it becomes `.v2` and both are published for
a while. It is not so different from exposing a ReST API.

**The consumer needs our idempotency key.** The promise of a message being executed At-least-once 
is now somebody else's problem too, and the only thing letting them recognize a redelivery is a stable message id. 
`OutboxMessage` already has an `Id`, and nothing currently sends it. Over AMQP it belongs in the `message_id` property, 
so `OutboxHandler::handle(array $payload)` has to widen to receive it.

**`markSent()` has to be adjusted.** The current implementation with HTTP the 2xx *is* the acknowledgment, so a handler that
returns without throwing really did deliver. `basic_publish` returns nothing: the relay writes
`sent_at`, and the record claims a delivery that may never be routed. We need a confirmation mode, and actually wait for
the confirmation.

**Ordering is absent and no flag adds it.** `OutboxRelay::drain()` orders by `recorded_at` but
continues past a failure, so a stuck `member.registered` does not hold back the `member.renamed` behind
it. If the other side cannot tolerate that, the outbox grows a partition key column and a rule that a
partition stops at its first failure.

### DO NOT publish into their broker

The obvious implementation is to point the handler at their broker. It works, and we would still not do
it because we would then hold their credentials, their vhost and exchange names in our
Infrastructure, and make our relay's health a function of their broker being reachable. Every failed
publish burns one of many attempts, so a long deployment on their side leaves our rows exhausted and a
human reading `last_error`.

The approach is to publish to our own exchange instead, and let the brokers do the hop. RabbitMQ's as plugin named shovel 
that consumes a queue on our cluster and republishes to a URI on theirs. Which gives us, in order of how much it matters:

- their broker being unreachable stops being our failure. Messages pile up in our queue and the shovel plugin
  reconnects on its own. Our relay published successfully to our own exchange and is done;
- the remote credentials and topology live in broker configuration rather than in the projects code, so rotating
  them is not a deployment matter, and no part of the application names the other project;
- who consumes our events stops being our concern. A second subscriber costs them a binding on our
  exchange, not a change to a handler of ours;
- it is observable as a shovel with a state in the management UI, instead of as a rising `attempts`
  column.

Another plugin named Federation expresses the same hop as an upstream link and is the better fit when both clusters want to
share an exchange as peers. Shovel is unidirectional, point to point and is declared entirely on
one side, which is exactly the shape of "push our events to another team". 
