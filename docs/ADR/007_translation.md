# Translation

There are two problems behind the word and they share nothing except the word.

One is the text *we* wrote: a validation message, an error detail, a security failure, a label under
a button. It lives in the source, in one language, and something has to turn it into the reader's.

The other is the text *somebody else* wrote and we stored: a curated label on a catalogue entry, a
description a partner supplied in four languages. It lives in a database row, in as many languages as it was
authored in, and nobody translates it. The reader either finds their language in the row or does not.

The first is a rendering problem and belongs entirely to a delivery. The second is a modelling
problem and belongs to an aggregate. Filing one under the other is how a codebase ends up with a
`TranslatorInterface` injected into a repository, and it is the only mistake in this document that
cannot be undone by editing a catalogue.

If you would rather poke at it than read about it:

```shell
curl -s -i localhost:8000/members/banana -H 'Accept-Language: fr' | grep -i '^content-language'

curl -s localhost:8000/likes -X POST -H 'Content-Type: application/ld+json' \
  -H 'Accept: application/problem+json, application/json' -H 'Accept-Language: fr' \
  -d '{"from":"banana","to":"kumquat"}' | jq '.violations'

curl -s localhost:8000/rpc -H 'Content-Type: application/json' -H 'Accept-Language: fr' \
  -d '{"jsonrpc":"2.0","method":"membership.viewReputation","params":{"memberId":"banana"},"id":1}'

bin/console app:messages          # every declared code, every enabled locale, everything missing
bin/console membership:members --page=0; echo $?     # the same words, at the default locale, exit 22
```

The three surfaces answer the same sentence in the same language for the same mistake, which is the
whole of what this ADR ships.

## Flow

A request arrives. `LocaleListener` reads `Accept-Language`, narrows it to `framework.enabled_locales`,
falls back to `framework.default_locale`, and sets the locale on the request and on the translator.
No code of ours runs and nothing of ours is configured beyond four keys.

The request becomes a command or a query. **The locale does not travel with it.** Nothing downstream
of the delivery knows what language anybody reads.

Something fails. A domain exception is raised carrying a declared code and a named parameter map;
it carries no sentence, in any language.

The exception reaches a delivery. One class looks the code up in the `failures` catalogue for the
ambient locale and renders it. The HTTP surface puts the result in a `violations` array or in an
RFC 9457 `detail`, the RPC surface puts it in `error.message`, the console prints it at the default
locale. Three renderers, one lookup.

The response carries `Content-Language`, and the cache is told to `Vary` on `Accept-Language`.

Nothing else in the process ever asked what language anybody speaks.

## Architecture

### Three kinds of text, and only one of them is ours to translate

The two-way split in the opening is the one everybody starts with, and it hides a third case that is
responsible for most of the confusion:

|               | Who wrote it                         | Who translates it                   | Keyed on                 |
|---------------|--------------------------------------|-------------------------------------|--------------------------|
| **A message** | us, for a human, at an edge          | us, into a catalogue                | its declared `code()`    |
| **A label**   | us, for a human, *about domain data* | us, into a catalogue                | the enum's backing value |
| **Content**   | a user, a curator, a partner         | nobody; it is *authored* per locale | nothing, it is stored    |

Row two is the one that gets misfiled. `BlockReason::Harassment`, `BadgeCondition`, `TrustLevel`,
`ProfileStatus`, `PairStatus`, `VerificationStatus` are closed sets this application owns. Their
human labels are hard-coded text that happens to be *selected by* domain data, which is not the same
as being domain data. The reference implementation this ADR was started from has a `Locale::label()`
returning `'French'` on the enum itself, and that one method is what eventually drags a translator
into a domain: once an enum answers in English, somebody will want it to answer in French, and the
only way to do that from inside the enum is to inject something.

So: **an enum carries its identity, never its label.** `membership.badge-condition.pristine` lives in
the catalogue like every other message, and the enum stays a closed set of names.

`Matching\Infrastructure\ReadModel\MemberFeedEntry::patternLabel()` is the live offender in this
tree:

```php
return \sprintf('%s %s', ucfirst($this->color), ucfirst($this->motif));
```

A read model building an English sentence, with a locale-naive `ucfirst` for good measure. It hands
the delivery a rendering instead of two values, so the delivery has nothing left to render.

### The line: what you write is data, what you read is context

> **The language you write in is data. The language you read in is context.**

A localized text a curator authors is content. The locale is part of the value, it travels in the
command as a value object, it is stored, it is versioned, and losing it loses the meaning of the
string.

The locale a caller wants to *read* in is context. It is resolved at the edge, it never enters a
command, it reaches a read model only as a narrowing value, and losing it costs nothing worse than
the default language.

Everything else in this document follows from which side of that line a given string is on.

### The code is already the key

ADR 005 made every `UnprocessablePayloadThrowable` declare a `code()`, and locked five properties on
it: stable across refactorings, published as contract, namespaced by context, dot-separated because
a `/` breaks `ValidationException::getId()`, and **declared rather than derived** so that renaming a
class is not a breaking change for callers.

That is a translation key. The repository has been writing catalogue keys for a whole ADR without
calling them that, and this one costs nothing to collect.

It is worth being precise about what the alternative would have been, because it is the common one
and it is what the implementation this ADR started from does. Inside subclasses of `AbstractDomainUnprocessableException`
registers **the English sentence itself** as the catalogue key. That is Symfony's documented default
and Twig's default, and it has one real advantage: a missing translation degrades to readable
English with no catalogue at all.

It also has four costs, and they compound:

1. **fixing a typo orphans every translation.** The key changed, so four `.xlf` files now carry a
   key nothing looks up, and the fifth language silently falls back to English;
2. **the key is a whole sentence with placeholders in it**, which is what a translator sees in their
   tool, and what a diff shows when the punctuation moves;
3. **it needs a converter.** The sentences would be written for `vsprintf`, the catalogue wants ICU, so
   `SprintfToIcuConverter` exists: ninety-five lines of regex over printf's grammar, with a
   `\0_LITERAL_PERCENT\0` sentinel and a `@phpstan-ignore-next-line` in the middle of it. Every
   line of it exists to undo a choice made one layer down;
4. **the parameters are positional.** `%s` becomes `{arg1}`, so a translator reordering a sentence
   for a language with a different word order is reordering `{arg1}` and `{arg2}` with no idea what
   either one is.

None of those four are a bug in that code. They are all consequences of the key being a sentence. Key
the catalogue on the code instead and the extractor has nothing to extract, the converter has
nothing to convert, and the parameters can be named because the exception knows their names.

### A message is a template plus parameters, and everything agrees

The shape is not ours and not novel. Three things already in this repository's `vendor/` do it:

- **PSR-3.** `LoggerInterface::error(string $message, array $context)`: a template and a named
  context, rendered by whoever renders;
- **Symfony Security.** `AuthenticationException::getMessageKey()` and `getMessageData()`, translated
  in the `security` domain by the listener that renders the failure. The component has kept the
  domain free of sentences for fifteen years by doing exactly this;
- **Symfony Validator.** `ConstraintViolation` carries `getMessageTemplate()` and `getParameters()`
  next to `getMessage()`, and the whole reason the pair exists is so something else can render it.

`Shared/Application/Validation/Violations::violation()` already says so, in the docblock explaining
its own nulls:

> `messageTemplate` repeats `message` because the template/parameters split is a translation seam
> and nothing here is translated yet. When it is, that is the pair to populate.

This ADR populates it.

## Resolving the locale

### Four config keys and no code

```php
// config/packages/translation.php
'default_locale' => 'en',
'enabled_locales' => ['en', 'fr'],
'set_locale_from_accept_language' => true,
'set_content_language_from_locale' => true,
```

That is the whole of locale resolution. `Symfony\Component\HttpKernel\EventListener\LocaleListener`
reads the header, negotiates it against `enabled_locales`, and sets the locale on the request and on
the translator; `enabled_locales`'s own config description says so:

> Defines the possible locales for the application. This list is used for generating translations
> files, but also to restrict which locales are allowed when it is set from Accept-Language header.

**No `LocaleResolver`, no listener of ours, no `RequestStack` anywhere.** This deserves saying out
loud because the instinct in a hexagonal codebase is to write the resolver, and a hand-rolled
`Accept-Language` parser is a quality-value grammar with a wildcard, a range-matching rule and a
fallback chain, reimplemented worse.

It also means **no signature anywhere carries a locale for hard-coded text.** The translator is
request-scoped by the framework, so the renderer calls `trans($key, $params, 'failures')` with no
locale argument and gets the caller's language. That is only safe because of the rule below: the one
thing allowed to read the ambient locale is the thing doing the rendering.

### `Accept-Language` is a preference, not a parameter

ADR 004 is emphatic that a filter value which will not parse is a `400` and is never silently
defaulted. `Accept-Language` looks like a filter value and is not one:

|               | A filter value (ADR 004)                   | `Accept-Language`               |
|---------------|--------------------------------------------|---------------------------------|
| unparseable   | `400`, `BadFilterInput`, exit 22           | ignored                         |
| unsatisfiable | `400`                                      | negotiated down, then defaulted |
| absent        | documented default, decided in the handler | `default_locale`                |
| affects       | which rows come back                       | which words come back           |

`Accept-Language: klingon, tlh;q=0.9` is not a client error. RFC 9110 §12.5.4 says a server may
ignore the header entirely, and an origin server that answered `400` to it would be alone on the
internet. So it never reaches `BadFilterInput`, never produces a violation, and never appears in the
`400` responses `BadFilterInputResponses` decorates onto operations.

There is a corollary worth locking: **there is no `?locale=` parameter, and there will not be one.**
Two negotiation mechanisms means a tiebreak nobody wants to document and a cache key nobody
remembers to `Vary` on. HTTP has one mechanism for this and it is in the header.

### `Content-Language`, and the cache that lies without it

Negotiation that the caller cannot observe is indistinguishable from a bug, so the response says
which language it picked. `set_content_language_from_locale` does that.

The other half is not optional and is currently wrong. `config/packages/api_platform.php` declares:

```php
'defaults' => [
    'cache_headers' => [
        'vary' => ['Content-Type', 'Authorization', 'Origin'],
    ],
],
```

`api-platform/http-cache` is installed. The first day a French string ships, a shared cache stores
the French response under a key that does not mention the language and serves it to the next English
caller. `Accept-Language` joins that list in the same commit as the first translated string, not
after somebody reports it.

### The console, where a default is not a fallback

Console output is rendered at `default_locale`, and there is no `--locale` option.

An operator is not an end user. `app:members:register` and `app:likes:cast` exist to prove ADR 005's
option B serves more than one delivery, and the person running them is debugging this application,
not using it.

But note what that does *not* mean. Under this ADR the English sentence lives only in the catalogue,
so the console does not bypass the translator; there is nothing else to print. It calls the same
renderer, at a fixed locale, and never negotiates. The distinction matters because "the console is
English" sounds like an exemption and is actually the narrowest possible use of the same seam.

### The consumer, where there is no caller

An `#[Asynchronous]` handler and an outbox relay have no request, therefore no ambient locale,
therefore nothing to read. ADR 005 already makes the same observation about validation
("Asynchronous, where nobody is waiting") and the answer here is the same shape: whatever the edge
knew and the handler needs has to be *carried*, or it does not exist.

For text addressed to a person, the thing that has to be carried is not the dispatcher's language.
Whoever cast the last like is not who reads the notification.

So the rule is: **an asynchronous handler never guesses a locale. If something we own renders a
sentence for a named person outside a request, the recipient's locale travels in the message.**

### Why nothing in this tree carries a locale, and why `Member` does not have one

Apply that rule to the one place it could bite, and it comes back negative.

```php
interface NotificationGateway
{
    public function notify(string $recipientRef, string $template, array $context): void;
}
```

`PairFormedNotificationHandler` sends `('pair-formed', ['pairId' => ..., 'pairedAt' => ...])` and no
sentence in any language. `$recipientRef` is the *provider's* reference to that person: a push and
mail provider that holds a user record holds that user's language preference, and renders
`pair-formed` in it.

Which makes the language that person reads their email in **their fact, held by their provider**,
exactly as a badge's brand is the manufacturer's fact in ADR 003 path 4. A `locale` on `Member` would
be a field we accept at registration, migrate, expose, keep correct, and never once read.

The test for when that flips: **does something we own render a sentence, for a named person, outside
a request?** The day this application sends its own mail through Symfony Mailer instead of handing a
key to a provider, the answer is yes, and on that day the locale becomes a fact about a member,
stored on the aggregate, replicated into `Matching`'s `MemberFeedEntry` the way every other
cross-context fact is (ADR 001: events into a local projection, never a read across the boundary).
Not before.

The same argument disposes of a `Locale` enum in `Shared/Domain/`. With no aggregate holding one and
no command carrying one, its only consumer would be `enabled_locales` in config, which is a plain
list of strings. A domain type whose only user is a config file is what ADR 005 deleted
`Shared/Domain/Validation/` for: *"it was never used outside itself."* `Locale` arrives with
`LocalizedText` or it does not arrive.

## Hard-coded text

### Option A is already translated, and that is not as good as it sounds

ADR 005 ships two validation designs and this ADR turns up a third difference between them that
neither of us had listed.

`Symfony\Component\Validator\Violation\ConstraintViolationBuilder::addViolation()` calls
`$this->translator->trans(...)` at build time. Every violation produced by Symfony's validator
arrives **already rendered in the request locale**, from the `validators` domain, which ships
translated into fifty-seven locales.

`Shared/Application/Validation/Violations::violation()` constructs a `ConstraintViolation` with
`new`, which bypasses the builder and therefore the translator. And API Platform does not make up
the difference: `AbstractConstraintViolationListNormalizer::getMessagesAndViolations()` reads
`$violation->getMessage()` verbatim and has no translator at all.

So today, on the same endpoint, in the same response shape:

|                    | Option A (`Membership`, `Handle`)      | Option B (`Matching`, `Violations`)                                  |
|--------------------|----------------------------------------|----------------------------------------------------------------------|
| `message`          | translated, by Symfony, for free       | English, always                                                      |
| what it says       | `This value is too short.`             | `Handle "ab" is too short, it must be at least 3 characters long.` |
| `code`             | `9ff3fdc4-b214-49db-8718-39c315e33d45` | `membership.handle-too-short`                                        |
| translatable by us | no                                     | yes                                                                  |

Option A gets free translation of a generic sentence keyed by an opaque UUID. Option B pays for
translation of a specific sentence keyed by something a caller can route on. That is the honest
trade, and it is the third item on ADR 005's list after "who owns the rule" and "what the OpenAPI
document can advertise". It does not move that ADR's conclusion; it does mean a reader choosing
between the designs should be told that option B has a bill attached, and this section is it.

### Where the English goes

**The catalogue, and only the catalogue.**

An exception carries a code and named parameters. It does not carry a sentence, and
`translations/failures+intl-icu.en.xlf` is a committed, required deliverable rather than an optional
convenience.

The alternative was to keep a developer-facing English sentence on the exception *and* a
caller-facing one in the catalogue, on the grounds that a stack trace reading
`membership.handle-too-short` is worth less than one reading a sentence. It loses for the reason
ADR 005 rejects every other duplicated predicate: two Englishes for one failure drift, and the one
that drifts is always the one nobody reads.

What a stack trace gets instead is the code and the parameters, which is precisely PSR-3's bargain
and is greppable in a way a sentence is not.

The consequence is that **a missing catalogue entry ships the raw code to a caller**, and that is
the whole reason the next section comes out the way it does.

### Option A, extraction by parser

Walk the AST, find the code literals, write them into a `MessageCatalogue`, let
`bin/console translation:extract fr --force` do the rest. This is `DomainExceptionMessagesExtractor`
and `NonEmptyStringMessagesExtractor` with their subject changed from sentences to codes, and it is
Symfony's own workflow.

It is less bad than the sentence-keyed version it replaces, because the key it finds is stable. It is
still heuristic. Our codes are constructor arguments to private constructors
(`new self('A handle cannot be blank.', 'membership.handle-blank')`), so the visitor has to know
the shape of each exception family; a code assembled by concatenation, returned from a `match` arm,
or read from a constant is invisible to it, and nothing tells you it was missed. `nikic/php-parser`
becomes a dev dependency.

And it cannot answer the question this ADR needs answered, which is not *"find me the codes you can
see"* but *"prove there is no code without an entry"*.

### Option B, the code is declared

One enum per context, listing that context's codes:

```php
namespace App\Membership\Domain\Exception;

enum MembershipFailure: string implements FailureCode
{
    case HandleBlank    = 'membership.handle-blank';
    case HandleTooShort = 'membership.handle-too-short';
    case HandleTooLong  = 'membership.handle-too-long';
    case HandleCharset  = 'membership.handle-charset';
    case HandleTaken    = 'membership.handle-taken';
    // ...
}
```

The enum carries nothing but the names. It is not a message catalogue in PHP, it does not hold
templates, and it does not know what a translator is. It exists so that the set is *enumerable*,
and everything else follows from that:

- **completeness is checkable.** `cases()` over every `FailureCode` against
  `MessageCatalogue::defines()` per enabled locale is a total answer, which no parser can give;
- **`code()` stops being a free-form string.** ADR 005's "no `/`, dot as separator" rule is one
  assertion over one enum instead of a convention repeated at thirty call sites;
- **duplicates are visible.** Two exceptions reaching for the same code is a line of code you can
  see, not a collision in a `.xlf` file;
- **it navigates.** A caller reports `matching.cannot-like-self` and the IDE goes from the wire name
  to the case to the exception in two jumps;
- **it is the published contract in one file per context**, which is what you hand a client team,
  and it is the same thing ADR 006 made `MethodRegistry` and `Param` do for RPC: exposure is a
  declaration, never a consequence.

The cost is one indirection at the point of failure: `MembershipFailure::HandleTooShort` instead of
a literal. ADR 005 praised the literal (*"Each named constructor carries its own `code()`"*), and
that survives intact; the named constructor still names its own case, and the case is still one home
for the string.

### What I would do

**Option B**

If a missing entry shipped `membership.handle-too-short` to a caller instead of a sentence, then the
catalogue's completeness is a correctness property, and a correctness property needs something that
can fail. A parser that found nine of ten codes reports success. An enum with ten cases and nine
entries reports a gap and names it.

The two decisions are one decision, and this is the seam where it shows.

### The exception, after

```php
final class InvalidHandleException extends \InvalidArgumentException
    implements UnprocessablePayloadThrowable, TranslatableThrowable
{
    private function __construct(
        private readonly FailureCode $failure,
        private readonly array $parameters = [],
    ) {
        parent::__construct($failure->value);
    }

    public static function tooShort(string $value): self
    {
        return new self(
            MembershipFailure::HandleTooShort,
            ['value' => $value, 'min' => Handle::MIN_LENGTH],
        );
    }

    /** @return non-empty-string */
    public function code(): string { return $this->failure->value; }

    public function parameters(): array { return $this->parameters; }
}
```

```xml
<!-- translations/failures+intl-icu.en.xlf -->
<trans-unit id="membership.handle-too-short">
  <source>membership.handle-too-short</source>
  <target>Handle "{value}" is too short, it must be at least {min, number} characters long.</target>
</trans-unit>
```

Four things about that pair:

- it is **shorter** than what it replaces. Three of `InvalidHandleException`'s four named
  constructors currently carry a `sprintf`, and all four carry a code literal;
- **the parameters are named**, so a translator moving `{value}` after `{min}` for a language that
  wants it there is moving something they can read;
- **ICU, natively, with no converter.** The `+intl-icu` suffix on the domain is per file, so
  `{count, plural, one {# field} other {# fields}}` works in `InvalidPayload`'s container message
  without anything anywhere parsing printf;
- **`Handle::MIN_LENGTH` is still the one home for the number.** ADR 005 lock 9 said a Symfony
  constraint's arguments must be domain constants rather than literals repeating them; the same rule
  applies to a message parameter, and for the same reason. A catalogue entry that hard-codes `3` is
  drift with a translation department in front of it.

### A parameter is a value, not a fragment

This one was found by shipping, an hour after the catalogue went in, and it is the most transferable
thing in the document.

ADR 004 gave `BadFilterInput` two generic named constructors:

```php
BadFilterInput::notA($key, $raw, 'an identifier');
BadFilterInput::outOfRange('page', (string) $number, 'page numbers start at 1');
```

Those third arguments are English prose. They were invisible for two ADRs because everything around
them was English too. Translate the sentence they sit inside and they are suddenly the only words in
it that did not move:

```
GET /members/banana   Accept-Language: fr
« banana » n’est pas an identifier, ce qu’attend « memberId ».
```

A translator cannot fix that, and neither can the catalogue: the fragment is chosen by the caller of
`notA()`, in PHP, at a site with no idea which language the response will be in. **A parameter is a
value the sentence talks about, never a piece of the sentence.** `{value}`, `{min}` and
`{sequence}` are values. `an identifier` is grammar.

The fix is one code per case, which is what every other exception in the tree already did:

| was                                                  | is                                                       |
|------------------------------------------------------|----------------------------------------------------------|
| `notA($p, $v, 'a boolean')`                          | `notABoolean($p, $v)`, `shared.filter-not-a-boolean`     |
| `notA($p, $v, 'an integer')`                         | `notAnInteger($p, $v)`, `shared.filter-not-an-integer`   |
| `notA($p, $v, 'an identifier')`                      | `notAnIdentifier($p, $v)`, `shared.filter-not-an-identifier` |
| `notA($p, $v, 'a date')`                             | `notADate($p, $v)`, `shared.filter-not-a-date`           |
| `notA($key, '', 'an identifier')`                    | `missing($key)`, `shared.filter-missing`                 |
| `outOfRange('page', $v, 'page numbers start at 1')`  | `pageBelowOne('page', $v)`, `shared.page-below-one`      |

Eight codes where there were two, and the caller gets the better end of it:
`shared.filter-not-an-integer` is something a client can branch on and `shared.filter-wrong-type`
never was. The last row is a second defect the same audit turned up, because
`notA($key, '', 'an identifier')` was how a *missing* required parameter was reported, and it
rendered as *`""` is not an identifier*.

The general test, for the next one: **if a parameter's value would have to change when the locale
changes, it is not a parameter.** Run it over any message that interpolates something a developer
typed rather than something a caller sent.

Being a value is necessary and not sufficient, which the next section is about: a ULID is a value in
every language and still has no business in a sentence.

### An identifier is a value the reader cannot use

Second audit, same catalogue, and this one was found by reading rather than by shipping. Thirteen of
the thirty-eight entries interpolated an identifier:

```
Le porteur « 01JB4K8QX2M7V0ZC3RSTUVWXYZ » ne peut pas s’aimer lui-même.
La paire « 01JB4K9T5N8P1QDE2FGHIJKLMN » est introuvable.
La chaussette « 01JB4KA6H3W9Y4XV5BNMQRSTUV » n’est pas dans ce tiroir.
```

Every one of those passes the previous section's test. A ULID is a value, it is the same string in
French and in English, and no translator has to touch it. It is also, for the reader, twenty-six
characters of noise in the middle of a sentence. **An identifier in a sentence is an identifier the
reader cannot use**: it is not a name, it is not on a screen anywhere, and in every case above the
caller had typed it into the request a moment earlier.

The right question is not "can this be interpolated" but "does this help the reader tell *which*
thing went wrong". Three answers, and the tree has an example of each:

- **There is a human-readable handle, so use it.** `membership.handle-taken` says
  *Handle "chaussette" is already taken*, and the handle is both what the caller sent and what
  they will change. `{value}` on every filter and parse failure is the same thing: it is what the
  caller typed, which is exactly how they recognise it.
- **There is no handle and there never will be.** A pair is two members and a status; it has no
  name, not even in principle. A badge is indistinguishable from the next badge. The sentence loses
  nothing by saying *that pair could not be found*, because the ULID was never telling the reader
  which pair either.
- **There is a handle, but not here.** This is the interesting one. `membership.member-not-found`
  could name a handle, except that it is a *not found*: at the moment the failure is built, the
  handle provably does not exist. `matching.like-already-cast` could name two handles, except
  that `Matching` keeps them on a read projection, so fetching them would mean a write path querying
  a read model to build a sentence, breaking ADR 004's first rule to say something the caller sent
  in the request body. In both cases the handle is out of reach for a reason that is a design
  property rather than an oversight, and reaching for it anyway is how you find out.

The same reading also settled the one borderline case. `membership.billing-conflict` interpolated
`sub_1a2b3c4d5e6f`, which is not one of our ULIDs but the billing provider's own reference, and the
argument for keeping it was that somebody opening a ticket with the provider would quote it. A hash
with a prefix in front of it is still a hash: nobody reading that 409 recognises their subscription
by it, and they have exactly one. An operator who needs it has the gateway's request log.

#### Removing it from the catalogue is not enough

The thirteen entries could have been fixed by editing two XLIFF files, and that fix would have been
worth about a month. `parameters()` is the whole of what a renderer may interpolate, so a parameter
no sentence names is not documentation, it is an invitation, and the catalogue is edited by people
who have never opened `MemberNotFoundException`. `{memberId}` comes back in one locale and not the
others, and nothing fails: `app:messages` checks that every code *has* an entry, not what is inside
it.

So the identifiers left the exceptions, not just the sentences:

```php
MemberNotFoundException::withId($id)            →  MemberNotFoundException::unknown()
PairNotFoundException::withId($id)              →  PairNotFoundException::unknown()
CannotLikeSelfException::forMember($memberId)   →  CannotLikeSelfException::sameMember()
LikeAlreadyCastException::between($from, $to)   →  LikeAlreadyCastException::again()
UnknownRevisionException::inJournalOf($w, $n)   →  UnknownRevisionException::sequence($n)
```

**A failure can only leak what it hands over.** This is the same move ADR 006 made when it turned
RPC exposure into a declaration instead of a consequence, and the same one
`MemberIsBlockedException` relies on to keep "they blocked you" out of the French entry: the
catalogue is trusted with wording and never with facts.

The last line is the distinction in one signature. `sequence` stayed and `memberId` went, because
the sequence is what the caller asked for and will ask for differently next time, while the member
is the one they already named in the URI. That is the test to run on the next message: **name what
the reader has to change, not what they already know.**

Two names went in with it, and they are worth copying only because they are boring. A not-found is
`::unknown()` in all five classes; a conflict over something that already happened is `::again()` in
all three. A named constructor with no arguments left had to stop lying about taking one, and a
consistent verb is easier to grep than five inventive ones.

### `TranslatableThrowable`, and an amendment to ADR 005

`Shared/Domain/Exception/` currently holds six markers answering one question (*what kind of failure
is this*), and one of them, `UnprocessablePayloadThrowable`, additionally declares `code()`. That
was right when the code's only job was the RFC 9457 `type` and the violation `code`. It is not right
once the code is also how the failure gets its words, because every marker's exceptions produce text
a caller reads, and `RpcErrorFactory` has an arm calling `$failure->getMessage()` for every one of
the six.

So the codes move up onto their own axis:

```php
interface TranslatableThrowable extends \Throwable
{
    /** @return non-empty-string */
    public function code(): string;

    /** @return array<string, scalar> */
    public function parameters(): array;
}
```

Two orthogonal interfaces per exception: **one says what status it is, one says how to render it.**
`exception_to_status` is untouched, the six markers become pure markers, and ADR 005's entire
argument for why `code()` is declared rather than derived moves into this interface's docblock
without a word changing.

Two things fall out of that move:

- **`AbstractDomainUnprocessableException` loses `getMessageTemplate()`/`getMessageParameters()`.**
  That pair is a `vsprintf` template plus *positional* parameters, which is the shape that made the
  reference implementation's sprintf-to-ICU converter necessary. Under this interface it is a code
  and a named map like everything else, and the stage-2 and stage-3 exception families finally
  render through one path;
- **`ErrorTypingProvider`'s regex deletes itself.** It currently builds the RFC 9457 `type` with
  `preg_match('/\\\\([^\\\\]+)\\\\Domain/', get_class($exception))` plus a camel-to-kebab pass over
  the class short name, which is precisely what ADR 005 forbids for `code()`: it publishes our
  namespace as public contract and makes every rename a breaking change for callers. Once
  `DomainUnprocessableThrowable` implements `TranslatableThrowable`, the tag URI is composed from
  the declared code and the provider stops reflecting on class names.

### One translator, three renderers

Five things read a failure's text today:

|              | Where                                                              | Reads                      |
|--------------|--------------------------------------------------------------------|----------------------------|
| HTTP, list   | `ValidationExceptionFactory` into API Platform's normalizer        | `$violation->getMessage()` |
| HTTP, single | `ErrorTypingProvider`, into the `Error` resource's `detail`        | `$failure->getMessage()`   |
| RPC          | `RpcErrorFactory`, one arm per marker plus the `violations` helper | both                       |
| Console, writes | `RenderViolations`                                              | `$violation->getMessage()` |
| Console, reads  | six `catch (BadFilterInput)` blocks, one per query command      | `$failure->getMessage()`   |

Translating in each of them is four homes for one policy. One class in
`Shared/Infrastructure/Translation/` is the only thing that ever calls `trans()`, and the other three
call it. That extends ADR 005 lock 12 by exactly one word: *one error object, one translator, three
renderers.*

Its whole implementation is the lookup, because **there is no fallback logic to write**:

```php
public function render(TranslatableThrowable $failure): string
{
    return $this->translator->trans($failure->code(), $failure->parameters(), 'failures');
}
```

`MessageCatalogue::has()` walks `fallbackCatalogue` and `trans()` uses it, so a missing `fr` entry
resolves through `en` without a line of ours. The fallback chain is Symfony's; our job is only to
make sure `en` is never the one missing, which is `app:messages`.

Two details that each cost an afternoon otherwise:

- **a parameter named `hint` becomes a response field.** `AbstractConstraintViolationListNormalizer`
  special-cases `$violation->getParameters()['hint']` and emits it as a `hint` key in the violation.
  That is a feature; it is also a reserved parameter name nobody documents;
- **`ConstraintViolation` is immutable**, so the renderer builds a new list rather than setting
  messages on the old one. It is a value object with ten constructor arguments and no setters, which
  is correct and mildly inconvenient exactly once.

### Where the translator may be named

`ArchitectureTestCase` already keeps translation out of the two inner rings by omission: `domain()`
allows `Symfony\Component\Uid` and `Ecotone\Modelling\Attribute`, `application()` adds
`Symfony\Component\Validator` and `Ecotone\Modelling`, and neither one names translation. So a
handler or an aggregate reaching for a translator fails today, which is the useful signal: this ADR
does not need a new permission, the way ADR 006 needed none.

What it does need is one **narrowing**, the second in this repository's history after ADR 005 pulled
`Symfony\Component\Validator` out of `miscellaneous()`. `infrastructure()` allows all of `Symfony`
through `vendors()`, so a context's own `Infrastructure` may name the translator today. Nothing
should: a Doctrine repository, a projection, an outbox relay and an HTTP gateway all produce facts,
and none of them has a reader.

```
Symfony\Component\Translation and Symfony\Contracts\Translation may be named under Ui, and nowhere else.
```

One `shouldNotDependOn` in `SharedArchitectureTest`, `MembershipArchitectureTest` and
`MatchingArchitectureTest`. It is the enforceable half of "only a delivery renders", and it is worth
having precisely because the violation is so reasonable-looking: a notification adapter wanting to
put a sentence in a payload is one autowired argument away, and it is the moment the design stops
holding.

## Database text

None of this section ships. It is written because the decision is taken, the shape is known, and the
next person to store a translatable string should not have to retake it; a demo lands when a feature
genuinely wants one.

### Two shapes, and only one of them is translation

|                  | Authored per locale                                     | Authored once, in a language                  |
|------------------|---------------------------------------------------------|-----------------------------------------------|
| example          | a catalogue label a curator maintains in four languages | a block reason a member typed                 |
| the locale is    | a key into the value                                    | a property of the value                       |
| a missing locale | a gap, filled by a policy                               | meaningless; there is one                     |
| we translate it  | no, someone authored each one                           | no, and machine-translating it would be a lie |

Both are content. Neither is translated by us. The difference is whether the locale indexes the
value or describes it, and getting that backwards produces a table with a `locale` column and one
row per record, forever.

In this tree today the only honest candidate for the first shape is `BadgeModel::$localLabel`, which
is our curation of a catalogue entry. `brand` and `officialName` sit next to it in the same
aggregate and are the **manufacturer's**, single-language, and must not become localized: ADR 003
path 4 is built on whose fact a field is, and localizing somebody else's fact is claiming it.

### `LocalizedText`, and why it is write-side only

```php
final readonly class LocalizedText implements Equality
{
    /** @return array<string, string> */
    public function locales(): array;

    public function in(Locale $locale): ?string;

    public function with(Locale $locale, string $text): self;
}
```

Three changes from `TranslatedText` in the implementation this started from, and each one is a
finding rather than a preference:

**It does not throw on a missing locale.** `TranslationNotFoundException` makes a missing Dutch
label a broken aggregate, and it is not; it is a gap in a rendering. Worse, the policy for filling it
differs per field and the aggregate is the wrong place to know which: a marketing blurb happily falls
back to English, a legal notice must not fall back at all and must show nothing. So the value object
answers `?string` and the *caller* holds the policy.

**It never reaches the read side.** A map of four languages, read to render one, is four times the
rows on a list endpoint. Per ADR 004 a read model answers by criteria and returns DTOs, so the
adapter selects one language in SQL and the fallback is a `COALESCE`, not a PHP loop:

```sql
SELECT COALESCE(label ->> :locale, label ->> :default, official_name) AS display_name
```

That is also the only version that can sort. `ORDER BY display_name COLLATE "fr-x-icu"` is per-locale
and indexable by an expression index; a PHP-side fallback sorts whatever the page happened to
contain, which is not sorting.

**It is named for what happened.** Nobody translated it; somebody authored it in several languages.
`TranslatedText` claims a provenance the data does not have.

### Reading it is a filter, and storing it is not a sixth path

**Reading.** The locale rides inside the query object next to the filter values, parsed by the
delivery, interpreted by the adapter: ADR 004's shape exactly, with one difference that has to be
stated or somebody will implement the 400. It is negotiated rather than parsed, so an unsupported
locale narrows to the default instead of refusing, and it never appears in
`BadFilterInputResponses`' list.

It is not a `Filter` in `Shared/Application/Filter/` and does not become one. A filter value chooses
*which rows*; the locale chooses *which column of a row*. Putting it in `FilterValues` would give
`DeclaredFiltersFactory` a query parameter to generate and publish, and there must not be one
(see `?locale=` above).

**Storing.** `docs/storage-map.md` gets no new section, because a `LocalizedText` is a value object
and a value object is stored the way its aggregate's existing path stores value objects: a custom
Doctrine type on paths 1 to 3, a field in the writer's changeset on path 4. Localizing a field
changes the *type* of one column, never how the aggregate is owned. ADR 003's five answers stay
five.

A JSON column, then, and not a child table. The argument is ADR 003's own: a translation with a life
of its own (a reviewer, a workflow, a timestamp, an approval) is a second aggregate and earns a
table; a bag of strings belonging to one owner, read and written whole with that owner, is a value
object and belongs in its owner's row. Postgres indexes the expression, so the read stays one row
and no join.

## What is not translated

Deciding this explicitly is cheaper than discovering it:

- **`propertyPath`**, which points into the payload the caller sent and therefore stays in the wire's
  language. Of a violation's three fields, exactly one is for a human;
- **`code`**, which is contract. Translating an identifier is how you get a client that branches on
  French;
- **the RFC 9457 `type` and `instance`**, which are URIs. `title` and `detail` are translated, and
  the RFC explicitly permits it: §3.1 says `title` should not change from occurrence to occurrence
  *"except for purposes of localization"*. Localization is the one variation it carves out by name;
- **routing keys, enum backing values, cursor contents, outbox topics**, all of which are machine
  vocabulary;
- **logs and `Throwable::getMessage()`**, for the audience reason in "Where the English goes". A log
  in the locale of whoever happened to trigger it is a log you cannot grep;
- **the OpenAPI and OpenRPC documents.** One reference document, in the project's language. A schema
  `description` gets compiled into generated client source, so a French document puts French
  docblocks in somebody's IDE, which is worse than English for every reader including the French
  one. The `info.description` block in `config/packages/api_platform.php` and the tag descriptions in
  `TagDescriptions` stay as they are, and this is the one place in the document where "we support
  four languages" is the wrong answer.

## The rules this obeys

1. **The language you write in is data; the language you read in is context.** Authored text carries
   its locale into the model. Requested locale stops at the delivery.
2. **A domain exception carries a code and named parameters, never a sentence.** Every language,
   including English, lives in a catalogue.
3. **The code is the key.** Declared, stable, dot-separated, published, and now also what the
   catalogue is indexed on. One string, three jobs, one home.
4. **A parameter is a value the sentence talks about, never a piece of the sentence.** If its value
   would have to change when the locale changes, it is a code of its own rather than an argument.
5. **An identifier is a value the reader cannot use.** Name what the reader has to change, never
   what they already typed. A handle that exists goes in; a ULID does not, and it leaves the
   `parameters()` map rather than only the sentence.
6. **Codes are declared in an enum per context**, so the set is enumerable and its completeness is a
   checkable property rather than a hope.
7. **Only a delivery may name a translator.** Not a domain, not an application service, not a
   context's infrastructure. Enforced.
8. **One translator, three renderers.** HTTP, RPC and console call one class; none of them calls
   `trans()`.
9. **No signature carries a locale for hard-coded text.** The framework makes it ambient at the
   edge, which is safe only because rule 7 holds.
10. **`Accept-Language` is negotiated, never parsed.** It never produces a `400`, it is restricted
    to `enabled_locales`, and there is no second mechanism such as `?locale=`.
11. **A negotiated response says so**: `Content-Language` on the way out, `Accept-Language` in
    `Vary`, or a shared cache serves the wrong language to the next caller.
12. **An asynchronous handler never guesses a locale.** Either the recipient's locale travels in the
    message, or the rendering belongs to whoever holds the recipient. In this tree it is always the
    second.
13. **An enum carries its identity, never its label.** A label is a rendering and lives where
    renderings live.
14. **Stored text is authored, not translated.** A missing locale is a gap filled by a policy the
    caller owns, never an exception the aggregate raises.
15. **Localizing a field changes a column's type, not an aggregate's storage path.** ADR 003 keeps
    its five answers.
16. **A machine-readable document is not localized.** One OpenAPI, one OpenRPC, one language.
17. **A negotiation failure is not translated, because its reader is not a human.** A `406` and a
    `415` say that the *caller* asked for or sent a media type the operation does not have, which no
    end user chose and none can change. They carry a plain English sentence, declare no
    `FailureCode`, and `app:messages` has nothing to check. Everything else keeps rule 1.

### Rule 17 is an amendment, and here is how narrow it is

[ADR 009](009_one-url-two-representations.md) gave one class, the rendering processor, the job of
answering a media type an operation does not declare, and that answer is a `406` with a sentence in
it. A reviewer called that a violation of rule 1 and was right about the letter.

**The line this draws is who can act on the message.** A `422` on a handle is read by somebody who
will retype it, so it is a code with a catalogue entry in every enabled locale. A `406` is read by
whoever wrote the `Accept` header, which is a client developer with a terminal open; translating it
into French would mean a French sentence in a log nobody reads, and giving it a declared code would
put a case in an enum that no human-facing surface will ever render.

So the test is not "is this an error" but **"is there a person who will change what they typed
because of this sentence"**. If yes, it is a declared code. If the only possible reader is the author
of the request's headers, it is prose.

The risk this creates is the obvious one and is why the rule is written down rather than left to
taste: the exemption is for content negotiation and for nothing else. A `400` on a filter value is a
person's typed query and stays a declared code, even though it also reaches a developer first. If a
third status ever wants in here, it owes its own sentence in this section.

## If you are adding translatable text

1. Work out which of the three kinds it is. If it is content, stop here and read the database section;
   nothing below applies.
2. Add a case to your context's `FailureCode` enum. Context prefix, dots, no `/`, and never derived
   from a class name.
3. Raise the exception with the case and a **named** parameter map. Parameters cite domain constants;
   a number in a catalogue entry is drift. Pass what the reader has to change and nothing else: no
   identifier, no English fragment, and nothing the request already said.
4. Implement `TranslatableThrowable` alongside the status marker that says which HTTP status and
   which RPC code this is. Two interfaces, two questions.
5. Write the `en` entry in `translations/failures+intl-icu.en.xlf`, keyed on the code, in ICU. It is
   a deliverable, not a convenience.
6. Run `bin/console app:messages`. It walks every case of every enum against every enabled locale and
   exits non-zero on a gap. If it passes, the other locales are a translator's problem and not yours.
7. Do not touch a renderer. If you needed to, the message is carrying something the code and the
   parameters could not, and that is worth a conversation before it is worth a special case.

## What this ADR still owes

Most of it landed with this document rather than after it, which is the order CLAUDE.md asks for.
What shipped, briefly, so that the list below is readable as what is *left*:
`TranslatableThrowable` and the three `FailureCode` enums; twenty-one exceptions rewritten onto the
`DeclaredFailure` trait; `AbstractDomainUnprocessableException` stripped of its `vsprintf` pair;
`FailureMessages` and its five call sites; `ErrorTypingProvider` moved into `Ui` with its class-name
regex deleted; the four config keys and `Accept-Language` in `vary`; 38 codes in `en` and `fr`;
`bin/console app:messages`; and the phpat narrowing in three rule sets. Then a second pass over the
thirty-eight entries took every identifier back out of both the sentences and the `parameters()`
maps that fed them, across thirteen exceptions and twenty-one throw sites. PHPStan is unchanged at
21 errors in the same four files it named before.

What is left:

- **`MemberFeedEntry::patternLabel()` is untouched, and on purpose.** Fixing it properly is not a
  rendering change, it is an API change: `patternLabel` is a published field on `/members`,
  `/candidate-feed` and `/profile-history`, and five read models build one. Translating it means
  either a catalogue entry per colour-motif pair, which is combinatorial and wrong, or exposing
  `color` and `motif` and letting the delivery join two translated labels, which changes the wire
  shape of three endpoints. That decision is worth its own commit and possibly its own argument; it
  should not ride along with the seam.
- **Option A's messages are still Symfony's.** `Membership`/`Handle` gets its 422 text translated for
  free from the `validators` domain in fifty-seven locales, and none of those sentences is ours:
  a French caller is told *"Cette chaîne est trop courte."* where option B would have said which
  handle and what the minimum is. That is the bill ADR 005's two designs were always going to
  present, and this ADR only made it legible.
- **`Error`'s `title` for a non-validation failure is API Platform's, in English.** A `400` answers
  `"An error occurred"` next to a fully translated `detail`. The HTTP status phrases underneath it
  are protocol vocabulary and should stay, but that particular string is not a status phrase, it is
  vendor prose, and decorating it is a small piece of work nobody has done.
- **Nothing is tested.** `tests/` still holds no cases, and `app:messages` only proves the catalogue
  is complete, never that an entry says what it should. The `hint` parameter collision, the
  option A / option B split in `FailureMessages::translate()`, and the ICU plural boundary in French
  (where zero is singular) are three behaviours that would each fit in a test and currently rest on
  a paragraph.
- **The `en` catalogue is hand-written.** `app:messages` says when an entry is missing; nothing
  generates the skeleton, so adding a code means adding XML by hand. A `--dump` flag that emits the
  missing `trans-unit` stubs is an afternoon and would remove the last excuse for forgetting.

And three things left open on purpose:

- **`LocalizedText` and `Locale` do not exist yet**, by decision. The design is above; the demo waits
  for a feature that wants it rather than a feature invented to want it. The consequence is visible
  in `config/packages/translation.php`, where `enabled_locales` is a literal list: with nothing in
  either context holding a locale, an enum would have a config file for its only consumer, which is
  what ADR 005 deleted `Shared/Domain/Validation/` for.
- **Twig.** `templates/` holds one `base.html.twig` and this is an API. When a template renders
  anything a caller reads, the same rule applies (`{% trans %}` with a key, never a sentence), and
  that is the whole of it.
- **Security messages.** Symfony's `AuthenticationException` already carries `getMessageKey()` and
  `getMessageData()` and is already translated from the `security` domain, which is this ADR's design
  arriving fifteen years early. ADR 008 inherits the seam and should say what it renders and where.
