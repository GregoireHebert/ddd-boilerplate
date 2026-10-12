---
name: writing-an-adr
description: Write or amend an architecture decision record in docs/ADR/ for this DDD/CQS teaching repository - the house structure (flow, architecture, the options, the numbered rules this obeys, the if-you-are-adding checklist, what this ADR still owes), the prose conventions including no em dashes, and the standing rule that the ADRs lead and the code follows. Use when asked to write, extend, amend or reopen an ADR, or when a task turns out to need a decision nobody has taken.
---

# Writing an ADR

`docs/ADR/` is the reasoning of this repository. **The ADRs lead, the code
follows.** Every class in the tree exists to demonstrate a decision one of these
documents takes; none of it is evidence for one. When an ADR moves, the code
owes it a commit, and **when the code cannot follow, that is a finding worth
reopening the earlier ADR over** (ADR 005 is that, about the deleted functional
validator).

## Prose conventions

- **No em dashes and no en dashes.** Use a semicolon, a comma or a full stop.
  Every document under `docs/` currently holds zero of them, and the guard hook
  will say so.
- First person singular, present tense, and willing to say "I did that mistake"
  or "as tempting as it is I probably say No". These documents argue; they do
  not announce.
- Bold the sentence that is the rule, so a reader skimming finds the load-
  bearing line. Most sections have exactly one.
- Tables for anything with more than two axes; ASCII diagrams for a flow or a
  decision tree. Both are used heavily and both are fenced.
- Name real classes with real paths. A rule with no example in the tree is a
  rule nobody can check.
- Cite the vendor file and version when an argument turns on library behaviour
  (`ORM 3.6.8, Persisters/Entity/EntityPersister.php`), because the next reader
  will want to re-check it.
- Link sideways: `[ADR 003](003_store-the-aggregate.md)`,
  `[`docs/storage-map.md`](../storage-map.md)`,
  `matching/07`.

## The shape

Numbered `NNN_kebab-case-title.md`, and the headings are near enough fixed that
two ADRs can be read side by side:

```markdown
# Title

One or two paragraphs naming the problem, usually by splitting a word
everybody thinks means one thing into the two or four things it means.

If you would rather poke at it than read about it:      <- optional but common
```shell
bin/console app:something
curl -s localhost:8000/...
```

## Flow                  <- the happy path in eight short paragraphs, no code
## Architecture          <- the reasoning, in subsections, one rule each
### <the line that decides everything>
## <the mechanism>       <- how it actually works here
### Option A, ...        <- the designs considered
### Option B, ...
### Option C, ...        <- often "don't do that, I did that mistake"
### What I would do
## What the designs actually produced   <- when both shipped: measured, not predicted
## The rules this obeys  <- NUMBERED. This is the section the rest cites.
## If you are adding ... <- the checklist, in the order of the document
## What this ADR still owes   <- named here rather than discovered later
```

Two of those sections carry most of the weight.

**"The rules this obeys"** is numbered because everything else refers to it:
CLAUDE.md, the other ADRs and the skills all cite
"ADR 004 rule 11" and "ADR 005 lock 9". **Renumbering breaks those citations**,
so append rather than renumber, and if a rule genuinely dies, say so in its
place.

**"What this ADR still owes"** is the honesty section. List what did not get
built, what is deliberately left open and why, and what is known debt the ADR
itself created. ADR 005 naming `Like`'s duplicated guard as "debt this ADR
creates and names" is the model.

## What makes one of these good here

- **Split the word first.** ADR 007 opens by saying translation is two problems
  that share nothing except the word; ADR 008 opens on four questions. The
  split is the contribution; the rest is consequence.
- **Say what the default does wrong.** Every rule in the OpenAPI section exists
  because API Platform's default published something untrue. A rule with no
  failure behind it is a preference.
- **Show the option you rejected, and why it was tempting.** Option C in ADR 004
  is an expression tree, and the argument against it is that it is the idea that
  will come back every six months.
- **Prefer a measured result to a predicted one.** Once both designs ship,
  replace the prediction with what actually differed. ADR 005's "five
  differences survived, and only the first was expected" is worth more than the
  original guess.
- **Record the bug that taught the rule.** *« banana » n'est pas an identifier*
  is why "a parameter is a value, never a fragment" is a rule and not a
  suggestion, and it is quoted in the ADR.
- **Prefer an enumerable set to a clever parser.** It is the same argument three
  times: `FailureCode` cases over an AST extractor, `Permission` cases over an
  expression string, one class per RPC method over routing onto the bus.
  Completeness is checkable only if something can walk the set.
- **Scope the advice.** "A single-delivery application should use `security:`
  expressions and stop here." An ADR that does not say when it is overkill will
  be applied where it is.

## Housekeeping when an ADR lands

- Does CLAUDE.md need a paragraph? It is the always-loaded index and it
  currently summarises every ADR.
- Does `docs/storage-map.md` owe a line? Only if the answer to "how is this
  stored" changed.
- Is there a worked example? **A rule with no example in the tree is a rule nobody
  can check**, and in a boilerplate that is a real hazard: it is easy to write a
  rule here that nothing can ever contradict. Say plainly when an example comes
  from the reference application rather than from this repository.
- Do the phpat rule sets owe a rule? The last three ADRs each added a narrowing.
- Does a `.claude/skills/` skill owe an edit? They restate these rules for the
  agent, and a skill that has drifted from its ADR is worse than no skill.

## Amending rather than adding

An ADR may amend an earlier one, and this happens: ADR 007 moved `code()` off
`UnprocessablePayloadThrowable` onto a new `TranslatableThrowable`, and ADR 008
amended ADR 006's "everything is a 200" by exactly one case. **Say which rule of
which ADR you are amending, in those words, and keep the amendment narrow.** Do
not silently edit the older document's rule out from under its citations.
