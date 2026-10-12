---
name: reading-and-filtering
description: Add or change anything in this DDD/CQS tree that returns a list, filters, sorts, paginates or counts, following ADR 004 - read models and ports in Application/ReadModel, Page/Slice/PageOf/SliceOf, offset versus keyset, optional totals, the two filter designs, and the rule that a parse failure is a 400 or exit code 22. Use before adding a query parameter, a collection endpoint, a console list command, a projection column, or a batch write that starts from a filter.
---

# Reading, filtering, paginating

The argument is `docs/ADR/004_filter.md`. `php bin/console app:reads` walks every
read model once and reports which one answered, which pagination its port offers
and whether anybody paid for a total. `--keep` leaves the data behind.

## The one rule everything else follows from

> **A repository answers "give me this one aggregate". A read model answers
> "give me the rows that match".** Filtering belongs to the second, always, on
> all five of ADR 003's storage paths.

A `Domain/Repository/` interface declaring `findBy(criteria)` is a promise at
least two of the five implementations cannot keep, on an interface whose whole
point is that the Application layer cannot tell which one it got. An interface
that has to be implemented by throwing has the wrong method on it.

So: **every list goes through a port in a context's `Application/ReadModel/`,
returning DTOs**, never through `Domain/Repository/` and never returning an
aggregate. A list endpoint that can hand back a Doctrine-managed aggregate is a
list endpoint that eventually writes.

## Ask these in order

1. **Is it a rule that belongs to the aggregate?** Then it is a method on it and
   you are done. `Member::displayable()` means "not lost, and presentable"; a
   specification would have to be re-expressed once per store that ever holds
   badges. Test: would you want every store adjusted the day the rule changes?
   Then it is domain behaviour. Is it "the user selected striped badges"? Then it
   is a read-model parameter.
2. **Is the filter serving a write?** Then it returns *identifiers*, and the
   write is one command per identifier, asynchronous, one transaction each.
   Never load-the-set-and-iterate: that puts N consistency boundaries in one
   transaction whose size the data decides. `SubscriptionMaintenanceService` is
   the worked example and the only `#[Asynchronous]` handler in the tree.
   The selection is stale by construction, so **the read model selects
   candidates, the aggregate decides** and is allowed to refuse. A change that
   carries no rule at all is a set-based `UPDATE ... WHERE` in Infrastructure,
   labelled as one, and only available on paths 1 to 3.
3. **Which read model answers it?** If none exists, which ADR 003 path feeds
   one? `docs/storage-map.md` says where the data is.
4. **Can it answer today?** On a projection the honest answer is often "after I
   add a column", and **adding the column is the decision, not a detail**. On
   path 4 there is no collection to filter at all: the provider has no list
   verb, so you build a projection fed on a schedule (`app:subscriptions:project`)
   and the list query never touches the provider.
5. **Which pagination does the screen need?** Page numbers and a jump mean
   offset. A stable infinite scroll means keyset, and the ordering key has to
   end on something unique.
6. **Does it need a total at all?** Decide it rather than paying for exact by
   default.
7. **One delivery or three?** One, and the query DTO is the whole design
   (option A). Three, and the declaration starts paying for itself (option B).
8. **Do not reach for an expression tree** (option C). If a store cannot answer
   the question, the answer is a different read model, not a richer filter
   language.

## The shared vocabulary

`Shared/Application/ReadModel/`, and it is deliberately small:

```php
final readonly class Page  { public int $number;   public int $size; }   // asked
final readonly class Slice { public ?string $after; public int $size; }  // asked
/** @template T */ final readonly class PageOf  { array $items; int $number; int $size; ?int $total; }
/** @template T */ final readonly class SliceOf { array $items; int $size; ?string $next; }
```

- **The port declares which pagination it offers, in its signature, and nothing
  implements both.** A caller asking a feed for page 7 should get an error, not
  an empty result. There is no shared interface promising both and no `offset()`
  on a contract: an offset is how one adapter writes SQL.
- **The total is optional and separate.** Exact costs a `COUNT` over the
  *filtered* set. `size + 1` rows answer "is there a next page" for free and are
  what an infinite scroll needs. Estimated is a third answer. `?int $total` is
  nullable on purpose.
- **`Cursor` is Infrastructure**, in `Shared/Infrastructure/ReadModel/`, opened
  only by the adapter that minted it. Everything above carries an opaque string.
- Do not copy API Platform's `PaginatorInterface` inward. It returns `float`
  from `getTotalItems()` to allow `INF`; it is shaped by what `hydra:view` emits,
  not by what a store can answer. Adapt it *outward*, in
  `Shared/Infrastructure/ApiPlatform/State/Pagination/`.

## The three layers, and what each may know

- **UI** declares what can be asked and parses it. Only it distinguishes a query
  string from an `InputOption` from a form field, and only it knows whether a
  parse failure is a status or an exit code.
- **Application** declares the questions and carries typed values. It must not
  be able to find out how they will be answered.
- **Infrastructure** interprets: DQL, SQL, somebody's query parameters, a
  closure.
- **Domain is absent on purpose.** A filter a user typed is a question about a
  screen.

You must be able to add a CLI option without touching the query, and change the
store behind a read model without touching any delivery.

## Filtering and pagination are a pair

A page request is **an exposed parameter like any other**: `page` arrives in the
same query string as `color`, is parsed by the same parser, and fails the same
way. It travels *inside* the query object. There is no second pipeline and no
second context key.

> **A failure to convert an exposed argument into a filter value or a page
> request MUST be a 400 or exit code 22.** Never a silent default, never a
> `WHERE 1=1`, and never a second answer for the same kind of mistake.

`Shared/Application/ReadModel/BadFilterInput` implements `BadRequestThrowable`,
so the status comes from `exception_to_status` in
`config/packages/api_platform.php`. Every console query command catches it and
returns 22.

## The two designs, both shipped

**Option A, the query object is the filter.** One class per read question whose
constructor *is* the vocabulary (`Matching/Application/Query/ListCandidates`).
Each delivery builds one. PHPStan checks the whole path; the supported set is
one constructor. Right when there is one delivery and two parameters.

**Option B, one declaration, every surface.** `MemberDirectoryFilters` next to
its port, read by `Shared/Infrastructure/ApiPlatform/Metadata/DeclaredFiltersFactory`
on one side and `Ui/Console/Query/FilterSetOptions` on the other. **The
declaration must not produce the surfaces** - each delivery reads it and builds
its own, or the declaration has stopped being delivery-agnostic. The indirection
through a class name is forced: attribute arguments must be constant
expressions.

Two traps in option B:

- **generated `QueryParameter`s carry `constraints: []` deliberately.** Leave it
  out and API Platform derives assertions from the schema and answers 422 for
  the keywords it understands, so one endpoint answers 422 for a bad enum and
  400 for a bad boolean. `constraints: []` sends every bad value to the same
  exception.
- never add `Filter::expression()`. The day it exists, option B has silently
  become option C.

**There is no shared filter abstraction, deliberately.** No `Criteria`
travelling from UI to store, no expression tree, no interface promising both
mechanisms. The five paths do not have equal expressivity, which is the
assumption an AST needs and cannot have here. Read the ADR before adding one
back; it was tried and removed (`e549ba2`/`d672b24` are history, not a template).

## Interpreting, per store

- **Doctrine adapter** (paths 1 to 3): do not trust parameter type inference.
  Bind `Id` with `IdType::NAME` and bind pure enums explicitly, or the
  comparison silently matches nothing. `DoctrineCandidateFeed`'s comment on its
  `!=` is the example.
- **DBAL adapter**: right when the shape is rows and the destination is a DTO.
  Hydrating entities to take them apart again is working backwards.
- **HTTP adapter**: gets the query parameters the provider offers and nothing
  else. A gateway handed a filter it cannot express should fail at wiring time,
  not silently return an unfiltered page.
- **In memory**: only a method on an already-loaded aggregate, or a set you know
  is small and will stay small.

## At the HTTP edge

`doctrine: false` is set, so **every collection operation needs a hand-written
provider** in `src/Ui/Api/StateProvider/`. `UiArchitectureTest` forbids `Ui` from
naming any context's `Infrastructure`, so a provider reaches a read model only
through the query bus.

Offset is well served by API Platform in both counting modes;
`PartialPaginatorInterface` gives a `hydra:view` with no total, which is the
cheap mode and costs nothing to ask for. **Keyset has no shape there**:
`paginationViaCursor` coerces through `(float)` and cannot express two columns
or a ULID, and `PartialPaginatorInterface`'s `next` is literally `page + 1`. So
a keyset endpoint is one resource whose representation carries its items and an
opaque `next`, with no `hydra:view`.

Collection operations set `paginationEnabled: false`. That does **not** disable
pagination: the hydra view comes from the paginator the provider returns. It
only stops `OpenApiFactory` inventing a `page` parameter documented as "The
collection page number" that wins the merge against the accurate declared ones.

A response property typed as a collection of an Application DTO
(`list<Candidate>`) cannot be resolved by the schema factory and degrades to
`array<string|null>`, so the cursor feeds spell `members` out in
`#[ApiProperty(openapiContext:)]`.

`Shared/Infrastructure/ApiPlatform/OpenApi/BadFilterInputResponses` adds the 400 to
every operation taking a query parameter, once, because that is a fact about the
whole API.

## Scoping is not filtering

"My likes" comes from the principal. **A scoping value is never an exposed
parameter**: not a `QueryParameter`, not an `InputOption`, not a form field, and
option B's declaration has to distinguish the two so the factory skips it.
Scope in the query, never `array_filter` after the rows came back. See the
`guarding-an-operation` skill and ADR 008 rule 12.
