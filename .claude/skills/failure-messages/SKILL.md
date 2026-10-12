---
name: failure-messages
description: Write or change any message a caller could read in this DDD/CQS tree, following ADR 007 - a failure carries a declared code and a named parameter map and never a sentence, codes are enum cases in a FailureCode per context, the English lives only in translations/failures+intl-icu.en.xlf, and bin/console app:messages proves the catalogue complete. Use before adding an exception, a failure code, a catalogue entry, an enum label, or anything that calls trans().
---

# Failure messages and translation

The argument is `docs/ADR/007_translation.md`. `php bin/console app:messages`
walks every declared code against every enabled locale and **exits non-zero on a
gap**.

## Three kinds of text, and only one is ours to translate

|               | Who wrote it                         | Who translates it                   | Keyed on                 |
|---------------|--------------------------------------|-------------------------------------|--------------------------|
| **A message** | us, for a human, at an edge          | us, into a catalogue                | its declared `code()`    |
| **A label**   | us, for a human, *about domain data* | us, into a catalogue                | the enum's backing value |
| **Content**   | a user, a curator, a partner         | nobody; it is *authored* per locale | nothing, it is stored    |

Row two is the one that may often get misfiled. A `BlockReason`, `BadgeCondition`,
`TrustLevel`, `ProfileStatus` are closed sets we own; their human labels are
hard-coded text *selected by* domain data, which is not the same as being domain
data.

> **An enum carries its identity, never its label.** Once an enum answers in
> English, somebody will want it to answer in French, and the only way to do
> that from inside the enum is to inject something.

If it is **content**, stop: read the database section of ADR 007. Nothing there
ships yet, `LocalizedText` and `Locale` do not exist by decision, and the locale
is then *data* that travels in the command rather than context.

> **The language you write in is data. The language you read in is context.**

## The rule

> **A domain exception carries a declared code and a named parameter map, never
> a sentence.** `getMessage()` returns `membership.handle-too-short`. Every
> language, English included, lives in the catalogue.

The alternative, keying on the English sentence, is Symfony's documented default
and costs four compounding things: fixing a typo orphans every translation; the
key is a whole sentence with placeholders that a translator sees in their tool;
it needs a sprintf-to-ICU converter; and the parameters are positional, so
reordering for a different word order means reordering `{arg1}` and `{arg2}`
with no idea what either is.

A stack trace gets the code and the parameters instead, which is PSR-3's bargain
and greppable in a way a sentence is not.

## Adding a failure

1. **Work out which of the three kinds it is.**
2. **Add a case to your context's `FailureCode` enum**
   (`SharedFailure`, `MembershipFailure`, `MatchingFailure`). Context prefix,
   dots, **no `/`** (a slash breaks `ValidationException::getId()`), never
   derived from a class name. The enum carries nothing but names; it exists so
   the set is *enumerable*, which is what makes completeness checkable.
3. **Raise it with the case and a named map**, using the `DeclaredFailure` trait
   that all twenty nine implementations use:

```php
public static function tooShort(string $value): self
{
    return new self(
        MembershipFailure::HandleTooShort,
        ['value' => $value, 'min' => Handle::MIN_LENGTH],
    );
}
```

4. **Implement `TranslatableThrowable` alongside the status marker.** Two
   orthogonal interfaces: one says which HTTP status and RPC code, one says how
   to render. All six status markers extend `TranslatableThrowable`.
5. **Write the `en` entry**, keyed on the code, in ICU. It is a deliverable, not
   a convenience: a missing entry ships the raw code to a caller.

```xml
<trans-unit id="membership.handle-too-short">
  <source>membership.handle-too-short</source>
  <target>Handle "{value}" is too short, it must be at least {min, number} characters long.</target>
</trans-unit>
```

6. **Run `bin/console app:messages`.** If it passes, the other locales are a
   translator's problem.
7. **Do not touch a renderer.** If you needed to, the message is carrying
   something the code and the parameters could not.

The `+intl-icu` suffix is per file, so `{count, plural, one {# field} other {# fields}}`
works with nothing anywhere parsing printf. **A number in a catalogue entry is
drift**: cite `Handle::MIN_LENGTH`, the same rule as a constraint's arguments.

## What may be a parameter

> **A parameter is a value the sentence talks about, never a piece of the
> sentence.**

`BadFilterInput::notA($key, $raw, 'an identifier')` passed English prose as an
argument and rendered as *« banana » n'est pas an identifier*. A translator
cannot fix that: the fragment is chosen in PHP at a site with no idea which
language the response will be in. It is now eight declared codes
(`shared.filter-not-a-boolean`, `-not-an-integer`, `-not-an-identifier`,
`-not-a-date`, `shared.filter-missing`, `shared.page-below-one`, ...), and the
caller gets the better end: `shared.filter-not-an-integer` is something a client
can branch on.

> **The test: if a parameter's value would have to change when the locale
> changes, it is a code of its own.**

Being a value is necessary and not sufficient:

> **An identifier is a value the reader cannot use.** Name what the reader has
> to change, never what they already typed.

A ULID is the same string in every language and is still twenty-six characters
of noise: it is not a name, it is on no screen, and the caller typed it a moment
earlier. Three answers, one example of each in the tree:

- **a human-readable handle exists** - use it. `membership.handle-taken` names
  the handle; `{value}` on every filter failure is what the caller typed.
- **there is no handle and never will be** - a pair is two members and a status;
  a badge is indistinguishable from the next badge. *That pair could not be found*
  loses nothing.
- **there is a handle, but not here** - `membership.member-not-found` could name a
  handle except that at the moment the failure is built it provably does not
  exist; `matching.like-already-cast` could name two except that fetching them
  means a write path querying a read model to build a sentence.

**The identifiers left `parameters()`, not just the catalogue**, because a
failure can only leak what it hands over and the XLIFF is edited by people who
never open the exception. Five not-founds are `::unknown()` and three conflicts
`::again()`, for want of an argument to take. `membership.unknown-revision` keeps
`{sequence}` because that is what the caller will change.

A refusal says even less: `shared.forbidden` and `shared.not-authenticated` take
**no parameters**, and **the permission name does not travel** - it goes in the
log, next to the actor and the operation.

## Rendering

**Only `Ui` renders.** `Shared/Infrastructure/Translation/FailureMessages` is the
one class in the tree that calls `trans()`, and phpat's `no_translator_outside_ui`
enforces it in all three context rule sets. The HTTP, RPC and console renderers
all call it: *one error object, one translator, three renderers.*

It has **no fallback logic on purpose**: `MessageCatalogue::has()` walks
`fallbackCatalogue` and `trans()` returns the raw key when nothing resolves. Our
job is only to make sure `en` is never the one missing, which is `app:messages`.

`translate()` asks the catalogue whether it owns a violation's template before
touching it, because **option A's violations arrive already translated** by
`ConstraintViolationBuilder` from the `validators` domain while option B's
arrive carrying a code. That is the third difference between ADR 005's two
designs: option A gets free translation of a generic sentence keyed by an opaque
UUID, option B pays for translation of a specific sentence keyed by something a
caller can route on.

Two details that each cost an afternoon otherwise: **a parameter named `hint`
becomes a response field** (`AbstractConstraintViolationListNormalizer`
special-cases it), and `ConstraintViolation` is immutable, so the renderer
builds a new list.

## Nothing carries a locale

`framework.set_locale_from_accept_language` plus
`set_content_language_from_locale` plus `enabled_locales` is the whole of
resolution. **No `LocaleResolver`, no listener of ours, no `RequestStack`.** No
signature anywhere takes a locale.

**`Accept-Language` is a preference, never a parameter.** It is negotiated down
to `default_locale` rather than answering 400, which is the one place ADR 007
and ADR 004 deliberately differ; it never produces a violation and never appears
in `BadFilterInputResponses`. **There is no `?locale=` and there will not be
one.** `Accept-Language` must stay in `vary` in `config/packages/api_platform.php`
or a shared cache serves the wrong language to the next caller.

The console renders at `default_locale` with no `--locale`. An asynchronous
handler never guesses a locale: either the recipient's locale travels in the
message, or the rendering belongs to whoever holds the recipient. In this tree
it is always the second - `NotificationGateway` hands a provider a recipient
reference and a template key, and the provider knows its own users' languages.
That is why no aggregate holds a locale and there is no `Locale` value object.

## Not translated, decided explicitly

`propertyPath` (it points into the payload the caller sent), `code` (contract;
translating an identifier gets you a client branching on French), the RFC 9457
`type` and `instance` (URIs - `title` and `detail` *are* translated, and §3.1
carves out localization by name), routing keys, enum backing values, cursor
contents, outbox topics, logs and `Throwable::getMessage()`, and **the OpenAPI
and OpenRPC documents** - a schema `description` compiles into generated client
source, so a French document puts French docblocks in somebody's IDE.

## Known, and not yours to fix in passing

`Matching\Infrastructure\ReadModel\MemberFeedEntry::patternLabel()` builds
`ucfirst($color).' '.ucfirst($motif)` and is untouched on purpose: `patternLabel`
is a published field on three endpoints, so fixing it is an API change, not a
rendering change.
