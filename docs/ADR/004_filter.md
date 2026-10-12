# Filter

Filter is the process to reduce the amount of data selected from a collection and then eventually
paginate over the result.

This ADR follows [ADR 003](003_store-the-aggregate.md), and it has to. That ADR ends on five
different ways an aggregate can be stored, and a filter is a question asked of stored data. A
filtering design that only works on two of those five would not be correct.

If you would rather watch it than read it:

```shell
bin/console app:reads          # walks every read model once and reports how each one filters and paginates
bin/console app:reads --keep   # same, but leaves the data behind so the endpoints have something to serve
```

That command is to this document what `app:paths` is to ADR 003. It asks one list per storage path,
through the query bus, and prints which read model answered, which pagination that port offers and
whether anybody paid for a total. The last section is the batch: a write that starts from a filter,
in two steps, selecting without changing anything.

## Flow

A user sends input: an HTTP query string, a CLI option, an HTML form field.

That input is parsed into typed values. A failure to parse is a 400 or an exit code 22, never a
silent default and never a `WHERE 1=1`.

The typed values reach a **read model** through a query handler.

The read model's adapter turns them into whatever its store understands: DQL, SQL, HTTP query
parameters, or a closure over an array.

Did you notice the thing I did not say? There is no repository in it, and the rest of this document is
mostly about why.

## Architecture

Filtering responsibility is split across three layers, and the separation is probably not the one you would guess.

- The **UI** declares what can be asked, and parses what was asked. It is the only layer that distinguish
  a query string from an `InputOption` from a form field, and the only one that knows whether a
  parse failure is an HTTP status or an exit code.
- The **Application** declares the questions and filters a read model can answer, and carries typed values to it.
  It does not know how they will be answered, and must not be able to find out.
- The **Infrastructure** interprets. It is the only layer allowed to know whether the answer comes
  from a JOIN, from a query parameter on somebody else's API, or from `array_filter`.

The Domain is missing from that list on purpose.

A filter the user typed is not a domain concept. "Show me the green ones" is a question about a
screen, and it changes when the screen changes. A selection that carries a *rule*, on the other
hand, absolutely is a domain concept, and it is a method on the aggregate rather than a filter; that
case gets its own section further down.

### Layer isolation

Because of that isolation, there MUST NOT be any coupling between how a filter is exposed to a
delivery mechanism and how it is interpreted by a store. We MUST be able to add a CLI option to an
existing filter without touching the query, and to change the store behind a read model without
touching any delivery.

## Where does filtering apply?

The obvious answer is "onto a collection of aggregate roots, coming from a repository". It is the
answer the DDD literature gives, and I realized lately that it is the wrong place for our architecture. 
Let me first say why.

As a reminder, a repository is a centralized, organized and secure storage system for various types
of data. It is a container that holds, manages and preserves information. Repositories can store
different data types, including structured (databases), semi-structured (XML, JSON) and unstructured
(documents, images, videos) data. It acts as a single source of truth, making data consistent,
secure and easily accessible.

In most PHP application, a repository and an aggregate root collection serve distinct but related
purposes under a single object. Usually a repository acts as a storage abstraction for aggregate
roots, while an aggregate root collection is the grouping of these objects. Here, using
Domain-Driven Design, the repository is both.

There, a repository is a design pattern that acts as an intermediary between the domain layer and
the data mapping layer (usually its infrastructure counterpart). Its primary purpose is to abstract
the persistence logic, making your domain objects persistence-ignorant. This means your domain
objects don't know how they are saved or retrieved.

In DDD, a repository is defined for each aggregate root. An aggregate root is a single entity that
acts as a gateway to a cluster of related objects. The repository is responsible for:

- retrieving a complete aggregate (the aggregate root and all its children);
- saving a complete aggregate;
- searching for aggregates based on specific criteria.

By using a repository, your application's business logic can work with domain objects **as if they
were in an in-memory collection**, without needing to know the underlying database or ORM
implementation.

```php
interface OrderRepository
{
    /**
    * @phpstan-param Id<Order> $id
    */
    public function getById(Id $id): Order;

    public function save(Order $order): void;

    /**
    * @phpstan-param Id<Customer> $id
    */
    public function findByCustomer(Id $customerId): OrderCollection;
}
```

Since an aggregate root is the principal entity of an aggregate, which is a group of objects that
must be treated as a single unit for data changes, the aggregate root is the only entity that can be
directly accessed from the outside. Any interaction with the child entities must go through the
aggregate root. For example, an `Order` is an aggregate root, and its `OrderItem`s are its children.
You would never directly manipulate an `OrderItem` from outside the `Order` object; instead, you
would call a method like `$order->addItem($item)`.

All of that is true. Although none of it enforces a repository to be the place where you filter.

### The repository is not where you filter

Let's look again at what it actually asks a repository to hand back.

An aggregate is a consistency boundary. That is its definition and not a convention: everything
inside one changes together and atomically, and anything outside it is a second boundary, reached
separately. A repository is that boundary's collection, for the write side. Its job is to give a
command the one boundary it is about to change, and to take it back afterward.

"Searching for aggregates based on specific criteria", has 2 problems when what comes back is *aggregates*.

It means that It asks of a command to handle several consistency boundaries to modify in a single transaction, 
which is the rule an aggregate exists to state, and is broken in order to save some typing. Or it hands a *read* whole
aggregates, with their children, their value objects **and their invariants**, in order to display a
list; which is paying the write model's price, per each row, for something that will be serialized and
thrown away. The first problem is a correctness problem and the second is a cost problem: 
It treats "a set of aggregates" as a thing the domain deals in, when the domain should only ever deal with one at a time.

Then if we look at the 5 ways to build an aggregate from the storage:

On path 3, 4 or 5 there may not be any collection to search at all. No local table to hold the fields, and the provider
may have no idea which of its aggregates are ours. With many providers for a repository, 
a fan-out of N provider calls is not a query, it is an outage with a `WHERE` clause;

That is what makes it an architectural error. A repository interface declaring `findBy(criteria)` 
is a promise that at least two of the five implementations cannot keep, on an interface whose entire point, 
is that **the Application layer cannot tell which implementation it got**. 
An interface that has to be implemented by throwing is an interface with the wrong method on it.

So the search responsibility does not belong to a repository. It belongs to a read model, which can
be shaped for the question rather than for the invariant, fed by whichever of the five paths suits,
and which can return exactly what the caller needs; often identifiers, rather than whole aggregates.

> **A repository answers "give me this one aggregate". A read model answers "give me the rows that
> match". Filtering belongs to the second, always, on all five paths.**

Reads for display go through query handlers **and read models**. 
The aggregate repository would only ever be used inside a command flow.

That is the whole ADR. Everything below is consequences.

### What about a batch update?

"Close every subscription whose trial ended yesterday." "Archive every quote older than ninety days."

These are ordinary business needs, they are unambiguously *writes*, and they start from a *filter*.
If a repository is not where you filter, then where?

There are three ways.

**The obvious one (to avoid): load the set, iterate, save.**

```php
foreach ($this->subscriptions->findExpiringBefore($date) as $subscription) {
    $subscription->close();
}
```

This is the shape that makes people want `findBy(criteria)` on a repository, so, why is it so bad?

- it puts N consistency boundaries inside one transaction;
- the size of that transaction is decided by the data, not by the command. It grows with how long
  the job was left unrun, and it holds every lock for the duration;
- the change tracker, Doctrine's or any other, has to snapshot N aggregates and write N times at the
  boundary. On path 4 that is N HTTP calls with no rollback;
- a failure on item 4,732 either discards 4,731 successes, or, on path 4, does not discard them and
  cannot tell you so.

**When the change carries a rule: filter to identifiers, then fan out.**

1. a read model answers "which identifiers match", and returns `list<Id<Subscription>>` rather than
   aggregates. That is a filter, on the read side, exactly like every other filter in this document;
2. one command per identifier goes on the bus, asynchronously;
3. each command loads one aggregate by identity, changes it, and is saved at its own message
   boundary, inside its own transaction.

What we achieve with this approach:

- one aggregate per transaction;
- failure is per item and resumable. `EcotoneDbalConfiguration` already gives one transaction per
  message and deduplication for at-least-once redelivery, and Messenger's `failed` transport has
  a dead-letter queue. 4,731 commit, one dead-letters, the rest carry on;
- it can spread across consumers, where the loop is one process holding one lock set;
- it is countable / observable while it runs, which a loop inside a transaction is not (at least not for free).

Said otherwise:

> **The read model selects candidates. The aggregate decides.**

This approach is not without consequences either, because it means the batch is eventually consistent: halfway through, 
some are closed and some are not. There is no instant at which every subscription became closed simultaneously 
in *any* implementation that touches more than one store. That would be a utopia.

Consequently, by the time the command for item 4,732 runs, that subscription may have been closed by a human or
renewed by its owner. The selection may be stale by construction, so the per-item command re-reads the
boundary and the aggregate is allowed to refuse. `Subscription::close()` throwing on an
already-closed subscription is not a problem in the selection; it is the boundary doing the one job
it has. A batch that assumes its own selection is still true is a batch that silently overwrites
whatever happened in between.

So if you were wondering: no, a read model is not only for screens. 
One whose consumer is a command is perfectly ordinary. 
What differs is its staleness, and the answer to staleness is the aggregate's precondition, never a fresher query.

**What if the writing carries no rule?**

Sometimes the change enforces no invariant, emits nothing anyone consumes per item, and the set is
large: anonymize a column, backfill a default. These are legitimate `UPDATE ... WHERE`, 
and it is an *infrastructure* operation that has to be clear about it (a good old comment), 
because it bypasses the aggregate entirely: no invariant, no event, no tracker,
and therefore any read model fed by those events is now stale unless something tells it. It is also
only available on paths 1 to 3, since path 4 has no table to update.

So in this case the approach could be expressed like this:

"Filter to identifiers on the read side, then one command per aggregate." 

Ok, but is it just the same a before with no domain meaning?
Pretty much, yes. The selection is a read, and the mutation is still one aggregate at a
time, so the repository is still asked by identity, N times. The previous approach above did not need an
exception, or anything, it is just the meaning of the command that changes.

### SQL first

Once a repository has returned a collection of objects, it should in principle be perfectly
reasonable and efficient to perform additional, in-memory filtering on that collection. Filter them
by a first name the user typed, sort them alphabetically, find the ones in a specific city.
"Performing these simple operations on an in-memory collection is significantly faster and less
resource-intensive than making another round trip to the database for every small filter". This
statement would be true for languages that are not emptying their memory between each user request.
But for the web, and especially in PHP, you would most probably reduce the amount of data loaded
using a pagination which must be applied *after* filtering. On a large dataset you cannot ask an
in-memory collection to reduce by customer name or by city and then paginate; it is strictly worse
than a well-prepared query that reduces the set in the store.

**Which is why I would recommend, 90% of the time, a store-first filtering approach.**

I used to say "SQL-first, delegating most of the work to the repository". 
The second half of the sentence was a mistake I use to make: the work goes to the read model's store, which on path 3 is
a projection on a second entity manager, and on path 4 is a projection we chose to build rather than an API we fan out to.

In-memory filtering is left with two legitimate cases, and only two. A dataset you know is small and
will stay small (a few dozen rows behind a dropdown). And a selection that is a domain rule, which
is the next section, and which is not really "filtering" at all.

## Where a filtered list comes from, path by path

Every list is served by a read model. The five paths of ADR 003 decide how that read model is
**fed**, never how the filter is **applied**. Based on our project and
[`docs/storage-map.md`](../storage-map.md), here is how for the read side:

| path                                       | the list a screen asks for             | what answers it                                               |
|--------------------------------------------|----------------------------------------|---------------------------------------------------------------|
| 1, `Like`/`Block`                          | "who have I liked"                     | a read adapter over the same tables, returning DTOs           |
| 2, `Member`/`Badge`/`Pair`                  | "Badges that are green"                 | a read adapter that knows the mapping, not the aggregate      |
| 3, `ProfileJournal`                        | "my profile history"                   | the shadow entity, which is already a read shape              |
| 3, the discovery feed                      | "who could I show next, having argyle" | the projection itself.                                        |
| 4, `Subscription`/`BadgeModel`/`Reputation` | "subscriptions expiring this month"    | a projection you decided to build. The reader will not answer |
| 5, outbox and saga                         | n/a                                    | how a projection stays fed, and how it is torn down           |

### Path 1, where the aggregate and the row coincide

`Like` and `Block` are a column list. The table is the aggregate, so a read adapter querying that
same table is ok and needs nothing more beyond the class itself.

It still returns DTOs rather than `Like` objects. The moment a read path hands back an aggregate, 
someone downstream will mutate one, and on path 1 that aggregate is Doctrine-managed, so the mutation will be flushed. 
A list endpoint that can write is a list endpoint that eventually does. 
With Doctrine you can use DQL `NEW`, or readonly mapping. 

### Path 2, where you are not filtering the aggregate at all

`Member` diverges from its table on every single field. 
Which means a filter expressed in domain vocabulary is a lie until something translates it:

For example:
- `preferences` is a `MatchingPreferences` value object collapsed into one json column, so
  "members who accept wool" is a JSON operator, not a field comparison;
- `badgeDesign` is an embeddable spread over two columns, so the filter needs the column names the
  embeddable chose, not the value object's shape;
- `status` is a *pure* enum through `PureEnumType`, and `handle` goes through a custom type, so
  the parameter binding has to be given the type.

So on path 2 the translation from "what the user asked" to "what the store understands" belong to a read adapter 
that is allowed to know the mapping. It can't really belong to a generic filter object handed down from the UI, 
which by construction knows only field names.

### Stage 3, where a read shape already exists

`MemberProfileRevisionRow` is shaped by the table. It is already the read model; the mapper exists
to turn it into an aggregate, and a list query simply does not call the mapper.

It still comes with some performance or memory limitation. 
For example `ProfileHistoryService::history()` currently returns every revision
a member ever had, unpaginated, by loading the journal aggregate. Fine for a member
with nine revisions, difficult to defend for one with nine thousand.

### Path 3, where we read a projection

In the project, the discovery feed is a projection with its own entity manager, its own table, fed by `MemberRegistered`,
`MemberProfileChanged` and `MemberStatusSwitched`, over the same DBAL connection so it commits with
the writing operations.

Filtering here is trivially cheap because the projection exists *for* the query. The important
property of the projection also looks like a limitation: **adding a filter means adding a column to the
projection.** The impact of supporting a filter is never immediate, but could later become a slow query you did not expect.

Looking at `DoctrineCandidateFeed` also see a second limit. To get "Candidates I have not already liked"
you would use `NOT EXISTS`, but you cannot, because `matching_like` belongs to the other manager
and DQL does not join across managers even on one connection. In this case we have to project
the likes as well, or to filter after the fact and accept we can't rely on SQL pagination and experience a drift when paginating.
What you'd avoid is a raw SQL join, which reintroduces exactly what the two managers existence prevents.

### Path 4, where the reader will not answer

`Subscription` is partly a billing API. 
`BadgeModel` is a catalogue API plus an optional local row.
`Reputation` is two APIs and no table at all.

Unless these API gives us the mean to, there is no collection to filter, and no amount of design makes one. 
Also filter that fans out to N provider calls is not a filter per se. So when path 4 needs a list, it
gets a projection, built the reader feeding a local table, on an event or on a schedule, so the list query never touches the provider.

Between those two, the schedule is usually the cheaper answer and it is the one this project takes.
Feeding on an event means the aggregate has to publish some, and `Subscription` is ADR 003's option A
aggregate with its handlers on the class itself: making it record events would widen the Domain's
Ecotone allow-list a second time, and paying that for a read model is a bad trade. The schedule costs
one provider call per row and a `refreshedAt` column, and the projection is then exactly as fresh as
the last run.

Do not read that staleness as a defect to be engineered away. It is what "the data does not all live
in one database" means the moment somebody asks for a list, and the batch section above already says
what to do about it: the read model selects candidates, the aggregate decides.

This approach is not a workaround, it is the same idea ADR 001 gives for reading across bounded contexts.
An external provider is a context whose events we do not own; the local read model is how we read from it without coupling to it.

### Path 5, the projection's lifecycle

The outbox and the saga are not filtering mechanisms. They are how a projection stays correct when
it lives in a store the writing operation could not reach transactionally, and how it is torn down.

Even so, because these mechanisms are responsible for the projection (un)staleness,
it could more or less be considered as part of the filtering story, even though no filter appears anywhere in there.

## When the filter that belongs to the aggregate

In the project `ListBadgeCollection` carries a boolean named `displayableOnly`, and `BadgeCollectionService::list()` uses it in one line:

```php
return $query->displayableOnly ? $member->displayable() : array_values($member->badges()->toArray());
```

`Member::displayable()` means "not lost, and in a presentable condition". That is a rule, not a predicate. It
depends on the owner, the condition can be met or not, and it may change someday.

If it were for a filter specification, it would have to be expressed once per store that ever holds badges, and
those expressions would probably be forgotten and introduce regressions.
While a named method on the aggregate won't because there is only one of it.

So if you need to ask yourself should it be a domain method, ask your yourself this:
Would you want every store to be adjusted the day the rule changes? Then it is domain behaviour,
and it is a method. Is it "the user selected striped badges"? Then it is a read-model parameter.

And note that this `Member::displayable()` example is one where in-memory filtering is actually right, on an already
loaded aggregate, inside a command flow or a small read. It is the exception for the "90% of time store-first"
rule leaves room for.

## Expressing a filter

A filter that has to work from API Platform, a CLI command and an HTML form has four jobs:

1. **declare** what can be asked, once, somewhere all three deliveries can point at;
2. **generate a surface** per delivery: OpenAPI `QueryParameter`s, `InputOption`s with the right
   mode, form fields with types and choices;
3. **parse and fail correctly** per delivery. A query string is all strings; a CLI option is a
   string, a bool or an array; a form hands you typed data. And the failure is a 400 here, an exit
   code 22 there;
4. **interpret** it against a store: DQL, SQL, somebody's query parameters, a closure.

Let me present three designs, in increasing order of difficulty.

### Option A, the query object is the filter

One class per read question, and its constructor signature *is* the filter vocabulary. The project
already has one:

```php
final readonly class ListCandidates
{
    public function __construct(
        public string $memberId,
        public ?string $color = null,
        public ?string $motif = null,
        public int $limit = 20,
    ) {
    }
}
```

Each delivery builds one. API Platform declares its `QueryParameter`s on the operation and a
provider maps `$context['filters']` into the DTO. A console command declares its `InputOption`s and
maps `$input` into the same DTO. An HTML controller maps the request, through a form or by hand,
into the same DTO. The read adapter interprets, and for example `DoctrineCandidateFeed` already does it by hand,
with `if (null !== $color)`.

The downside is that declaration needs to be done for each delivery instead of once, so they can drift from each other. 
Adding a filter to a list that has three deliveries touches five files. There is no reuse of "a boolean filter" across
endpoints.

On the other hand, PHPStan can check the whole path, from the provider to the adapter. The supported set
of filters for a given list is enumerable by reading one constructor. Nothing generic exists, so
nothing generic can be abused, and no filter can be expressed that its store cannot answer, because
the store's adapter is the thing that declared it possible.

### Option B, a declared, named, typed parameter set

The declaration becomes an object of its own, living next to the read port in
`Application/ReadModel/` for the same reason `CandidateFeedInterface` lives there:

```php
final class CandidateFilters
{
    public static function declaration(): FilterSet
    {
        return FilterSet::of(
            Filter::enum('color', Color::class),
            Filter::enum('motif', Motif::class),
            Filter::bool('excludeLiked'),
        );
    }
}
```

Each delivery gets its own surface out of that declaration, rather than restating it: query parameters
and their OpenAPI schema for API Platform, `InputOption`s for the console, field definitions for a
form.

Note which way round that sentence has to be built, because the obvious reading does not survive
contact. The declaration must not *produce* the surfaces. A `FilterSet::asQueryParameters()` would
mean importing `ApiPlatform` into the shared namespace the declaration lives in, and a declaration
that knows about one delivery has stopped being delivery-agnostic, which was the entire point. So
each delivery *reads* the declaration and builds its own surface. In this project that is
`Shared/Infrastructure/ApiPlatform/Metadata/DeclaredFiltersFactory` on one side and
`Ui/Console/Query/FilterSetOptions` on the other, and the declaration has never heard of either.

Parsing would runs through `Shared/Domain/Validation`, **where
the "400 or exit 22" is known**: one parser, three error renderers, and a filter value that is valid at all times once built.
Interpretation stays per adapter.

The downside, is the implementation itself: a `Filter`, a `FilterSet`, three surface generators and one
parser. Plus the discipline to never add `Filter::expression()`, because the day that exists this
design has become option C (see after) without anyone deciding to.

On the other hand, adding a filter touches the declaration, the read port and the adapter, and not one
delivery: the query parameter, its OpenAPI schema, the console option, its `--help` line and the
parse all appear on their own. Saying "one file" would be overselling it, because a question no
store can answer is not a feature; what option B removes is the restating, not the answering. The
failure rule is enforced in one place instead of three. We need a validation module (we'll come to
that). And a filter declared but not interpretable by a given read model is a wiring error you can
detect at boot, rather than a 500 on a random tuesday after a deployment.

### Option C, our own "AST" with an interpreter per store

A composable feature. 
An expression tree in the shared kernel, and a visitor per backend:

```php
$spec = and(eq('color', $color), not(exists('like', $self)));
```

plus a `DoctrineInterpreter`, an `HttpInterpreter`, an `InMemoryInterpreter`.

**Short answer: don't do that, I did that mistake.**

An AST is a promise that any expression can be answered by any store. The five paths are a
demonstration that they cannot: `NOT EXISTS` is a subquery on a projection, rarely exist for an
HTTP gateway, and is a nested loop in memory. Every interpreter must implement every node or throw
at runtime, so the abstraction kind of fails at the one job it was built for, and it fails late. The node
set only ever grows.

### What I would do?

The truth is I love the first option, for it is straightforward.
And I like the option B because it brings a small amount of coherence without being a huge machinery either.

Start at A. For example `ListCandidates` already is A, it costs nothing, and for a list with one boolean and one
delivery it is the right amount of design. Reach for B when a list carries enough filters, or enough
deliveries, that writing the surface three times starts being tedious to maintain.

I would try to avoid C explicitly, because "we could just build a small query language" is the idea that will come back every six months.
Fight the urge of trying to be clever.

Same closing advice as ADR 003: do not pick one for the whole project. 
A read model with two optional parameters and an API-only surface has no use for a filter declaration.

## Interpreting a filter, per store

**A Doctrine read adapter** (paths 1, 2, 3) writes DQL or builds a `Criteria`. The one
thing it must not do is trust parameter type inference: bind `Id` with `IdType::NAME` and bind pure
enums explicitly, or the comparison could silently match nothing. `DoctrineCandidateFeed`'s comment on
its `!=` is the exact example.

**A DBAL adapter** is the right level when the read shape is rows and the destination is a DTO.
Hydrating entities in order to take them apart again is working backwards, and on path 4's
projections there is no entity to hydrate anyway. 

If the table has an XML mapping kept purely as schema metadata,
a thin layer reading that metadata for table and column names, such as ESQL, keeps a renamed column from silently breaking a reader.

**An HTTP adapter** gets whatever query parameters the provider offers and nothing else. A gateway that
receives a filter it cannot express should fail at wiring time, not silently return an
unfiltered page.

**In memory** means a method on an aggregate, per the section above. If you find yourself writing a
generic in-memory interpreter, the list you are filtering is either too big to be in memory or too
meaningful to be filtered generically.

## Pagination

### Filtering and pagination are a pair

They arrive together and they must stay together. A filter changes the count, the count changes the
last page, and a total computed over the unfiltered set is just wrong.

Which is the first decision, and it is not really about pagination at all: **a page request is an
exposed parameter like any other.** `page` and `itemsPerPage` arrive in the same query string as
`color`, in the same `InputOption` list as `--color`, and in the same form. They are declared next to
the filters, parsed by the same parser, and they fail the same way: `page=0` is a 400 here and an
exit 22 there, never a silent fallback to page 1.

So there is no separate pagination pipeline, no second context key, and nothing that has to be
plumbed through the provider alongside the filters. In option A the query object carries them:

```php
final readonly class ListCandidates
{
    public function __construct(
        public string $memberId,
        public ?string $color = null,
        public ?string $motif = null,
        public Slice $window = new Slice(after: null, size: 20),
    ) {
    }
}
```

and in option B the declaration carries them, so one `FilterSet` generates the `page` query
parameter and the `--page` option along with everything else, and one `process($onValid, $onErrors)`
validates both halves at once:

```php
FilterSet::of(
    Filter::enum('color', Color::class),
    Filter::enum('motif', Motif::class),
    Window::slice(defaultSize: 20, maxSize: 100),   // or Window::pages(...)
);
```

The page request travels with the query because it *is* part of the query. What must not split is
the read port: it takes the filter values and the window in the same call, and what it returns
carries the items and the position together.

### There is no single pagination contract, for the same reason there is no filter AST

The tempting shortcut is to take the delivery framework's pagination contract, copy it inward, and
call it the application's vocabulary. API Platform has `PaginatorInterface` and
`PartialPaginatorInterface`, they are small, they already express current page, items per page, last
page and total, and re-declaring them under our own namespace looks like free design.

Those interfaces are shaped by what a JSON-LD `hydra:view` needs to emit, not by what a store can answer, 
and their types say so: `getTotalItems()` returns `float` because that interface wants to allow `INF` 
for "I do not know the total". Copying it inward imports the type that exists for the unknown-total case 
into a design where every read model is then obliged to produce a total.

The other part of the problem is an `offset()` on a shared contract. An offset is
`(page - 1) * size`, which is `OFFSET`/`LIMIT`: one mechanism, from one family of stores, promoted to
the vocabulary every read model must speak. A read model paginating by keyset has no offset, and one
reading a store that paginates for you has no page number to compute one from.

So the rule from the filter applies unchanged:

> **The port declares which pagination it offers. There is no shared interface promising both.**

A caller that asks for page 7 of an infinite-scroll feed should get an error, not an empty result.

### What the read side actually has to paginate

Before choosing mechanisms.

Every list is served by a read model, and every read model is one we own: the write tables on paths 1
and 2, the shadow rows on stage 3, our projection on path 3, and, on path 4, a projection we decided
to build precisely because the provider will not answer a list.

So a provider's own cursor never reaches a read port. Walking somebody's `next_page_token` to refresh
a projection is a **feeding** concern, living in the projector next to the gateway, and the loop that
drains it is not pagination in the sense this section means. What the read side paginates is always
our own SQL.

That reduces the number of problems we may have. Nos we get to chose between two mechanisms,
both over stores we control.

### Two mechanisms, and the port says which

|                 | offset                                    | keyset (cursor)                            |
|-----------------|-------------------------------------------|--------------------------------------------|
| the ask         | "rows 40 to 59 of the ordered set"        | "the 20 rows after this one"                |
| gives you       | page numbers, a last page, jump to page 7 | stability under concurrent writes           |
| cannot give you | stability                                 | a page number, a last page, a jump          |
| cost at depth   | grows: `OFFSET 100000` scans and discards | constant                                    |
| needs           | a total, to know the last page            | a *total* order, unique to the last column  |

Neither is better. They answer different screens, and the screen is known (hopefuly before) when the port is written.

The discovery feed is the case worth being concrete about, because offset would be the wrong mechanism for
it. `MemberFeedEntry` is ordered by `updatedAt DESC`, and every `MemberProfileChanged`
rewrites an `updatedAt`. So between the reader's page 1 and page 2, rows move; offset pagination then
shows duplicates and silently skips others, and nothing errors. And `updatedAt` is not unique, so even
the keyset version has to order and seek on `(updatedAt, memberId)` or it drops rows sharing a
timestamp.

Concretely, two request shapes and two result shapes, rather than one:

```php
// what the caller asks for, carried inside the query
final readonly class Page  { public int $number;   public int $size; }
final readonly class Slice { public ?string $after; public int $size; }

// what the port hands back
/** @template T */ final readonly class PageOf  { public array $items; public int $number; public int $size; public ?int $total; }
/** @template T */ final readonly class SliceOf { public array $items; public int $size; public ?string $next; }
```

`CandidateFeedInterface` takes a `Slice` and returns a `SliceOf<Candidate>`. A badge catalogue takes a
`Page` and returns a `PageOf<BadgeRow>`. Nothing implements both, and no caller can ask a feed for its
last page.

Where that lands at the HTTP boundary is worth knowing before you write the first endpoint, because
API Platform has a shape for one of these mechanisms and none for the other.

Offset is well served, in both counting modes. A provider returning a `PaginatorInterface` gets
`hydra:totalItems`, `first`, `last`, `previous` and `next`; one returning only
`PartialPaginatorInterface` gets a `hydra:view` with `previous` and `next` and no total at all, which
is the cheap mode, and it costs nothing to ask for. Its one limitation is that the `next` is
*inferred* from "the page came back full", so a page that is exactly full and also last still
advertises a next link. Our own port knows better, having asked for `size + 1` rows, and there is no
method on that interface to say so.

Keyset has no shape. `paginationViaCursor` looks like it and is not: it takes one field, coerces
values through `(float)`, and rebuilds the cursor out of URL parameters, so it can express neither a
seek on two columns nor a ULID. Implementing `PartialPaginatorInterface` instead would be worse,
because its `next` is literally `page + 1` and advertising that on a feed hands callers a page number
this port refuses to honour. So a keyset endpoint is modelled as what it is: one resource whose
representation carries its items and an opaque `next`, and no `hydra:view` at all.

By this document's own layering, these belong with the read vocabulary they appear next to, in a
shared Application namespace: a page is a delivery constraint, not a domain rule, and no aggregate has
ever had an opinion about page size. That is now `App\Shared\Application\ReadModel`, and the wiring it
needed was smaller than expected: a `phpat` rule joining the already-registered `SharedArchitectureTest`
rather than a new entry in `phpstan.dist.neon`, plus an exclusion in `config/services.php` so the
container does not try to autowire a page request.

The cursor format is the one piece that does *not* live there. Only the adapter that wrote the
`ORDER BY` knows how many columns a token encodes or what they mean, so `Cursor` sits in
Infrastructure and everything above it carries an opaque string it never opens. That is what lets a
feed change its ordering key without a delivery noticing.

### The total is another thing on its own

`?int $total` is nullable on purpose. Counting is not part of paginating; it is a separate question
whose answer is often the most expensive part of the request, and it has three answers:

- **exact**, for "page 3 of 42". A `COUNT` over the filtered set, and on a join-heavy query with
  `fetchJoinCollection: true` that is a subquery through an output walker, which can cost more than
  the page itself;
- **none**, for "next". Ask the store for `size + 1` rows, return `size` of them, and the one you
  dropped is `hasNext`. It costs nothing, and it is what an infinite scroll actually needs, or a fake pagination;
- **estimated**, for "about 4,000 results". `reltuples` on Postgres, or a cached count.

Counting eagerly, on every page, because the return type has a non-nullable total on it, is the
expensive answer chosen by accident. Nothing in the delivery layer demands it either: a provider that
implements only API Platform's `PartialPaginatorInterface` gets `hydra:view` with `first`, `next` and
`previous` from `PartialCollectionViewNormalizer`, while `CollectionNormalizer` omits
`hydra:totalItems` on its own. The cheap mode is available; it just has to be asked for.

## Usage

### The read port names the question

```php
// src/Matching/Application/ReadModel/CandidateFeedInterface.php

interface CandidateFeedInterface
{
    /**
     * @param Id<Member> $forMemberId excluded from its own feed
     *
     * @return SliceOf<Candidate>
     */
    public function candidatesFor(Id $forMemberId, ?string $color, ?string $motif, Slice $window): SliceOf;
}
```

Typed values, a named question, no filter object, no `Criteria`, and no way for a caller to ask
something the projection cannot answer. The window is a `Slice` rather than a `Page` because this is
a feed, and the signature is where that is decided: nobody can ask this port for page 7, or for a
total it would have to count.

### The query handler carries values, and decides nothing

```php
// src/Matching/Application/DiscoveryService.php

#[QueryHandler(self::LIST_CANDIDATES)]
public function candidates(ListCandidates $query): SliceOf
{
    /** @var Id<Member> $memberId */
    $memberId = Id::fromString($query->memberId);

    $window = new Slice($query->window->after, min(max(1, $query->window->size), 100));

    return $this->feed->candidatesFor($memberId, $query->color, $query->motif, $window);
}
```

No repository, no aggregate, no transaction. The handler clamps a page size, which is a policy, and
forwards. It could not accidentally load a `Member` and mutate it if it tried, because the manager
behind the feed does not know that class exists.

That clamp is also the one thing option B would take off its hands: a `Window::slice(defaultSize: 20,
maxSize: 100)` in the declaration is the same rule, applied at parse time, for every delivery at once.

### The adapter interprets, and is the only one that knows the store

```php
// src/Matching/Infrastructure/ReadModel/DoctrineCandidateFeed.php

$dql = 'SELECT f FROM '.MemberFeedEntry::class.' f WHERE f.active = true AND f.memberId != :self';

if (null !== $color) {
    $dql .= ' AND f.color = :color';
}

// The keyset seek. Ordering on updatedAt alone would drop rows sharing a timestamp,
// so the cursor carries both columns and the comparison is over the pair.
if (null !== $window->after) {
    [$afterUpdatedAt, $afterMemberId] = Cursor::decode($window->after);
    $dql .= ' AND (f.updatedAt < :afterUpdatedAt OR (f.updatedAt = :afterUpdatedAt AND f.memberId < :afterMemberId))';
}

$query = $this->manager()
    ->createQuery($dql.' ORDER BY f.updatedAt DESC, f.memberId DESC')
    ->setParameter('self', $forMemberId, IdType::NAME)   // explicit, or it matches nothing
    ->setMaxResults($window->size + 1);                  // the extra row is hasNext, and costs nothing
```

That is option A in full. Three deliveries can call it; each one builds a `ListCandidates`, and that
is the entire contract between them. The store, the seek and the extra row stay in this class, which
is the only one entitled to know any of it.

### What option B adds, when a list earns it

The delivery stops restating the declaration:

```php
// API Platform: the resource names the declaration, and a metadata factory turns it into
// QueryParameters when the metadata is built.
#[GetCollection(uriTemplate: '/members', provider: MemberCollectionProvider::class)]
#[DeclaredFilters(MemberDirectoryFilters::class)]

// Console: the same declaration, a different surface
protected function configure(): void
{
    FilterSetOptions::applyTo($this, MemberDirectoryFilters::declaration());
}
```

The indirection on the API side is not a style choice. PHP attribute arguments must be constant
expressions, so `parameters: MemberDirectoryFilters::declaration()->asQueryParameters()` does not
compile; a class name is a constant expression, and a `ResourceMetadataCollectionFactoryInterface`
decorator is where the call can actually be made. It lands in the right place anyway, because
generating an OpenAPI parameter is delivery work.

One detail that decides whether rule 11 holds at all: those generated parameters carry
`constraints: []`. Leave it out and API Platform derives its own assertions from the schema, `enum`
becoming a `Choice` and `minimum` a `Range`, and validates them before the provider is ever called;
the answer is then a 422. It only covers the keywords it knows, so one endpoint ends up answering
422 for a bad enum and 400 for a bad boolean, and which one you get is decided by which JSON Schema
keyword a parameter happened to use rather than by anything about the mistake. `constraints: []`
says there are none to derive, the schema stays for the documentation, and every bad value ends up
at the same exception.

and the parse becomes one call with two renderers:

```php
CandidateFilters::declaration()
    ->parse($rawInput)
    ->process(
        onValid: fn (FilterValues $values): array => $this->ask($values),
        onErrors: fn (ValidationResult $errors) => throw BadFilterInput::from($errors), // 400, or exit 22
    );
```

`BadFilterInput` implements `BadRequestThrowable`, so the HTTP status comes from the
`exception_to_status` block in `config/packages/api_platform.php` rather than from the domain
throwing an HTTP exception. A console command catches the same exception and returns 22.

Note that neither snippet mentions a store, and the adapter from the previous section does not
change at all. That is the layer isolation rule, holding.

## The rules this obeys

1. **A repository answers by identity. A read model answers by criteria.** Never the reverse, on any
   of the five paths.
2. **Every filtered list is served by a read model**, whichever path stores the aggregate. On paths 1
   and 2 that read model may be a query over the write tables; on 3 it is a projection; on 4 you
   build one, because the reader will not answer.
3. **A write that starts from a filter is two steps, never one.** Filter to identifiers on the read
   side, then one command per aggregate, one transaction each. The only alternative is a set-based
   write that carries no rule, in Infrastructure, labelled as one.
4. **The read model selects candidates, the aggregate decides.** A selection is stale the moment it
   is returned, so the per-item command re-reads its boundary and the aggregate is allowed to refuse.
5. **A declaration is delivery-agnostic. A surface, a parse failure and an interpretation are not.**
   Anything that travels from a delivery to a store carries values, never query fragments.
6. **A selection that carries a rule is a method on the aggregate**, not a filter.
7. **Filtering and pagination are a pair**, and a total always counts the filtered set.
8. **The port declares which pagination it offers**, offset or keyset, and nothing implements both.
   An offset is how one adapter writes SQL, not a method on a shared contract.
9. **A total is optional and separate.** Exact, estimated, or none. `size + 1` rows answer "is there
   a next page" for free, and a page without a total is a shape the delivery layer already supports.
10. **Do not build a filter AST.** Not ours, and not Doctrine's. The five paths do not have equal
    expressivity, which is the assumption an AST needs and cannot have here.
11. **A failure to convert exposed arguments into filter values or a page request MUST result in a
    400 HTTP status or an exit code 22.** Never a silent default, and never a second answer for the
    same kind of mistake: a delivery framework that offers to validate exposed parameters for you
    will answer with its own status for the subset it understands, which is how one endpoint ends up
    using two.

## If you are adding a filter

The order of the questions is the order of this document:

1. Is the answer a rule that belongs to the aggregate? Then it is a method on it, and you are done.
   `Member::displayable()` is the example.
2. Is the filter serving a *write*? Then it returns identifiers, and the write is one command per
   identifier, one transaction each. Re-check the precondition inside the aggregate, because the
   selection is already stale by the time the command runs. If the change carries no rule at all, it
   is a set-based write in Infrastructure and not a filter question.
3. Which read model answers it? If none exists, which of ADR 003's five paths feeds one?
   [`docs/storage-map.md`](../storage-map.md) says where the data is; the table above says what that
   implies for reading it.
4. Can that read model answer it today? On path 3 the honest answer is often "after I add a column",
   and adding the column is the decision, not a detail.
5. Which pagination does that screen need? Page numbers and a jump mean offset; a stable infinite
   scroll means keyset, and the ordering key has to end on something unique. The port takes one of
   the two request shapes and says so in its signature.
6. Does it need a total at all? Exact costs a `COUNT` over the filtered set; `size + 1` rows answer
   "is there more" for free; estimated is a third option. Decide it, rather than paying for exact by
   default.
7. One delivery, or three? One, and the query DTO is the whole design (option A). Three, and the
   declaration starts paying for itself (option B).
8. Whatever you do, do not reach for an expression tree (option C). If a store cannot answer the
   question, the answer is a different read model, not a richer filter language.
