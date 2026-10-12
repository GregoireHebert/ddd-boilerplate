---
name: rendering-a-representation
description: Add or change an HTML page, a template, a Ui/Web controller or any second representation of a resource in this DDD/CQS tree, following ADR 009 - one URL whose representation is chosen by Accept, html declared per operation and never in the global formats list, one rendering processor, no HTML representation for any write, and every form submitted by the script. Use before writing a controller, a Twig template, an outputFormats entry, a form, or a second URL for something that already has one.
---

# Rendering a representation

The argument is `docs/ADR/009_one-url-two-representations.md`. It is the most
recent decision in the tree, it amends ADR 006 and it supersedes part of
ADR 001's "a page is a delivery" section.

## The one rule everything else follows from

> **A URL is not a delivery.** A page is not a fourth surface with routes of its
> own; it is a second *representation* of an address API Platform already owns,
> chosen by `Accept` exactly as `application/ld+json` is.

So the first question is never "where does this controller go". It is:

> **Is there already a resource at this URL?**

If yes, **you are adding a representation, and you write a template and nothing
else.** No controller, no route, no provider change. `GET /members` runs the
same provider, puts the same query on the same bus and gets the same
`PageOf<MemberRow>` back whoever is asking; only the last step differs.

If no, ask [ADR 006](../../../docs/ADR/006_rest-vs-rpc.md)'s three tests whether
there should be. A login form fails all three and belongs in `Ui/Web/`; a member
directory passes them and is a resource that happens to have an HTML
representation.

## What is already written, and is not yours to build

`vendor/api-platform/state/Provider/ContentNegotiationProvider.php` parses
`Accept`, matches it against the operation's formats and throws the `406` when
nothing matches, all before any provider of ours runs. **Do not parse `Accept`
yourself, do not invent a format name, and do not add a `_format` route suffix.**

`Shared/Infrastructure/ApiPlatform/State/RenderingProcessor` is the one class of
ours that turns state into a representation. It decorates the main processor,
resolves the template from the operation and receives the provider's return
value *before* normalization, so a template reads `member.handle` off the DTO
with every type the query bus just proved.

> **Exactly one class of ours renders** (rule 2). Reading the request format to
> decide *how to refuse* or *whom to ask* is not rendering, which is why
> `SecurityEntryPoint` and a firewall's request matcher may read it.

## `html` is declared per operation, never in `formats`

> **This is the trap, and it cost an hour to find.**

```php
new GetCollection(
    outputFormats: ['jsonld' => ['application/ld+json'], 'html' => ['text/html']],
)
```

A global `'html' => ['text/html']` in `config/packages/api_platform.php` breaks
two things at once:

- `enable_re_doc` is `true` here, so `SwaggerUiProvider` **is** registered
  (`ApiPlatformExtension.php:674` loads it for `enable_swagger_ui ||
  enable_re_doc || enable_scalar`, and reading only the first flag is how this
  was got wrong). It takes over any operation whose request format is `html` and
  answers the documentation page. `GET /members` with `Accept: text/html`
  stopped answering `406` and started answering ReDoc, which is a refusal
  replaced by a wrong success;
- every resource then advertises a representation it does not have. The OpenAPI
  export grew twelve `<Resource>.html` schemas describing a `text/html` response
  with a JSON body, on a document CLAUDE.md calls a deliverable.

`defaults.extra_properties` sets `_api_disable_swagger_provider => true` once so
a declared `html` reaches the rendering processor. The documentation itself is
untouched: `SwaggerUiProvider` returns early for the `OpenApi` class.

> **An operation that should not have a page says so with `outputFormats:`,
> never by leaving the template out.** A missing template and a declared absence
> answer the same `406` and only one of them is readable.

## No write has an HTML representation, and no form submits natively

> **No write operation declares `html` and none has a template** (rule 6).

`error_formats` is status-agnostic, so the `html` that sends a browser's `403`
to an error page would send a `422` there too, and a `422` has to come back to
the form with the typed values intact.

So every resource write, `POST` included, goes by `fetch()` with the real method
and `Accept: application/ld+json`. **`public/form.js` is the one script.**

```html
<form data-action="/members" data-method="POST">   <!-- yes -->
<form action="/members" method="POST">             <!-- no -->
```

> **`data-action` and `data-method`, with no `action` and no `method`**, so with
> the script off the form submits a `GET` to the page it is on and reaches no
> write at all.

- the `422` is painted onto the fields by `propertyPath`, which is ADR 005's
  one-shape promise being cashed in;
- a `409` is **never** painted onto a field: ADR 005 makes a conflict
  non-collectable and its problem document carries no `propertyPath`, so it is a
  page-level notice;
- a `201` and a `202` are both a `Location` the script follows;
- no `_method` override, and no `POST` alias for a `DELETE`.

## When it really is a controller

`Ui/Web/` holds only what no resource represents: an empty form, the login and
logout routes, and assets that are a pure function of their path.

> **An empty form is not a representation of anything**, and **a capability with
> no resource has no representation**, so its page is a controller. The badge
> badge collection is the live case: its writes are RPC methods, so there is nothing for a
> page to be a representation *of*.

Three rules bind a controller:

1. **it never writes a resource** (rule 5). It serves a `GET`; a form it renders
   posts to the resource's own URL. A controller with a `POST` on a resource
   path is a second way to write one thing;
2. **it never calls an RPC method class.** It parses and dispatches as the
   method does, which is ADR 006 rule 10 with three envelopes instead of two;
3. **one route, one action, one controller file, and the guard is a class
   attribute** (rule 14), because `app:access` walks classes. A controller with
   five actions is five routes nothing can enumerate.

Template naming differs, and the difference is the point:

| what it is        | template                                              |
|-------------------|-------------------------------------------------------|
| a resource page   | `templates/<context>/<resource>/<operation>.html.twig` |
| a controller page | `templates/<context>/<page>.html.twig`                 |

**Templates are not source.** They are not autoregistered and they do not live
under `src/`.

## A representation need not be a page

`image/svg+xml` on `/badge-designs/{color}/{motif}` is the proof, produced in
`Ui/Web/Rendering/`. A format is a format; a file extension is never how one is
chosen.

## A page composes; a resource does not grow

> **A member added to a representation so that a template can draw something is
> the failure this rule exists to name** (rule 12).

List what the page needs that its resource does not hold, and decide per item:
an ESI fragment if the server should render and cache it, an async call to the
owning resource if a reader can wait. Never a new member on this representation.

**ESI is decided and unconfigured:** `framework.esi` is off and
`api-platform/http-cache` is installed but nothing renders a fragment. The
mechanism is chosen; the cache policy for a per-principal fragment is not, and
that is the part that will be got wrong.

## Who is asking

> **Two firewalls, chosen by the credential the request carries, never by the
> path** (rule 8).

`Authorization: Bearer` is the `api` firewall's and is `stateless`; everything
else is the `web` firewall's and may have a session. This is why
`BearerTokenRequestMatcher` exists and `pattern` does not appear on the API
firewall: one reader's page, images and `fetch()` calls ask for three different
media types with one cookie.

- **the session is PHP's, the token is ours**, and a revocable token is a JWT
  whose `jti` is the row (rule 9);
- **credentials are identified by something a human typed** (rule 10), never by
  a ULID;
- **`POST /login` selects on `Content-Type`** (rule 16), the one place in the
  document that negotiates on the request, because the question is which
  credential the caller came for rather than how they would like it drawn. As a
  form it mints a session and redirects; as JSON it mints a bearer token and
  starts nothing;
- **the entry point content-negotiates the refusal** (rule 11): a browser is
  redirected, a client gets RFC 9457, a JSON-RPC caller gets an error object.
  The status codes do not change between them.

**A resource page needs no guarding of its own**: the operation already carries
`#[Guarded]` and the firewall already ran. A controller page declares
`#[Guarded]` or `#[Unguarded(because: '...')]` on the class. See the
`guarding-an-operation` skill.

## Checklist

1. is there a resource at this URL? Then write a template and stop;
2. name it `templates/<context>/<resource>/<operation>.html.twig`; the processor
   resolves it and there is nothing to register;
3. add `'html' => ['text/html']` to **that operation's** `outputFormats:`, never
   to `formats`;
4. is it a write? Then it has no HTML representation and no template. The form
   page is a separate `GET`;
5. list what the page needs beyond its resource, and resolve each as a fragment
   or an async call, never as a new member;
6. forms carry `data-action` and `data-method` and nothing else;
7. if it is genuinely a controller, one class, one `__invoke()`, one route, and
   the guard attribute on the class;
8. `bin/console app:access` still exits the way it did. A new representation
   adds no row; a new controller page adds exactly one;
9. `bin/console api:openapi:export` and check the operation did not grow a
   `.html` schema describing a JSON body.

## Known, and not yours to fix in passing

- **CSRF is unresolved across the two representations.** A form post needs a
  token and a bearer-token post does not, and those are the same operation
  reached two ways. Rule 16 settles the sign-in case and sharpens the write
  case rather than answering it;
- **rule 15 and the badge collection section contradict each other for one pair of URLs.**
  `/me/badges` answers HTML to every `Accept` and `/members/{memberId}/badges`
  declares no `html` and answers `406` to a browser, so the canonical target
  refuses the media type the canonicalizing page was served as. That is the ADR
  arguing with itself, and it says so;
- **`.claude/plans/` describes controllers this ADR deletes** and template paths
  it renames. Each affected plan opens with an `## ADR 009 supersedes part of
  this plan` block; read that block before implementing one;
- **no test proves any of this.** `tests/` still holds no test cases.
