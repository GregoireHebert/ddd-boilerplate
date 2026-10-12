# One URL, two representations

I have been writing "the API" and "the web UI" as though they were two applications that happen to
share a database. They are not. They are one application, and what differs between a browser and a
client is narrower than it looks.

Four things get assumed to travel together, and only the first one actually does:

|    |                    | browser                      | API client                   |
|----|--------------------|------------------------------|------------------------------|
| 1  | the URL            | `/members`                   | `/members`                   |
| 2  | the representation | `text/html`                  | `application/ld+json`        |
| 3  | the credential     | a session cookie             | a bearer token               |
| 4  | the refusal        | a redirect to the login page | an RFC 9457 problem document |

**The URL is shared; the other three are negotiated, and each is negotiated by a different
mechanism.** That sentence is the whole of this document. Everything below is what follows from it,
and the reason it needs an ADR is that the obvious alternative, giving each delivery its own URL
space, is what this repository did for about fourteen hours by accident and it is genuinely
tempting.

If you would rather poke at it than read about it:

```shell
curl -s -H 'Accept: application/ld+json' localhost:8000/members
curl -s -H 'Accept: text/html'           localhost:8000/members
curl -s -H 'Accept: application/xml'     localhost:8000/members   # 406, and that is the point
bin/console debug:router | grep members                           # one route, not two
```

## Flow

A browser asks for `GET /members` with `Accept: text/html` and a session cookie. Symfony's router
matches the API Platform route, because it is the only one. The firewall authenticates the session
and builds an `Actor` stamped `Authority::Session`. The gate is asked for
`MembershipPermission::BrowseDirectory`. The state provider runs, puts `ListMembers` on the query bus,
and gets a `PageOf<MemberRow>` back.

A client asks for `GET /members` with `Accept: application/ld+json` and a bearer token. The router
matches the same route. The firewall authenticates the token and builds an `Actor` stamped
`Authority::Token`. The gate is asked for the same permission. The same provider runs, puts the same
query on the same bus, and gets the same `PageOf<MemberRow>` back.

The two paths stay together until the result has to become a response, and part there. One is
serialized as JSON-LD; the other is handed to Twig, which renders
`templates/membership/member/collection.html.twig` around the same DTOs.

Everything before that point knows nothing about which of the two it is serving, and that is the
property worth protecting. A provider that reads the request format has become two providers wearing
one class name.

## Architecture

### A URL is not a delivery

The tree already had four deliveries, which [ADR 008](008_security.md) counts: ReST, RPC, Twig and
the console. I let that count leak into a place it does not belong. A delivery is *how a caller
reaches the application*; a URL is *what the application calls a thing*. The console has no URLs at
all, which should have been the clue: if a URL were part of a delivery, the console would be missing
one, and it is not missing anything.

**A resource has one address. What a caller reads it as is a separate question, asked by
`Accept`.** This is not a clever reading of HTTP; it is the ordinary one, and it is the reading
`GET /members` already assumes when it answers JSON-LD to one client and plain JSON to another. The
only new part is that one of the media types renders a page.

### The mistake that taught me this

On the night of the security work an agent moved the whole API Platform surface from `/` to `/api`
in `config/routes/api_platform.php`, and no report claimed the change. The reasoning behind it was
sound and somebody had written it down honestly a few hours earlier: both deliveries mount at the
root, the Twig one currently owns `/login`, `/logout` and `/badge-designs/...`, and the day a page
card serves the member directory at `/members` it collides in the router with the API collection of
the same name, before any firewall sees it.

The collision is real. The fix is not. Two URL spaces means `GET /api/members` and `GET /members`
answer the same question with different words, every link in a page points at a different document
from the one an API client holds, and the application has two names for every noun it owns. **When a
browser and a client want different words for the same thing, the thing is not two things.**

I am recording this because the same pressure will come back. The first page card that touches a
path an API resource already owns will feel like the moment to split the router, and it is the
moment to add a media type.

### `Accept` and `Accept-Language` are both negotiation, and I treat them differently on purpose

[ADR 007](007_translation.md) rule 6 says `Accept-Language` is a preference and never a parameter:
an unavailable language is negotiated down to `default_locale` rather than answering `400`. This
ADR says an unavailable representation answers `406`. Those look inconsistent and they are not.

**A language I do not have still lets me answer the question, badly. A representation I do not have
means I cannot answer it at all.** Serve French content to a reader who asked for Japanese and they
get an answer they can paste into a translator; serve XML to a client that parses JSON-LD and they
get nothing. One degrades and the other fails, so one negotiates down and the other refuses.

### Templates are not source, and a template is named for the operation

[ADR 001](001_architectural-organization.md)'s "templates are not source" survives this ADR
unchanged: a template lives under `templates/`, found by path rather than by class name.

What changes is how one is chosen. There is no controller any more for a resource page, so there is
no namespace to mirror. **A resource page's template is named for its resource and its operation**,
at `templates/<context>/<resource>/<operation>.html.twig`, and the rendering processor resolves that
path from the operation it was handed. `GET /members` renders
`membership/member/collection.html.twig`, and `GET /members/{memberId}` renders
`membership/member/item.html.twig`.

A missing template is a `406` and not a `500`: it means this operation has no HTML representation,
which is a legitimate thing for an operation to be, and the next section is about why.

### Not every operation has both representations

The user-facing half of this is obvious and the machine-facing half is not. `GET /members` has an
HTML representation because somebody reads it. `POST /rpc` does not, because JSON-RPC is a protocol
and not a page, and `rpc.discover` least of all.

The interesting cases are in between, and rule 6 below decides them: `PATCH /members/{memberId}` has
**no** HTML representation, even though a person edits a form to reach it, because the form is a
`GET` on a controller and the `PATCH` itself is sent by a script that asks for JSON. A write has an
HTML representation only when an HTML form can perform it, which means `POST` and nothing else.

**An operation declares the representations it has, and the absence of one is a `406` rather than a
degraded page.** I do not want a fallback that renders a JSON dump inside a `<pre>` when a template
is missing; that is the kind of helpfulness that ships to production and stays there.

### A representation does not have to be a page, and `image/svg+xml` is the proof

A member's badge is drawn from stored state, and the first build of
[`membership/12`](../../.claude/plans/membership/12_your-badge-drawn.md) put it at
`GET /members/{memberId}/badge.svg` on a `Ui/Web` controller. Both halves of that are wrong by this
document's own rules, and a reviewer said so before I did. Rule 4 lets `Ui/Web` hold an empty form,
the login routes, and an asset that is a pure function of its path; a drawing of somebody's stored
badge is none of the three, because it reads the member through the bus. And `.svg` is a
representation chosen by a path suffix, which is rule 1 with a file extension standing in for the
prefix.

**So it is a resource operation with three representations.** `GET /members/{memberId}/badge` answers
`application/ld+json` for a client, `text/html` for a reader and `image/svg+xml` for an `<img>`, and
`outputFormats:` lists all three. Nothing new is needed to choose between them; that is
`ContentNegotiationProvider`'s job and rule 3 already sends it there.

This is worth stating because "representation" quietly came to mean "page" over the first half of
this document, and it does not. **A representation is whatever the operation declares it can answer
with.** The badge is the case that makes it obvious, because the third one is not a document at all,
and a format that had been hiding inside a file extension turns out to be an ordinary member of the
same list.

Rule 2 survives this unchanged, and it is worth seeing why. The rendering processor answers `svg` the
same way it answers `html`: it reads the format, and instead of handing a template to Twig it hands a
colour and a motif to `BadgeRenderer`, which knows nothing about the request. **Rule 2 was always
about the reading rather than about HTML**, and a third format is the first thing to test that.

The badge pattern preview is the contrast that proves the line rather than blurring it.
`GET /badge-designs/{color}/{motif}.svg` stays a `Ui/Web` controller, because it is a pure function
of its path: no member, no store, no bus, and a public cache entry. One of these two URLs draws a
badge somebody owns and the other draws a badge nobody owns, and that is the whole of the difference.

### An empty form is not a representation of anything

This is the hole the first draft left, and it is not small: roughly half the page cards on the board
are forms, and a write-only resource has nothing for a form to be a representation *of*.
`MemberRegistrationResource` declares one `Post`. There is no `GET /members/{id}` on a registration,
because a registration is not a thing you can fetch; so `GET` the empty form is a request for
something that does not exist yet, and asking the resource to answer it means inventing a `Get`
operation whose JSON representation would be an empty object nobody wants.

**An empty form is page content that no resource holds, so it is a `Ui/Web` controller**, by the
same rule as the navigation bar and the unread count. `GET /register` is
`Ui/Web/Membership/RegisterController`, it renders `templates/membership/register.html.twig`, and it is
a page in [ADR 001](001_architectural-organization.md)'s sense with ADR 001's template path.

**This document first said `/members/new`, then said that URL cannot exist, and both sentences were
wrong.** The first was written without running `debug:router`. The second said the collision with
`Get /members/{memberId}` was fatal, and it is not: `ApiPlatform\Metadata\HttpOperation` takes
`requirements` (`HttpOperation.php:107`), so
`new Get(uriTemplate: '/members/{memberId}', requirements: ['memberId' => '[0-9A-HJKMNP-TV-Z]{26}'])`
makes the item route decline `new` and the web route catch it. Loading `config/routes/web.php` before
`config/routes/api_platform.php` would do it too, since the first matching route wins.

So it is a choice, and **the interesting part is what each way costs**, which is the part I skipped
when I declared it impossible.

The requirement regex costs a `404` that is currently a `400`. `GET /members/not-a-ulid` answers
today with

```json
{"status":400,"type":"tag:gheb.dev,2026:shared.filter-not-an-identifier",
 "detail":"\"not-a-ulid\" is not an identifier, which is what \"memberId\" takes."}
```

which is [ADR 004](004_filter.md)'s "a parse failure is a `400`" and [ADR 007](007_translation.md)'s
declared code, working exactly as both intended. Put a ULID pattern in `requirements` and the router
stops matching before any of that runs, and the answer becomes a bare `404`. **A regex in the routing
table would silently replace a decided `400` and a translated message with the framework's default
`404`**, and nothing would fail to tell us.

Reordering the route files costs more and it costs it invisibly: it lets anything under `Ui/Web/`
shadow a resource's path. That is rule 5 turned inside out, enforced by a load order in a config file
rather than by anything a reader would notice.

**So `/register` stays, and now it stays for a reason rather than for an obstacle.** An empty form is
not a representation of a member, so its URL owes the member's URL no resemblance; `/login` is not
`/credentials/new` either, and the two pages `Ui/Web/` holds should look like each other rather than
like the resources they are not. The cost of the alternative was real and the ADR was not paying it to
buy anything.

Three vendor claims in one document, all three wrong, all three found by somebody else. The rule I
wrote after the first one is now the rule this section exists to demonstrate: **a claim about a vendor
belongs to whoever ran the command, not to whoever found it plausible.** I have started running the
command.

**The form then writes to the resource**, at `POST /members` and never to the controller, because
rule 1 is about the write and the write has one URL. It gets there through the script of rule 6
rather than through a native submit, so the `422` comes back as JSON and is painted onto the fields,
and a `201` is a redirect the script follows. The controller renders the form; it never receives it.

Two consequences worth stating, because both cost something.

The controller is not a lesser thing than a state provider. It puts a query on the bus or it renders
a static form, exactly as a provider puts a query on the bus, and the design system's rule that a
page reaches the domain through the bus is as true of one as of the other. **What a `Ui/Web`
controller may never be is a second way to write a resource**; if you find yourself giving one a
`POST`, the resource is missing an operation and the controller is about to hide it.

And option A's constraints now run for the page, which reverses a decision
[`membership/01`](../../.claude/plans/membership/01_register-a-member.md) took deliberately. That card
had the page parse with `Handle::fromString()` so it would prove a second time, after the console,
that option A is HTTP-only. Posting to the resource means API Platform validates first and the page
meets the constraints like any other HTTP client. **The proof does not disappear, it moves**: the
console is still option B on the same write and still exits 22, so the contrast survives with one
demonstration instead of two. I would rather lose a demonstration than keep two URLs writing one
resource.

### A capability with no resource has no representation, so its page is a controller

The badge collection is the case, and it is the one that shows this document's rule is about resources
rather than about pages. `membership.acquireBadge`, `loseBadge` and `discardBadge` are RPC methods
because [ADR 006](006_rest-vs-rpc.md)'s three tests put them there: a badge in a badge collection has no
address this application is willing to publish. Nothing is a resource, so nothing has a
representation, so **the badge collection page is a `Ui/Web` controller and its actions call the same
capabilities the RPC methods call.** Rule 5 is not strained by this, because rule 5 forbids a
controller writing a *resource*, and there is no resource here to write.

What the controller must not do is call the RPC method class. `AcquireBadgeMethod::__invoke()` takes
an `RpcParams` and returns an array, so a controller reaching for it would build a JSON-RPC params
object out of form fields and then read a JSON-RPC result array back: the page would be speaking
the wire protocol to itself, and a change to the protocol's envelope would break a form.

**It is a third envelope over the same domain, which is [ADR 006](006_rest-vs-rpc.md) rule 10
arriving for the third time.** That rule says RPC methods call the same domain constructors as the
state processors, not that either calls the other. A controller parses with
`Shared/Application/Validation/Violations`, builds the same value objects, and puts the same command
on the same bus. Three deliveries, three parses, one domain, and the duplication is the parse, which
is the duplication ADR 005 already decided is worth paying.

### One route, one action, one controller file

The badge collection page arrived as a single `BadgeCollectionController` with five actions guarding themselves by
calling the gate inline, and the badge drawing as a `MemberBadgeController` that carried `#[Guarded]`
and then reflected on its own class to read the attribute back. Two controllers, two ways of
guarding, neither of them written down anywhere. **That is how a convention dies: not by being argued
against, but by being invented twice in one week.**

**A `Ui/Web` controller is one class, one route and one `__invoke()`, and it carries its guard as a
class attribute**, `#[Guarded(Permission)]` or `#[Unguarded(because: '...')]`, exactly as a resource
operation and a console command already declare theirs.

The reason is not tidiness, it is enumerability, and it is the argument this repository has now made
four times: `FailureCode` cases over an AST extractor, `Permission` cases over an expression string,
one class per RPC method over routing onto the bus, and now one class per page over a controller with
five actions. **A class attribute can be walked; an attribute on one of five methods is a thing you
have to remember to look for**, and `bin/console app:access` is the command whose whole purpose is
that nobody has to remember.

So `app:access` gains a fourth reporter and walks `src/Ui/Web/`. Until it did, three writes on the
badge collection page were invisible to the one command ADR 008 built to make the policy checkable, and two
`#[Unguarded]` declarations printed exactly as an undeclared operation would, which is the ambiguity
rule 13 exists to remove. `reportConsole()` had the same hole, reading `Guarded::on()` and never
`Unguarded::on()`, and that one was mine: I added the attribute in one reporter and forgot the other.

The cost is more files, and I will take it. A five-action controller saves four `final class` lines
and spends them on every reader who has to work out which of five methods a route reached.

### Every form is submitted by a script, and nothing is submitted natively

Here is a constraint I wrote three rules against without noticing. An HTML form's `method` attribute
accepts `GET` and `POST` and nothing else. Four resource writes on the board therefore cannot be
performed by a form at all: `PATCH /members/{memberId}`, `PUT /members/{memberId}/matching-preferences`,
`DELETE /members/{memberId}` and `DELETE /pairs/{pairId}`.

Two of the three ways out are lies.

**A method override** is the classic one: a hidden `_method` field and
`framework.http_method_override`, which exists in this tree's configuration reference and is `false`,
as it has been by default since Symfony 6. It works by having the server pretend the `POST` it
received was a `DELETE`. Everything downstream then disagrees with everything upstream: the access
log, the firewall, a proxy's cache rules and a `405` all see one method while the operation sees
another. The reason the framework turned it off by default is the reason not to turn it back on.

**A `POST` alias operation** means declaring `POST /pairs/{pairId}/unpair` beside the `DELETE`. That
is two URLs for one write, which is rule 1, and it is also ADR 006 rule 1 sneaking a verb into a path
after that document spent a section keeping them out.

**Accepting `application/x-www-form-urlencoded`** on the write operations is the one that gets
suggested last and it is not stupid, which is why it is here rather than in a footnote. It would let
a native `POST` reach `POST /members` instead of answering `415`. **It fixes the request and leaves
the response broken**, and the response is the half that matters: a browser does not follow a `201`'s
`Location`, so a successful native registration would show the reader a JSON-LD document, and a
failed one answers `422`, which `error_formats` sends to an error page with every typed value gone.
So it buys a no-JavaScript path that works only when nothing goes wrong, and it pays a second input
format published on every write in a document CLAUDE.md calls a deliverable. If progressive
enhancement is ever worth reopening, this is the option to reopen it with, and the thing to fix first
is the response.

**A thin script** is the honest one. The page sends the real method with `fetch()`.

#### Why it is every form and not only the four

I first drew this line at the four, and left `POST` to submit natively. Building the refusals broke
that in an hour, and the break is worth recording because it is a case of two rules reaching for one
lever.

Rule 12 sends a browser's `403` and `404` to Symfony's error templates. The only switch that does
that on an API Platform route is putting `html` in `error_formats`, because
`ApiPlatform\Symfony\EventListener\ErrorListener::duplicateRequest()` hands an error to Symfony's
`error_controller` exactly when the negotiated error format is `html`. **That switch is
status-agnostic.** Turn it on and a `422` goes to an error page too, and a `422` is the one failure
that has to come back to the form with the fields still holding what the person typed. There is no
per-status mechanism in the vendor, and writing one means our own listener threaded into the
vendor's chain by a priority number, which this document already refused once when it chose a
processor over a `kernel.view` listener.

So the line moves rather than the mechanism: **a form is never submitted natively.** Every write,
`POST` included, goes by `fetch` with the real method and `Accept: application/ld+json`. No write
failure ever arrives as HTML, `error_formats` carries `html` for navigation failures only, and the
two rules stop fighting because they no longer touch the same lever.

#### What the script does, and what it costs

**It asks for JSON, because it is a client.** A `fetch` sending `Accept: application/ld+json` gets
the representation every other client gets, including the `422` with its `violations` array. **So no
write operation anywhere has an HTML representation**, none declares `html` in its `outputFormats`,
and none has a template. The HTML in the story is always the form page, which is a `GET` on a
controller.

**A form the script owns declares its target in `data-action` and `data-method`, and carries
neither `action` nor `method`.** This is the part the first draft got wrong, and a review caught it
before a reader did. I wrote `<form method="post" action="/members">` and told the script to take over
the `submit` event, which is only true while the script is there. With it off, the browser does what
the attributes say: it posts `application/x-www-form-urlencoded` to `/members`, gets a `415` from
`ContentNegotiationProvider`, and `error_formats` renders that as an HTML error page because the
browser's `Accept` matches `html`. **That is the exact outcome rule 6 widened to prevent, arriving
through the front door of the rule that was supposed to prevent it.**

So the native attributes go, and they are replaced by `method="dialog"`. A form with no `action` and
no `method` submits a `GET` to the page it is already on, which sounded harmless until the register
form grew a password: that `GET` puts the password in a query string, and therefore in the access
log, the history and any `Referer`. **A rule that moves a password from a `415` into a URL is worse
than the bug it replaced.** `method="dialog"` on a form with no ancestor `<dialog>` fires the `submit`
event, which is what the script listens for, and then does nothing at all; with the script absent the
page simply cannot act. **The presence of `data-action` is the opt-in**, which is why the login form
needs no marker to be left alone: the firewall receives it rather than a resource, so it stays native
and has no `data-action` to be picked up by.

**A form may also declare where the reader goes afterwards, with `data-on-success`, and that is the
only option this script will ever take.** It exists because registration needs it and nothing on the
server can supply it: the `201` correctly names the created member, but a reader who has just
registered has no session yet, so following that `Location` lands them on a refusal. The server
cannot tell a browser from a client here either, because a registration carries no credential by
definition and rule 6 makes the script ask for JSON-LD. **So the distinction is the page's to make,
and a page makes it by declaring a URL rather than by registering a callback.** The limit is exactly
that: `data-on-success` is a URL, never a function name, never a selector, never a hook. F3's "do not
give it options, a per-form callback or a registry" survives, because an attribute holding a URL is
the same kind of thing `data-action` already is.

**`data-precondition` is the third attribute, and "the only option this script will ever take" was
always a sentence about the *kind* of option rather than about the count.** It names a URL the
script `GET`s before the write, reads `version` off the body, and sends it as the `If-Match` request
header. It exists because `PATCH /subscriptions/{subscriptionId}` requires that header and refuses
an unconditional write with a `428`, and because an HTML form cannot send a request header at all:
this is the capability rule 6 widened the script to have, arriving at the one endpoint that needs
it. The limit is unchanged and is what matters: a URL, never a function name, never a selector,
never a hook, and the script still has one function per write. The read's own refusal is painted the
way a refused write is, and a precondition read that produces no validator never falls back to
writing without the header, because a required precondition a client can opt out of by accident is
the thing the `428` exists to prevent.

**A `422` is painted onto fields and a `409` is not.** [ADR 005](005_validation.md) already decided
that half: `UnprocessablePayloadThrowable` is the one collectable marker and `ConflictThrowable` is
not one. A conflict is a fact about state rather than about a field, it arrives alone by construction,
and its problem document carries no `propertyPath` to aim at, so the script shows it as a page-level
notice and leaves every typed value where it is. [F3](../../.claude/plans/00-F3_web-foundation.md)'s
plan said a conflict carries "the one violation a problem document carries"; it carries none, and that
was established against a live `POST /members` with a taken handle rather than against the prose.

**And it is thin on purpose**: read the form, send the method, and on a `422` paint each violation
onto the field its `propertyPath` names. That is one function for the whole application, because
[ADR 005](005_validation.md) made every `422` in this tree carry one shape, whichever of the two
validation designs produced it. It is a plain file under `public/` with no build step, per
[F3](../../.claude/plans/00-F3_web-foundation.md)'s first decision.

**What it costs, said plainly: no resource write works with JavaScript turned off.** Reading does,
signing in does, and the badge collection does, because the first is a `GET`, the second is a firewall
route rather than a resource, and the third posts to a `Ui/Web` controller. Everything else, starting
with registering, needs the script.

That is a real retreat from the design system's progressive-enhancement stance, which is why the
badge preview is a `<select onchange>` swapping an `<img>` rather than a fetch, and I am taking it
anyway. The alternatives were an application whose HTTP method is a fiction, or a failed
registration that answers with an error page and makes the reader retype everything. **If this trade
ever looks wrong, the honest way back is a `POST` alias with a paragraph in ADR 006, not a method
override and not a second rendering path.**

#### A redirect is a redirect, and a `202` is one too

[`membership/11`](../../.claude/plans/membership/11_erase-a-member.md) asked the question this section
had not. The erasure answers `202`, and its `Location` names a saga to watch rather than a thing that
now exists. Three answers were defensible: the script navigates as it does for a `201`, it polls the
`Location` from where the reader already stands, or it does nothing and leaves a link on the page.

**The script follows the `Location` on both, and the difference lives in the page it lands on.** I
took this one because the other two make the script learn a second behaviour, and the script is the
one file in this design that must not grow a branch. A timer, a target element and a poll interval
are three options, and three options is how one function becomes a framework nobody chose; F3's "do
not give it options, a per-form callback or a registry" is the same sentence said earlier.

What the reader actually wanted, which is to watch the thing advance, is not lost; it is simply not
the script's. `GET /member-erasures/{memberId}` is a resource with an HTML representation, and rule
12 says a page composes. **So the watching belongs to the checklist page**, which fills itself from
the resource it is the page of, and this is the first case on the board where rule 12 is the answer
to a question rather than a restriction on one.

The one thing that would reopen this is a `202` whose `Location` a reader has no business seeing. I
do not have one, and if one turns up it is an operation that should not be answering a browser at
all.

### `/me` is a perspective, not a second resource

`GET /me/badges` and `GET /members/{memberId}/badges` return the same rows, and for a while this
document had one of them as a controller page and the other as a resource, which is rule 1 broken in
the place rule 1 was written. The resolution is not to delete one. **They are the same resource seen
from two perspectives, and the perspective is a security affordance rather than a different thing.**

`/me/...` imposes that you can only read and change what is yours: the principal is resolved from the
credential and there is no segment to put somebody else's identifier in. `/members/{memberId}/...`
admits the opposite, that with a permission and an identifier a caller could reach anybody. Both are
true statements about one collection of badges, and which one a delivery reaches for says what it
believes about its reader rather than what it believes about the data.

So they are canonically the same, in the word's exact HTTP sense: the `/me` form answers the same
representation and carries `<link rel="canonical">` to the explicit URL. This is the rule below about
`/` arriving a second time, and the second arrival is what makes it a rule rather than a special
case. **A path that resolves the principal is an alias, and an alias owes a canonical link.**

What this does not license is a `/me` alias for every operation on the board. One is worth having
where a reader would type or bookmark it; `/me/badges` is, and `/me/members/{otherId}/badges` is a
contradiction in terms.

### `/` may forward, and then it owes a canonical link

The discovery feed lives at `GET /members/{memberId}/candidate-feed`, and `/` is where a signed-in
reader expects to land. Two ways, and both are legitimate.

A **redirect** is the honest one and costs nothing: `/` resolves the principal and `302`s to the
feed's own URL, which is then what the address bar, a bookmark and a shared link all carry.

A **forward** keeps `/` in the address bar, which is what a reader of a social application expects,
and Symfony can do it: `forward()` dispatches to another controller, and an API Platform route
carries its operation in `_api_resource_class` and `_api_operation_name` route defaults, so a
sub-request can be given those plus the `memberId` it needs. **It works, and it is hand-assembling
the framework's internal request attributes**, which is a thing to write once, comment, and expect
to revisit on a major upgrade.

The forward costs one thing and it is not rule 1's representation question: it is that two URLs then
return the same document, which is a canonicalization problem rather than a design one. **So a
forwarded page carries `<link rel="canonical">` pointing at the resource's own URL**, which is what
the web already does about duplicate addresses and is cheaper than arguing about it. Do not let a
forward become a habit: `/` earns one because a home page is a real thing a reader asks for, and no
other path does.

## Producing the HTML

I drafted this section as four designs to choose between, and then went and read the vendor, which
is the order I should have used in the first place. **Almost all of it already exists, and the part
I thought was the decision is a configuration value.** What follows is what API Platform 4.3.17
actually does, and the one class this repository still owes.

### The negotiation is already written, and so is the per-operation declaration

`vendor/api-platform/state/Provider/ContentNegotiationProvider.php` is a decorator provider, and its
`provide()` is four lines of substance:

```php
$formats = $operation->getOutputFormats() ?? ($isErrorOperation ? $this->errorFormats : $this->formats);
$this->addRequestFormats($request, $formats);
$request->attributes->set('input_format', $this->getInputFormat($operation, $request));
$request->setRequestFormat($this->getRequestFormat($request, $formats, !$isErrorOperation));
```

Three things fall out of those lines, and each one replaces a paragraph I was about to write.

**Negotiation is done.** `Accept` is parsed, matched against the configured formats and turned into
a request format, by the framework, before any provider of ours runs. Rule 1 of this document is not
something to build; it is something to stop working around.

**An operation already declares its representations.** `$operation->getOutputFormats()` falls back to
the global list, so `outputFormats: ['jsonld' => ['application/ld+json']]` on a single operation is
how `POST /rpc` says it has no HTML, and `canNegotiateContent()` is how an operation opts out
entirely. Rule 3 needs no mechanism either; it needs `outputFormats:` written on the operations that
differ.

**An unavailable format already answers correctly.** `getRequestFormat($request, $formats, true)`
throws when nothing matches, which is the `406` this document asks for. I do not have to decide what
a missing representation does, only which operations have which.

The name `html` is API Platform's own: `Bundle/DependencyInjection/Configuration.php:208` already
declares `'html' => ['mime_types' => ['text/html']]` among the default `docs_formats`. Nothing is
invented; a format moves from the documentation's list to a resource's.

### The correction: `html` is declared per operation, never in `formats`

I first wrote that `text/html` becomes available by adding `'html' => ['text/html']` to the global
`formats` list, and that this was free because `enable_swagger_ui` is `false` here. **Both halves
were wrong, and building it proved it in about an hour.**

`enable_swagger_ui` is the wrong flag. `ApiPlatformExtension.php:674` loads the Swagger UI services
when `enable_swagger_ui || enable_re_doc || enable_scalar`, and `enable_re_doc` is `true` in this
tree. So `SwaggerUiProvider` is registered, it decorates the read provider, and at its line 47 it
takes over **any** operation whose request format is `html` and answers the documentation page
instead. `GET /members` with `Accept: text/html` stopped answering `406` and started answering
ReDoc: a refusal replaced by a wrong success, which is the worse of the two failures.

And a global `html` made every resource advertise a representation it did not have. The OpenAPI
export grew twelve `<Resource>.html` schemas describing a `text/html` response with a JSON body, on
a document CLAUDE.md calls a deliverable. That is the same defect the `patch_formats` comment two
sections down in that config file already warns about, arrived at from the other direction.

**So `html` is never in `formats`. An operation that has a page declares it on itself:**

```php
new GetCollection(
    outputFormats: ['jsonld' => ['application/ld+json'], 'html' => ['text/html']],
    // ...
)
```

Which is rule 3 as it was already written, applied to the one format that has to be opt-in rather
than opt-out. Every operation that declares nothing keeps answering `406` from
`ContentNegotiationProvider`, and the OpenAPI document keeps describing only what exists.

Our resources also set `_api_disable_swagger_provider => true` once, in `defaults.extra_properties`,
so that a declared `html` reaches the rendering processor rather than the documentation page. The
documentation itself is untouched: `SwaggerUiProvider` returns early when the operation's class is
`OpenApi`, which is the route `/docs` goes through, so the opt-out cannot reach it.

The general lesson is worth more than the fix. **A configuration claim in an ADR is a fact about a
vendor, and it belongs to whoever checked it rather than to whoever found it plausible.** I read
`enable_swagger_ui: false` in the config file and did not read the condition that consumes it.

### The rendering is a processor, and the vendor has already written one

This is where I was wrong in the first draft, and where the guess that the templates belong to the
providers is close but half a step off.

A provider decides **what data**. A processor decides **what response**. For a resource page the
provider is already correct: `GET /members` runs the same provider, puts the same query on the same
bus and gets the same `PageOf<MemberRow>` back whoever is asking. **Nothing about the read changes,
so nothing about the provider changes.** What changes is the last step, where the result becomes
either a serialized document or a rendered page.

API Platform ships that exact pair for its own HTML page, and it is worth reading before writing
ours:

| vendor class | what it does |
|---|---|
| `Bundle/SwaggerUi/SwaggerUiProvider.php:47` | a decorator provider: `if ('html' !== $request->getRequestFormat())` hand off to the decorated one, otherwise supply different data |
| `Bundle/SwaggerUi/SwaggerUiProcessor.php:34` | a processor taking a `TwigEnvironment`, whose `process()` returns a rendered `Response` |

So the shape is precedented, in the framework, for precisely this problem. Ours is the simpler half
of it: we need the processor and not the provider, because unlike the documentation page we do not
want *different* data, we want the same data drawn differently.

**One class: a processor that renders Twig when the request format is `html`, and delegates to the
serializer chain otherwise.** It resolves `templates/<context>/<resource>/<operation>.html.twig` from
the operation it is handed, and it receives the provider's return value before normalization, so a
template reads `member.handle` off the DTO with every type the query bus just proved. That was the
cost of the encoder design I had drafted, and choosing the processor does not pay it.

`SwaggerUiProcessor` is also a warning worth copying: its constructor throws if Twig is missing,
with a message naming `composer require symfony/twig-bundle`. Ours should do the same, because the
failure otherwise arrives as a null dereference in a request rather than at container compile time.

### What I rejected, briefly

**A serializer encoder for `html`.** It works, and it was my first draft. The encoder runs after
normalization, so it receives arrays and the template loses every type; recovering them means a
pass-through normalizer, which is a second class solving a problem the processor does not have.

**A `kernel.view` listener.** It keeps the DTOs, and it sits outside API Platform's pipeline, so it
has to be threaded between the vendor's own listeners by a priority number nobody can read. The
processor is inside the pipeline by construction.

**A controller per page sharing the route**, which is what the board assumed before this ADR. Two
things answer one URL, the route is declared twice in two files with defaults that drift, and the
read path is written twice because the controller reaches the read model through the same query bus
the provider does. This is the option the twenty plans are written against and it is the one this
ADR deletes.

**A route `condition` matching on `Accept`.** Symfony routes take an ExpressionLanguage
`condition`, and `request.headers.get('accept') matches '/text\\/html/'` is a legal one, so two routes
could share a path and be told apart by the header. It is the most tempting rejected option on this
list, because it looks like negotiation written down where a reader can see it. It is wrong twice.

**It answers `404` where the right answer is `406`.** `UrlMatcher::handleRouteRequirements()` returns
`REQUIREMENT_MISMATCH` when a condition evaluates false (`UrlMatcher.php:218-225`), the matcher moves
on, and when nothing else matches, line 83 throws `ResourceNotFoundException`. So a client asking for
a media type the resource does not offer would be told the resource does not exist. Rule 3 exists to
make that distinction and this mechanism cannot express it.

**And a regex cannot read an `Accept` header.** `Accept: text/html;q=0.1, application/ld+json;q=0.9`
prefers JSON-LD, and `matches '/text\\/html/'` says HTML. Quality values, wildcards and ordering are
what `Symfony\Component\HttpFoundation\AcceptHeader` is for, and it sorts by descending quality
(`AcceptHeader.php:98`); reimplementing that inside an expression string is a parser in a config file.
`ContentNegotiationProvider` already calls the real one.

The general form of this is worth keeping: **the router matches a path, and negotiation reads a
header, and a design that asks one to do the other loses the vocabulary of the one it displaced.** A
route condition is still the right tool for something that genuinely is a routing fact, such as a
hostname or a method; `Accept` is not one.

**Two URL spaces.** "The mistake that taught me this", above.

## A page needs more than its resource holds

Here is the problem the first draft did not have an answer for, and it is the one that actually
decides whether this design survives contact with the board.

`GET /members` as JSON-LD is a page of members and nothing else. `GET /members` as a page is a
header with the signed-in member's handle and badge, a navigation bar, an unread count on the
laundry line, and then a page of members. Three of those four are not in the representation, and
they must not be put there: the moment the resource grows a `currentMember` member so a template can
draw a header, the API has grown a field that exists for a template, every client pays for it
forever, and rule 2 is broken in the only way that matters.

**A page composes; a resource does not grow.** Two mechanisms, and the choice between them is per
fragment rather than per application:

- **an ESI fragment** for anything the server can render and cache on its own terms. `framework.esi`
  plus `render_esi()` in the template, and `api-platform/http-cache` is already installed, so the
  cache headers this depends on are already a thing this tree thinks about. A navigation bar is the
  worked example: it varies by principal, not by resource, and it wants its own TTL;
- **an async call from the page** to another resource URL, for anything a reader can wait a moment
  for. The unread count is the worked example. **This is the mechanism that keeps the API honest**,
  because the page fetches `/laundry-line/unread` exactly as any other client would, and a field
  nobody can fetch over the API is a field the page cannot draw either.

The second one has a property I want on purpose: it makes the HTML representation a *consumer* of
the JSON one, which means a page that is hard to build is evidence about the API rather than a
reason to special-case the page.

**What neither of them is: a fatter provider.** A provider that returns the members plus the
navigation plus the unread count is answering three questions, and two of them belong to other
resources. If a page needs data from three resources, it makes three requests; whether they happen
on the server through ESI or in the browser through `fetch` is a caching decision, not an
architectural one.

## Who is asking, per representation

This section amends [ADR 008](008_security.md). Its four questions and their four homes are
unchanged; what changes is that its per-delivery table described four deliveries with four entry
points, and two of those now share a URL.

### Two firewalls, chosen by the credential, never by the path

This section said "one firewall, two authenticators" and reasoned from a premise that is only a
default: **"a Symfony firewall is matched by path".** It is matched by a `RequestMatcherInterface`,
and `pattern` is sugar for the path one; `request_matcher` is a first-class option on every firewall
(`security-bundle/DependencyInjection/MainConfiguration.php:218`). I concluded "merge them" from a
constraint the framework does not impose, and the code then did something else again, which is how
this got found.

What the code did was worse than either: two firewalls told apart by an **enumerated path list**,
`^/(login|logout|badge-designs|register|me)(/|$)` for the browser and `^/` for everything else. That
is rule 1 defeated at the security layer. `GET /badge-models` declares `text/html` and sits outside
the list, so a browser's session cookie reached a `stateless` firewall that never reads one, and the
page answered `302 /login` to a reader who had just signed in. Measured, not reasoned: the same URL
with a bearer token answered `200 text/html`. **The HTML half of this entire document was reachable
only by a client that would never ask for it.**

So the firewalls stay two, and what tells them apart stops being the path:

| firewall | matches | authenticator | session |
|---|---|---|---|
| `api` | the request carries `Authorization: Bearer` | `access_token` | `stateless` |
| `web` | everything else | `form_login`, then the session | yes |

**A firewall's job is to know what a credential is worth, so the credential is what should select
it.** A browser asking for a page, an `<img>` asking for a badge and a `fetch()` asking for JSON-LD
all carry the same cookie and no bearer token, so all three land on `web` and all three are the same
reader; that is the case the path list got wrong, because only the first of the three asks for HTML.

An anonymous request lands on `web` whatever it wanted, and that is harmless because
`SecurityEntryPoint` already content-negotiates the refusal under rule 11: a browser gets `302 /login`
and a JSON client gets a `401` problem document. The entry point was built for this and it is the
reason the split can be this coarse.

**Rule 8 cannot be implemented while `api_platform.defaults.stateless` is `true`**, and that was
found by a `500` rather than by reading. The flag becomes the `_stateless` request attribute
(`api-platform/symfony/Routing/ApiLoader.php:117`), and Symfony's `AbstractSessionListener` throws
`UnexpectedSessionUsageException` in debug when a request declared stateless touched the session.
The instant the session firewall started covering API routes, the HTML representation answered
*"Session was used while the request was declared stateless"*. It is `false` now, globally rather
than per operation, and globally is the point: **the session is used because of the credential, not
because of the representation.** A cookie-bearing `fetch()` asking for `application/ld+json` uses it
exactly as the page does, so flipping only the operations that declare `html` would be the path list
again, written in a different file. Nothing is lost on the bearer path, which starts no session; what
is gained on the cookie path is `Cache-Control: private` on a per-reader document, which `_stateless`
was suppressing.

`/logout` is declared on both firewalls for a related reason, and it is the clearest small example of
what changing the selector costs. The logout listener runs per firewall, so once a request carrying a
bearer token is matched by `api`, nothing clears anything and routing falls into `LogoutController`,
whose entire design is that its body is unreachable. Measured: `302` without the header and `500`
with it. **A route that was implicitly owned by a path pattern has to be explicitly owned by both
firewalls once the pattern goes.**

Keeping them apart rather than merging is a decision and not an accident. One firewall cannot be half
`stateless`, and the two halves genuinely differ in more than an authenticator: one mints a session
and owes CSRF, the other mints nothing and owes a `WWW-Authenticate`. `Actor` already carries an
`Authority` saying which one happened, and `TokenCurrentActor` and `SessionCurrentActor` are already
separate classes; the firewall config now matches the shape the rest of the code already had.

### The session is PHP's, the token is ours

**A browser session is a PHP session and nothing of ours is stored for it.** I had a credential row
carrying the session and it was wrong: a session is already a cookie naming a server-side file that
Symfony manages, expires and garbage-collects, and putting a row beside it buys a second lifetime to
keep in step with the first.

**A bearer token is ours, and if it is revocable it is a JWT with a `jti`.** The revocation list is
a table of `jti` values marked revoked, checked on each request; the token itself stays a token and
carries its own claims. The shape I shipped in the security work, where the credential row's primary
key *was* the opaque token, is worse than either: it stores the bearer token in the clear, and
revoking a token destroys the password login that happens to share the row.

So the table splits in two, and the split is the answer to "what is this row for":

| table              | holds                                               | lifetime                                  |
|--------------------|-----------------------------------------------------|-------------------------------------------|
| `ui_credential`    | an identifier a human chose, a password hash, roles | the account's                             |
| `ui_revoked_token` | a `jti`, a revoked-at instant                       | until the token would have expired anyway |

A session appears in neither, because a session is not ours.

### Signing in with a name a human chose

**Nobody signs in with a `Member` identifier.** [ADR 002](002_identifying-everything.md) makes every
identifier a ULID and [ADR 007](007_translation.md) rule 9 already says a ULID is a value the reader
cannot use: it is on no screen and in no password manager. A login form asks for an email address or
a username, both deliveries ask for the same one, and the credential row carries it.

This is the same argument ADR 007 used to take identifiers out of failure messages, arriving one
layer down. If a value is not something a person can type from memory or read off a screen, it is
not a value you can ask a person for.

The JSON sign-in rule 16 adds takes the same handle under the same name: the body is
`{"username": ..., "password": ...}`, not a ULID and not an IRI. The form half posts `_username` and
`_password`, which is Symfony's convention for a form rather than a second vocabulary of ours.

### Registration is the one write with no permission

`POST /members` is not guarded and never will be. There is no `membership.member.register` permission
to declare, because a permission is a question about an actor and there is no actor yet: registering
**is** the transition from nobody to somebody.

ADR 008 rule 7 already covers this and I did not notice it applied here: "an operation with no actor
is *unguarded* rather than privileged". `bin/console app:access` must therefore report
`POST /members` as deliberately unguarded rather than as a gap, and it must be able to tell the
difference; a command that cannot distinguish "nobody decided" from "somebody decided no" reports
noise.

### Registration is also where the password is set, and it ends at `/login`

`POST /members` writes a `ui_credential` row and dispatches `RegisterMember`. The first build of the
page did neither: it dispatched the command and returned, so a member who registered had no
credential, no way to sign in, and no way to find that out. `Credential::issue()` had exactly one
call site in the whole tree and it was `bin/console app:credentials:issue`, which is an operator's
tool and not a front door.

**So the registration payload carries what rule 10 says a person signs in with**, an email address or
a username, plus a password, beside the handle and the badge. Two different kinds of thing in one
request, and that is correct rather than a compromise: rule 10's identifier belongs to the account and
the handle belongs to the member, and a registration is precisely the moment both come into being.

**It writes the member first and the credential second.** The two live in different stores and no
transaction spans them, which is [ADR 003](003_store-the-aggregate.md)'s standing position rather than
a shortcut here. The order is the recoverable one: a member with no credential is an account an
operator finishes with `app:credentials:issue`, and a credential with no member is a login that leads
nowhere. If this ever has to be atomic, ADR 003 path 5 is the mechanism and the erasure saga is the
worked example.

**A successful registration ends at `/login`, not at the feed.** The alternative was to authenticate
the member there and then, which is one `UserAuthenticatorInterface::authenticateUser()` call and
genuinely nicer to use. I am not doing it, and the reason is rule 1: `POST /members` is one URL with
two representations, and a JSON-LD client posting to it must not be handed a session cookie. **The
firewall is the only thing in this design that starts a session**, and a state processor that signs
somebody in is a second one wearing a different name. The cost is real and small: a person types a
password and is asked for it again a second later. If that ever outweighs the rule, the honest way to
buy it is a `Ui/Web` route that registers and authenticates, which is a second URL for one write and
therefore reopens rule 1 rather than quietly excepting it.

**The `201` names the member, not the registration.** `Location` is `/members/{memberId}`, which
`MemberResource`'s `Get` answers, and the script follows it. Before this was fixed the header carried
a skolem IRI, `/.well-known/genid/{id}`, because `MemberRegistrationResource` declares a `Post` and no
item operation, so API Platform's IRI converter falls through `OperationNotFoundException` into
`generateSkolemIri()`. Every successful registration navigated the reader to a `404`, and the card's
own worked example promised a URL that nothing in the tree produced.

### Signing in twice, and the two credentials that are not two representations

`POST /login` sent as a form answers with a session and a redirect. The same route sent as JSON
answers with a bearer token. That is rule 16, and I want to argue first about what it is **not**,
because the wrong reading is the attractive one and I held it for an hour.

The wrong reading is rule 1: one URL, two representations, chosen by `Accept`. It is wrong because
rule 1 is about a resource, and a resource is one noun whose state gets rendered more than one way.
Here nothing is being rendered twice. Two different credentials are being minted:

|                      | the server keeps        | ended by                      | survives a restart      | CSRF applies |
|----------------------|-------------------------|-------------------------------|-------------------------|--------------|
| session              | all of it               | destroying the row            | as long as the store does | yes        |
| bearer token         | a `jti`, once withdrawn | a row in `ui_revoked_token`   | it is the client's      | no           |

Not one cell of that table is a rendering choice. A session is not a JSON serialization of a token
and a token is not an HTML serialization of a session; they have different lifetimes, different
revocation stories and different threat models. Calling them one resource in two media types would
be the forced unification this document spends fifteen rules refusing elsewhere.

So rule 16 is rule 8 applied one step earlier. **Rule 8 says the firewall is chosen by the credential
a request carries; rule 16 says a request that carries none and asks for one is answered with the
credential its encoding implies.**

#### The selector is `Content-Type`, which is the one place in this document I negotiate on the request

Everywhere else the question is "how would you like this rendered", and the answer is in `Accept`.
Here the question is "which of two credentials did you come for", which is a fact about the caller
rather than about the response, and the honest signal for it is what they actually submitted.

I tried `Accept` first and it fails on a case this repository already has: a page's own `fetch()`
sends JSON and asks for `application/ld+json`, so `Accept` cannot tell a first-party script from a
third-party client. `Content-Type` describes the thing being submitted and nothing else.

The mechanism is two lines of firewall configuration and both are load-bearing.
`JsonLoginAuthenticator::supports()` claims a request whose format or content type is JSON
(`symfony/security-http 7.4, Authenticator/JsonLoginAuthenticator.php:66`). `FormLoginAuthenticator`
claims **every** `POST` to `check_path` unless told otherwise (`:71`), so without `form_only: true`
beside it the form authenticator swallows a JSON body and refuses it for a missing `_username`,
before the JSON one is ever asked.

#### Why it is on the stateful firewall, and why that is not a contradiction

A sign-in carries no `Authorization: Bearer`, so `BearerTokenRequestMatcher` does not match it and
the `web` catch-all takes it. That is rule 8 working rather than rule 8 being bent: there is no
credential to select on.

The tempting fix is to widen the matcher to "the body is JSON". It is wrong twice. It would put every
`fetch()` this application's own pages make onto a `stateless` firewall, cookie and all, which is the
exact defect rule 8 was written to remove. And it would put a route back into a matcher that a whole
commit went into emptying.

So one firewall issues two credentials and one of them is stateless.
`Shared/Infrastructure/Security/AccessTokenGrant` is where that is made true, and it is one line: the
token storage is emptied after the token is minted, because
`AuthenticatorManager::handleAuthenticationSuccess()` sets it before calling a success handler
(`Authentication/AuthenticatorManager.php:241`) and `ContextListener` would otherwise serialize it
into a session on the way out (`Firewall/ContextListener.php:175`). The line is not a workaround, it
is the sentence: **issuing a bearer token is not signing anybody in.** Measured: the JSON sign-in
response carries no `Set-Cookie` for `PHPSESSID`, and the form one does.

#### Why there is no `POST /tokens` and no state processor

A resource was the house style and I wanted it: a `TokenResource`, a processor, API Platform's
serializer, a line in the OpenAPI deliverable for free, and rule 2 kept whole without an argument.
I refused it on a sentence this repository had already written, in
`Ui/Web/LoginFormController`'s docblock:

> A controller that verified a password would be a second home for authentication, in a delivery,
> which is the shape three ADRs have now removed from three different places.

A state processor is a controller with a better name. Verifying a password there skips
`CheckPassportEvent` and everything listening on it, needs its own timing-safe comparison and its own
rehash-on-login, and puts ADR 008's first question in the one layer ADR 008 says it must never be in.
The firewall authenticates; `AccessTokenGrant` answers afterwards.

Rule 2 survives this, and by its own terms rather than by exception. The rule reserves the turning of
**state** into a representation for one class, because a provider or a read model that branches on
the format has become two of itself. Nothing is provided here. There is no resource, no state and no
serializer context; there is a credential that did not exist a line earlier. That is the argument
`SecurityEntryPoint` already makes for why a refusal may read the request format, and a grant has no
more to fork than a refusal does. The two classes are mirrors: one is the only thing `/login` can say
when it says no, the other the only thing it can say when it says yes.

The response is `application/json` and carries no IRI, which is also deliberate. An `@id` promises
the thing can be fetched there, and a `GET` on a bearer token would serve the credential to whoever
asked. `expiresAt` is published because it is the one thing a client must act on, and `jti` is not,
for the same reason a refusal does not name a permission; the client already has it as a claim inside
what they were handed. `200` rather than `201`, because a `201` promises a `Location` and the
paragraph above is why there is none.


## Refusing, three times

One refusal, three representations, and the entry point picks by `Accept`. The statuses are ADR 008
rule 11's and do not move.

|                         | `text/html`               | `application/ld+json` | JSON-RPC                          |
|-------------------------|---------------------------|-----------------------|-----------------------------------|
| not authenticated       | `302` to `/login`         | `401`, RFC 9457       | `401` in the status line          |
| authenticated, refused  | `403`, the error template | `403`, RFC 9457       | `200`, `Forbidden` (`-32005`)     |
| not found, or not yours | `404`, the error template | `404`, RFC 9457       | `200`, `NotFound` (`-32000`)      |

I got that last column wrong twice in one table when I first wrote it, and the shipped
`Shared/Infrastructure/ApiPlatform/JsonRpc/ErrorCode` is the authority: `-32000` is `NotFound` and
`-32004` is `BadRequest`, so I had transposed them, and **there is no case for `401` at all, by an
explicit decision that enum's docblock records.** The codes are ADR 006's and this document does not
get to restate them from memory; cite the enum.

Three notes, one per column.

The browser column is a redirect because a person who is not signed in wants the login page, not a
document describing their predicament. The `403` and `404` are Symfony's own error templates, at
`templates/bundles/TwigBundle/Exception/error403.html.twig` and `error404.html.twig`, which is a
mechanism that already exists and that I do not need to reinvent.

The JSON column is RFC 9457 with all five members populated: `type` as the problem's IRI, `title` as
a short stable phrase ("Authentication required"), `status`, `detail` as a sentence that explains
without being precise, and `instance` as the IRI of the thing being asked for. ADR 008 rule 14 still
governs `detail`: a refusal says nothing about permission names, identifiers, or what would have
worked.

The JSON-RPC column is unchanged from [ADR 006](006_rest-vs-rpc.md), with the one exception ADR 008
already carved out: `401` is the only refusal that leaves `POST /rpc` in the status line, because
RFC 9110 requires a `WWW-Authenticate` challenge that a JSON-RPC response object cannot carry.

### The table above was true of half the application, and I had not checked the other half

Rule 11 says the entry point content-negotiates the refusal, and the entry point does. What I never
measured until now is what happens **after** it, and the answer was that the three columns held for
every URL API Platform owns and for none of the ones it does not.

Anonymous `GET /me/badges` with `Accept: application/ld+json`, which is a Twig route, answered this:

```json
{"type":"https://tools.ietf.org/html/rfc2616#section-10",
 "title":"An error occurred",
 "detail":"shared.not-authenticated",
 "class":"Symfony\\Component\\HttpKernel\\Exception\\HttpException",
 "trace":[]}
```

`GET /members/{memberId}/badges`, the same refusal one route over, answered the document the table
promises. Three of the five RFC 9457 members were wrong and the fourth was worse than wrong:
`detail` was the **raw declared code**, which is the single string ADR 007 exists to keep out of a
reader's hands. `type` pointed at a dead RFC 2616 anchor, so a client routing on it needed a second
rule for refusals that happen to come from a page rather than a resource. And `trace` published our
file paths to anybody who sent an `Accept` header.

The cause is one early return.
`ApiPlatform\Symfony\EventListener\ExceptionListener::onKernelException()` leaves immediately
unless the request carries `_api_respond`, which no `Ui/Web` route does, so Symfony's own
`ErrorListener` rendered a `FlattenException` instead. **The vendor's name for that early return is
`handle_symfony_errors`** (`api-platform/symfony 4.2, EventListener/ExceptionListener.php:39`), it
defaults to false, and turning it on hands every kernel exception to the same listener the API has
always used. `ErrorTypingProvider` then runs on a Twig route, so the tag URI, the catalogue sentence
in the negotiated locale, the title and the `instance` all arrive, and so does `exception_to_status`.

The browser is untouched by the flag, and this is the part worth knowing before anybody panics about
it: `ErrorListener::duplicateRequest()` falls back to Symfony's `error_controller` whenever the
negotiated format is `html`, so `error403.html.twig` and `error404.html.twig` still render. The flag
widens which requests are **offered** the negotiation; it does not change what the negotiation
decides.

#### The other half needed a second list, and a second list is a second answer

With the flag on, a session whose member row had been erased still answered `500` to a browser while
answering `404` to a client. That is not rule 11 half-met, it is rule 11 broken, because a refusal
that is a `404` to a machine and a `500` to a human is two refusals wearing one status table.

The reason is that two listeners decide a status and they read different lists. API Platform's
`getStatusCode()` walks `exception_to_status` and is only reached on the non-HTML branch. Symfony's
`ErrorListener::logKernelException()` walks `framework.exceptions`
(`symfony/http-kernel 7.4, EventListener/ErrorListener.php:54`) and is what the HTML branch is left
with.

So the mapping has to exist in both shapes, and the thing I refused to do is write it twice. Symfony
configuration here is PHP, so `config/packages/api_platform.php` declares the eight markers once in a
variable and spreads them into `exception_to_status` while deriving `framework.exceptions` from the
same array. `config/packages/security.php` already set the precedent of declaring a `framework` key
beside the thing that requires it.

**Only our eight markers go in, and the line is not arbitrary.** They are the interfaces in
`Shared/Domain/Exception/`, which is to say the ones our code throws and therefore the only ones a
Twig page can raise. The four vendor entries stay out because they are raised inside API Platform's
pipeline, which no `Ui/Web` route enters.

Keeping them out is also what protects the `422`, and this is the bug I nearly wrote. Symfony's
listener does not merely read its list, it **replaces the throwable** with an `HttpException` that
carries the mapped status (`:60`). The `422` is the one answer in this application assembled from the
exception object itself: `ValidationException::getId()` composes the problem IRI out of the violation
codes and `ValidationExceptionFactory` builds the `violations` array. An entry for
`ValidationException` in `framework.exceptions` would hand that pipeline a wrapper. I measured both
of ADR 005's designs before and after and both documents are byte for byte identical, which is the
only reason I am willing to say the risk is closed rather than avoided.

| `Accept` on `GET /me/badges`, member erased | before | with the flag | with both |
|---------------------------------------------|--------|---------------|-----------|
| `application/ld+json`                        | `500`  | `404`         | `404`     |
| `text/html`                                  | `500`  | `500`         | `404`     |

#### The redirect was the one response in the tree that negotiated and did not say so

`api_platform.defaults.vary` puts `Accept` on everything `AddHeadersProcessor` touches, and the
refusal redirect is built by the firewall before routing, so no processor ever sees it. Measured:
`GET /me/badges` anonymous answered `302` with `Vary: Accept-Language` and nothing else, while the
same URL in the same state answered a `401` problem document to `Accept: application/ld+json`. A
shared cache keyed on the URL alone would have served the login redirect to the client. The entry
point sets the header itself now, which is three lines including the reason.


## The rules this obeys

1. **One resource, one URL.** A representation is chosen by `Accept`; it is never chosen by a path
   prefix, a subdomain, a query parameter or a route suffix.
2. **Exactly one class of ours turns state into a representation, and it is the rendering
   processor.** A provider, a handler, a read model and every processor that is not that one are
   identical for every representation; a second class that renders by branching on the format is a
   second seam, and the whole point is that there is one. This rule first said "reads the request
   format", which was too wide and was contradicted twice in a fortnight by code that was right:
   `SecurityEntryPoint` reads it because rule 11 tells it to, and a firewall's request matcher may
   read it for rule 8. **Reading the format to decide how to refuse, or whom to ask, is not
   rendering**, and the rule now says which of the three it governs.
3. **An operation declares its representations with `outputFormats:`.** A media type an operation
   does not have is a `406`, never a degraded rendering of one it does have. The framework already
   raises it; `ContentNegotiationProvider` is the file. Do not parse `Accept` yourself, do not
   invent a format name, and do not add a `_format` route suffix. **A representation need not be a
   document**: `image/svg+xml` is an `outputFormats:` entry like any other, and a file extension is
   never how one is chosen.
4. **A resource page has no controller.** `Ui/Web/` holds only what no resource represents: an empty
   form, the login and logout routes, and assets that are a pure function of their path.
5. **A `Ui/Web` controller never writes a resource.** It serves a `GET`; a form it renders posts to
   the resource's own URL. A controller with a `POST` on a resource path is a second way to write
   one thing. A capability that is not a resource, because ADR 006's tests put it on `POST /rpc`,
   has no representation and so its page is a controller whose actions reach the domain directly.
   **A controller never calls an RPC method class**; it parses and dispatches as the method does,
   which is ADR 006 rule 10 with three envelopes instead of two.
6. **A form is submitted by the script, never natively.** Every resource write, `POST` included,
   goes by `fetch()` with the real method and `Accept: application/ld+json`, and its `422` is
   painted onto the fields by `propertyPath`. No `_method` override, and no `POST` alias for a
   `DELETE`. **No write operation has an HTML representation**, so none declares `html` and none has
   a template, and `error_formats` therefore only ever renders a navigation failure. A `201`
   and a `202` are both a `Location` the script follows; watching what it lands on is that page's
   business, by rule 12. **The form carries `data-action` and `data-method` and neither `action` nor
   `method`**, so with the script off it submits a `GET` to the page it is on and reaches no write at
   all. A `409` is never painted onto a field: ADR 005 makes a conflict non-collectable and its
   problem document carries no `propertyPath`, so it is a page-level notice.
7. **A resource page's template is named for the resource and the operation**, at
   `templates/<context>/<resource>/<operation>.html.twig`. A controller page keeps ADR 001's
   `templates/<context>/<page>.html.twig`, because there is no operation to name it for.
8. **Two firewalls, chosen by the credential the request carries, never by the path.** A request
   with `Authorization: Bearer` is the `api` firewall's and is `stateless`; everything else is the
   `web` firewall's and may have a session. A path list cannot do this, because one reader's page,
   images and `fetch()` calls ask for three different media types with one cookie.
9. **A session is PHP's and is stored nowhere of ours. A bearer token is ours, and a revocable one
   is a JWT whose `jti` is the row.**
10. **Credentials are identified by something a human typed**, an email address or a username, never
   by a ULID.
11. **The entry point content-negotiates the refusal.** A browser is redirected; a client gets RFC
   9457; a JSON-RPC caller gets an error object. The status codes do not change between them, except
   where ADR 006 already says a `200` carries the error.
12. **A page composes; a resource does not grow.** Anything a page needs that its resource does not
    hold arrives as an ESI fragment or as an async call to the resource that does hold it. A member
    added to a representation so that a template can draw something is the failure this rule exists
    to name.
13. **An operation with no actor is unguarded and says so, with `#[Unguarded(because: '...')]`.**
    `app:access` distinguishes "deliberately unguarded" from "unguarded and nobody noticed", and
    `POST /members` is the first of the former.
14. **One route, one action, one controller file, and the guard is a class attribute.** A `Ui/Web`
    controller has a single `__invoke()` and declares `#[Guarded]` or `#[Unguarded]` on the class, so
    `app:access` can walk the directory as it walks resources, RPC methods and commands. A controller
    with five actions is five routes that nothing can enumerate.
15. **`/me/...` and `/members/{principal}/...` are one resource seen from two perspectives, and the
    explicit one is canonical.** The first resolves the principal and can reach nobody else; the
    second admits that with a permission and an identifier it could. They answer the same
    representation, and the `/me` one carries `<link rel="canonical">` to the explicit URL, exactly
    as `/` does for the feed.
16. **A request that carries no credential and asks for one is answered with the credential its
    encoding implies.** `POST /login` as a form mints a session and redirects; as JSON it mints a
    bearer token and starts nothing. This is rule 8 one step earlier, and it is deliberately not
    rule 1: a session and a token are two credentials, not two renderings of one. The selector is
    `Content-Type`, the only place in this document that negotiates on the request, because the
    question is which credential the caller came for rather than how they would like it drawn.

### How an operation says it is deliberately unguarded

Rule 13 asked `app:access` to tell a decision from an oversight and gave it nothing to read, which
is the kind of hole that only shows up when somebody tries to build it. `#[Guarded]` takes a
permission, and the absence of `#[Guarded]` is exactly the ambiguity in question.

**A sibling attribute, `#[Unguarded(because: '...')]`, and the prose argument is required rather
than decorative.** `POST /members` carries "registration is the transition from nobody to a member,
so there is no actor to ask a question about", and `app:access` prints that sentence in the row
instead of counting the operation as a gap.

Three things make this the shape rather than a list inside the command. It is a declaration beside
the operation, so it moves when the operation moves, which is the argument ADR 008 already made for
putting the permission enums next to the resources. It is **enumerable**, so `app:access` walks the
set rather than parsing anything, which is the argument ADR 007 made for `FailureCode` cases over an
AST extractor and ADR 006 made for one class per RPC method. And a required sentence makes the
decision cost something to take: a hardcoded path list inside the command would let the next
unguarded write join it silently, which is the drift the command exists to catch.

Exit non-zero remains the behaviour for anything unguarded and undeclared. A declared one is
reported and does not fail the run.

**A class carrying both attributes is a contradiction and `app:access` says so**, exiting non-zero
with the two declarations quoted rather than silently preferring one. I am writing that down because
the alternative is a precedence rule, and a precedence rule here would mean somebody can make a
guarded operation unguarded by adding a line rather than by removing one. The two attributes are
answers to the same question and only one of them can be true; deciding which quietly is exactly the
drift the command exists to catch.

This ADR amends two rules elsewhere, narrowly and by name.

**[ADR 001](001_architectural-organization.md), "A page is a delivery, so it lives where the
deliveries live"**: the sentence `Ui/Web/<Context>/<Name>Controller.php` is where a Twig delivery
lives now applies only to pages that are not a resource representation. Everything else in that
section stands, including "templates are not source", "`Ui/Web` may name a context's `Application`"
and "a delivery may compute presentation, and nothing else", which is `BadgeRenderer` and is
untouched.

**[ADR 008](008_security.md) rule 3, "four questions, four homes"**: unchanged. What changes is the
per-delivery table underneath it, where ReST and Twig now share a firewall and differ by
authenticator. Rule 18 is appended there rather than edited in.

## If you are adding a page

1. is there already a resource at this URL? If yes, you are adding a representation, not a page, and
   you write a template and nothing else;
2. name the template `templates/<context>/<resource>/<operation>.html.twig`. The rendering processor
   resolves it from the operation; there is nothing to register;
3. list what the page needs that the resource does not hold, and decide per item: an ESI fragment if
   the server should render and cache it, an async call to the owning resource if a reader can wait.
   Never a new member on this representation;
4. if the operation should *not* have an HTML representation, say so on the operation with
   `outputFormats:` rather than by leaving the template out. A missing template and a declared
   absence answer the same `406` and only one of them is readable;
5. if there is no resource at this URL, ask [ADR 006](006_rest-vs-rpc.md)'s three tests whether there
   should be. A login form fails all three and belongs in `Ui/Web/`; a member directory passes them
   and is a resource that happens to have an HTML representation;
6. do not write a controller for a resource page. If you find yourself needing one, the provider is
   missing something and the controller is about to hide it;
7. guard it exactly as the JSON representation is guarded, which is to say do nothing: the operation
   already carries `#[Guarded]` and the firewall already ran;
8. if it *is* a controller page, give it its own class with one `__invoke()` and put
   `#[Guarded]` or `#[Unguarded(because: '...')]` on the class. Do not add an action to a controller
   that already has one; that is rule 14, and the reason is that `app:access` walks classes;
9. if the form on it writes a resource, spell it `data-action` and `data-method` with no `action` and
   no `method`, so the page cannot reach a write when the script is absent;
10. check `bin/console app:access` still exits the way it did. A new representation adds no row; a new
    controller page adds exactly one.

## What this ADR still owes

- ~~**the rendering processor does not exist yet**~~. It does:
  `Shared/Infrastructure/ApiPlatform/State/RenderingProcessor` decorates the main processor and resolves
  `templates/<context>/<resource>/<operation>.html.twig`. `html` is still deliberately out of the
  global `formats`, so an operation that has not declared it answers `406`, which is rule 3 working
  rather than a gap. What the debt became instead is narrower and is listed two bullets down: the
  processor now owes a second branch, for `image/svg+xml`, and that branch is the first test of
  whether rule 2 was written about the reading or about HTML;
- **ESI is decided and unconfigured.** `framework.esi` is off, `api-platform/http-cache` is
  installed but nothing renders a fragment, and the first page card that needs a navigation bar is
  where this gets built. The mechanism is chosen; the cache policy for a per-principal fragment is
  not, and it is the part that will be got wrong;
- **CSRF is unresolved across the two representations.** A form post needs a token and a bearer-token
  post does not, and those are the same operation reached two ways. I think the answer is that CSRF
  protection is the session authenticator's concern and applies when the session authenticated the
  request, but I have not built it and I am not deciding it in a bullet point;
- **nothing, on the `/api` prefix.** It is out of `config/routes/api_platform.php` again with a
  comment saying why it stays out, and I checked rather than assumed that no document followed it
  while it was wrong: every URL in `docs/` and `.claude/` is still unprefixed, and the one
  `GET /api/members` left in this file is the rejected option being quoted;
- **twenty plans and thirteen cards describe controllers this ADR deletes**, and twenty eight plans
  name a template path it renames. They are wrong now; correcting them is mechanical and is not this
  document's job, but nobody should implement one before it is done;
- **rule 15 and the badge collection section cannot both be followed for one pair of URLs, and that is this
  document arguing with itself rather than code failing to follow.** Rule 15 says `/me/badges` and
  `/members/{memberId}/badges` are one resource seen from two perspectives and must answer the same
  representation. "A capability with no resource has no representation, so its page is a controller"
  says the badge collection is a `Ui/Web` page precisely because nothing there is a resource. Measured today:
  the `/me` one answers HTML to every `Accept` and the explicit one declares no `html` and answers
  `406` to a browser, so the canonical target refuses the media type the canonicalizing page was
  served as. One of the two sections has to give and I am not deciding which in a bullet;
- **rule 16 settles nothing about CSRF and makes the open question above sharper.** A form sign-in is
  protected and a JSON one is not, deliberately, because there is no cookie for a third party to
  forge with. That is the right answer for `/login` and it is exactly the shape of the unresolved
  one: a *resource write* reached by both a form and a bearer token. The sign-in is the easy case
  because the two halves mint different credentials; a write is the hard case because the two halves
  do the same thing;
- **no test proves any of this**, which is the same debt ADR 008 closed on and the reason it is worth
  saying twice. `tests/` still holds no test cases.
