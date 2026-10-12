<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use App\Shared\Infrastructure\Security\AccessTokenGrant;
use App\Shared\Infrastructure\Security\BearerTokenRequestMatcher;
use App\Shared\Infrastructure\Security\CredentialProvider;
use App\Shared\Infrastructure\Security\JwtTokenHandler;
use App\Shared\Infrastructure\Security\SecurityEntryPoint;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/*
 * ADR 008, the authentication commit, as amended by ADR 009 rule 8.
 *
 * What left this file, all of it from the untouched Symfony recipe and none of it from this project:
 * the `users_in_memory` provider and its single `admin` user with the password `password`;
 * `http_basic` on the `main` firewall; and both `access_control` rules, which pointed at
 * `^/catalogue` and `^/smartphone`, paths that have never existed here. They were the proof the card
 * opened with: nobody knew who was asking, and the reason it was not a vulnerability was that there
 * was nothing to attack.
 *
 * ## `access_control` answers exactly one question
 *
 * **Does this path need anybody at all.** That is the only question a regex over a URI can answer
 * without being wrong the first time somebody adds a sub-resource with a different audience, and
 * `GET /members/{id}/reputation` is already that sub-resource (membership/10 serves three shapes from
 * one URL). A permission is never named here: permissions are declared on operations and on
 * `Ui/Web` controllers with `#[Guarded]` and asked at the gate.
 *
 * ## Two firewalls, told apart by the credential and never by the path
 *
 * ADR 008 asks for a **stateless** one over the API and `/rpc` (`access_token`, no session, no CSRF,
 * because the API has consumers that are not our webapp) and a **stateful** one over the webapp (a
 * session, a login form, CSRF on every write form, which is what a cookie is for in a first-party
 * client). That part is unchanged: one firewall cannot be half `stateless`, and the two halves owe
 * different things, a session and CSRF on one side and a `WWW-Authenticate` challenge on the other.
 *
 * **What changed is what selects one.** This file used to split them with two path lists,
 * `^/(login|logout|badge-designs|register|me)(/|$)` for the browser and `^/` for everything else,
 * and that is ADR 009 rule 1 defeated one layer below the layer that states it. A resource has one
 * URL and chooses its representation by `Accept`, so every resource with an HTML representation sat
 * outside the browser's list: `GET /badge-models` answered `302 /login` to a reader who had just
 * signed in, because their session cookie reached a `stateless` firewall that does not read one,
 * while the same URL with a bearer token answered `200 text/html`. The whole HTML half of ADR 009
 * was reachable only by a client that would never ask for it.
 *
 * So the firewalls stay two and the selector moves to the credential, which is ADR 009 rule 8:
 *
 * | firewall | matches                                  | authenticator            | session     |
 * |----------|------------------------------------------|--------------------------|-------------|
 * | `api`    | the request carries `Authorization: Bearer` | `access_token`        | `stateless` |
 * | `web`    | everything else                          | `form_login`, then the session | yes   |
 *
 * **A firewall's job is to know what a credential is worth, so the credential is what should select
 * it.** One signed-in reader's page, the `<img>` that draws their badge and the `fetch()` that
 * submits their form carry one cookie and ask for three media types; a path list splits that reader
 * in three, and only the first of the three asks for HTML.
 *
 * **A sign-in carries no credential at all, which rule 16 then finishes.** `/login` therefore lands
 * on `web` whichever of the two it came for, and what it is answered with is decided by
 * `Content-Type`: a form mints a session, JSON mints a bearer token through
 * {@see \App\Shared\Infrastructure\Security\AccessTokenGrant}. That is the `json_login` block on the
 * `web` firewall below, and it is why the stateful firewall issues something stateless.
 *
 * `request_matcher` is a first-class firewall option and replaces `pattern` outright
 * (`security-bundle/DependencyInjection/SecurityExtension.php:370`), so `api` declares no path at
 * all and `web` is the catch-all. Order still matters, because Symfony uses the first firewall whose
 * matcher matches: `dev` first so the profiler is never secured, then `api`, then `web` last because
 * it matches everything. {@see BearerTokenRequestMatcher} is the whole of the decision and its
 * docblock argues the two narrownesses that make it safe.
 *
 * An anonymous request now lands on `web` whatever it was asking for, and that is harmless rather
 * than tolerated: {@see SecurityEntryPoint} content-negotiates the refusal under ADR 009 rule 11, so
 * a browser is redirected to `/login` and a JSON client gets the same RFC 9457 document it always
 * got. That is why `web` names the entry point explicitly below instead of letting `form_login`
 * supply its own, which would redirect a JSON client into a login page.
 *
 * ## What this does not fix, said out loud
 *
 * **The router-level collision is still there and is a different problem.**
 * `config/routes/api_platform.php` mounts API Platform at `/` and `config/routes/web.php` mounts the
 * Twig delivery's attribute routes at `/` as well. The day a page card wants a directory at
 * `/members` it collides with the API collection of the same name, in the router, before a firewall
 * is consulted. The firewall split no longer depends on those two path sets being disjoint, which is
 * the point of this commit; the router still does, and that decision is not this file's to take.
 *
 * **CSRF across the two representations is unresolved**, which ADR 009 records in "What this ADR
 * still owes" and does not decide. Nothing here turns it on: `form_login`'s `enable_csrf` covers the
 * sign-in form and nothing else, and no resource write goes through Symfony's form component, so
 * widening `web` to the catch-all added no check that was not there before. It also did not remove
 * one. Whoever decides it will decide it for a write reached two ways, which is the hard half.
 *
 * ## The `framework` block below
 *
 * `form_login`'s `enable_csrf` needs the CSRF token manager, which is a `framework` setting. It is
 * declared here, next to the firewall that requires it, rather than in `config/packages/framework.php`,
 * because the requirement comes from the firewall and a reader asking "why is CSRF on" should find
 * the answer in the same file as the question.
 */

return App::config([
    // See the note above: this is the firewall's requirement, declared beside the firewall.
    'framework' => [
        'csrf_protection' => true,
    ],
    'security' => [
        // https://symfony.com/doc/current/security.html#registering-the-user-hashing-passwords
        // Keyed on the interface, which is why `SecurityUser` had to implement it: the hasher is
        // resolved from the user object's class, and a user with no password hash has no hasher.
        'password_hashers' => [
            PasswordAuthenticatedUserInterface::class => 'auto',
        ],
        // https://symfony.com/doc/current/security.html#loading-the-user-the-user-provider
        // One provider, a service, over the `ui_credential` table. Both firewalls use it: the same
        // row answers a form login and a bearer token, and `CredentialProvider` is the seam an
        // identity server would replace without a single call site moving.
        //
        // It is asked for a **username** and never for a ULID (ADR 009 rule 10). The form login
        // below hands it `_username` straight from the form; the bearer token carries a member in
        // its `sub` claim and `JwtTokenHandler` therefore resolves through the provider's other
        // entry point rather than through this one.
        'providers' => [
            'credentials' => [
                'id' => CredentialProvider::class,
            ],
        ],
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt)|css|images|js)/',
                'security' => false,
            ],

            /*
             * STATELESS. Every request that presents a bearer token, whatever it asks for and
             * whatever path it asks it at. ADR 008 gives the ReST API and `POST /rpc` one row each
             * with the same mechanism, so they are one firewall; ADR 009 rule 8 then says the
             * mechanism is what selects it.
             *
             * No session, because the API has consumers that are not our webapp. No CSRF, because
             * there is no cookie to forge a request with.
             *
             * **This comes before `web` and after `dev`**, and the order is the whole of the
             * exclusivity: Symfony uses the first firewall whose matcher matches, `web` matches
             * everything, and `dev` has to stay in front of both so the profiler is never secured.
             *
             * `request_matcher` replaces `pattern` rather than narrowing it, so there is no path
             * here to drift. {@see BearerTokenRequestMatcher} asks one question, whether the request
             * carries `Authorization: Bearer`, and deliberately does not ask whether the token is
             * any good: a malformed one belongs to this firewall's refusal, a `401` with a
             * challenge, rather than being demoted to an anonymous browser and redirected.
             *
             * `JwtTokenHandler` verifies a signed token and then asks `ui_revoked_token` whether its
             * `jti` has been withdrawn, which is ADR 009 rule 9: a revocable bearer token is a JWT
             * whose `jti` is the row. What stood here before made the credential row's primary key
             * *be* the opaque token, so the token was stored in clear text and revoking it destroyed
             * the password login sharing the row. Swapping to an identity server is still
             * `'token_handler' => ['oidc' => [...]]` here and a different provider above, and no
             * call site moves.
             *
             * `entry_point` and `failure_handler` are the two lines here that are about the *shape*
             * of an answer rather than about who may ask, and they name one class because there is
             * one refusal.
             *
             * `access_token` is not an entry point, so without `entry_point` Symfony raises a bare
             * `HttpException(401)` carrying its own English sentence and no challenge;
             * {@see SecurityEntryPoint} raises the declared failure instead, so the refusal is the
             * same RFC 9457 document as every other one, or a redirect to `/login` when the caller
             * asked for `text/html` (ADR 009 rule 11).
             *
             * `failure_handler` closes the hole the entry point alone leaves, and it is the
             * commoner of the two paths. An entry point answers a request with **no** credential;
             * a request with a credential that does not verify never reaches it, because
             * `AccessTokenAuthenticator::onAuthenticationFailure()` short-circuits with
             * `new Response(null, 401, ['WWW-Authenticate' => ...])`: an empty body labelled
             * `text/html`. That is the first `401` most clients meet, and the published OpenAPI
             * document said it was a problem document. Naming the same class here makes both paths
             * agree, which is the point of it being one class.
             */
            'api' => [
                'request_matcher' => BearerTokenRequestMatcher::class,
                'provider' => 'credentials',
                'stateless' => true,
                'entry_point' => SecurityEntryPoint::class,
                'access_token' => [
                    'token_handler' => JwtTokenHandler::class,
                    'failure_handler' => SecurityEntryPoint::class,
                ],
                /*
                 * `/logout` is declared on BOTH firewalls, and it is here because of a 500 that
                 * only exists once a firewall is chosen by a credential rather than by a path.
                 *
                 * Symfony's logout listener runs per firewall. While `web` was selected by
                 * `^/(login|logout|...)`, every request to `/logout` reached it. Now a request
                 * carrying `Authorization: Bearer` is matched by `api` instead, the listener never
                 * runs for it, and routing falls through into
                 * {@see \App\Ui\Web\LogoutController}, whose whole design is that its body is
                 * unreachable: it throws `\LogicException`. Measured: `/logout` answered `302`
                 * without the header and `500` with it.
                 *
                 * Declaring it here makes the listener run on whichever firewall matched, so the
                 * controller stays unreachable and its argument stays true. On a `stateless`
                 * firewall it clears nothing, because there is nothing of ours to clear; a bearer
                 * token is not ended by asking a browser route to forget it, and ADR 009 rule 9
                 * says what does end one, a `jti` in `ui_revoked_token`.
                 */
                'logout' => [
                    'path' => 'app_logout',
                ],
            ],

            /*
             * STATEFUL. Everything else, which is the browser and anybody who turned up without a
             * credential at all.
             *
             * It declares no matcher, so it is the catch-all. That is a wider surface than the
             * enumerated path list it replaces, and the width is the fix rather than a cost: a page,
             * the `<img>` inside it and the `fetch()` it makes are one reader with one cookie asking
             * for three media types at three URLs, and the list could only ever name the first of
             * them. `/badge-designs/{color}/{motif}.svg`, `GET /badge-models` as `text/html` and
             * `GET /members/{memberId}/badge` as `image/svg+xml` are now the same firewall's, which is
             * what they always were to the reader.
             *
             * An anonymous API client lands here too, and that is the case to check rather than the
             * case to fear. `entry_point` is named explicitly for it: with only `form_login`
             * configured Symfony would use that authenticator's own entry point, which redirects
             * everything to `/login`, and a `fetch()` asking for `application/ld+json` would get a
             * login page with a `200` on it. {@see SecurityEntryPoint} reads `Accept` instead and
             * answers a `302` or an RFC 9457 `401` accordingly, which is ADR 009 rule 11 and the
             * reason this split can be as coarse as one header.
             *
             * `lazy` keeps the session unread until something asks who is here, so an anonymous
             * request that needs nobody still starts no session and sets no cookie.
             */
            'web' => [
                'provider' => 'credentials',
                'lazy' => true,
                'entry_point' => SecurityEntryPoint::class,
                'form_login' => [
                    // The same route for both, because `LoginFormController` only ever answers the
                    // GET: the authenticator intercepts the POST before routing. That identity is
                    // also why ADR 009 rule 14's split produced `LoginFormController` and
                    // `LogoutController` rather than a form half and an authenticate half: `logout`
                    // below has a path of its own and is therefore a second route, and `check_path`
                    // does not and is therefore not. Giving it one is a change here before it is a
                    // change in `src/Ui/Web/`.
                    'login_path' => 'app_login',
                    'check_path' => 'app_login',
                    // ADR 008: CSRF on every write form on this firewall, and a sign-in is one.
                    // The defaults are the field `_csrf_token` and the token id `authenticate`,
                    // which is what `templates/security/login.html.twig` emits. It covers the
                    // sign-in form and nothing else; CSRF for a resource write reached two ways is
                    // the open question ADR 009 records and does not answer.
                    'enable_csrf' => true,
                    /*
                     * ADR 009 rule 16, and the line that makes the two sign-ins below disjoint.
                     *
                     * `FormLoginAuthenticator::supports()` claims **every** `POST` to `check_path`
                     * by default (`security-http/Authenticator/FormLoginAuthenticator.php:71`), so
                     * a JSON sign-in would be swallowed here and refused for a missing `_username`
                     * before `json_login` was ever consulted. `form_only` narrows it to
                     * `application/x-www-form-urlencoded` and `multipart/form-data`, which is what
                     * a browser form actually sends and the only thing this authenticator can read.
                     *
                     * It is a narrowing and not a feature flag: nothing that worked before stops
                     * working, because nothing but a form has ever posted here.
                     */
                    'form_only' => true,
                ],
                /*
                 * ADR 009 rule 16. The same route, the same credential store, the same two
                 * handlers' worth of decisions, and a bearer token instead of a session.
                 *
                 * **Why it is on `web` and not on `api`.** Rule 8 selects a firewall by the
                 * credential a request carries, and a sign-in carries none: there is no
                 * `Authorization: Bearer` on the request that asks to be given one.
                 * {@see BearerTokenRequestMatcher} therefore does not match it and `web`, the
                 * catch-all, takes it. Widening that matcher to "the body is JSON" was tried on
                 * paper and is wrong twice over: it would put every `fetch()` this application's own
                 * pages make onto a `stateless` firewall, cookie and all, and it would put a route
                 * back into the matcher that rule 8 spent a commit taking out.
                 *
                 * So the firewall is stateful and one of the two things it issues is not. That is
                 * not a contradiction, it is the distinction rule 16 draws:
                 * {@see \App\Shared\Infrastructure\Security\AccessTokenGrant} empties the token
                 * storage so nothing is persisted, and its docblock has the vendor line numbers.
                 *
                 * `username` and `password` are the default paths and are named anyway, because
                 * ADR 009 rule 10 is a decision about *what the caller types* and a reader of this
                 * file should not have to know a default to see that it is a username rather than a
                 * ULID. The form half posts `_username` and `_password`, which is Symfony's
                 * convention for a form and not ours to align.
                 *
                 * No `enable_csrf`, and it would be meaningless: CSRF protects a credential the
                 * browser attaches by itself, and this exchange is for a credential the client has
                 * to attach by hand.
                 */
                'json_login' => [
                    'check_path' => 'app_login',
                    'username_path' => 'username',
                    'password_path' => 'password',
                    'success_handler' => AccessTokenGrant::class,
                    // The same refusal as every other one on this API. See the `api` firewall
                    // above, where the same class answers the same question for a bearer token
                    // that did not verify.
                    'failure_handler' => SecurityEntryPoint::class,
                ],
                'logout' => [
                    'path' => 'app_logout',
                ],
            ],
        ],

        // membership/01: `/register` and `/login` are the first two entries this list ever gets, and
        // both say the same thing, out loud, that access_control's emptiness used to say by
        // omission: nobody needs to be anybody here. `PUBLIC_ACCESS` rather than leaving the path
        // out, because ADR 009 rule 13's `app:access` distinguishes a declared absence of an actor
        // from a forgotten one, and access_control has the same problem one layer down; an empty
        // list cannot tell "no path needs a check yet" from "these two were checked and cleared". A
        // login form and a registration form are exactly the two pages a reader with no session must
        // be able to reach, so they are the two that say so.
        // membership/02 adds the first entry that demands anybody, and it is the only question this
        // list is allowed to answer: **does this path need a principal at all.** Not which one, and
        // above all not which permission. `/me/badges` is a page about whoever is signed in, so an
        // anonymous reader has nothing to be shown and wants the login form rather than a refusal.
        //
        // `IS_AUTHENTICATED_FULLY` is not a role and is not a permission. It is the authentication
        // attribute, which is ADR 008's first question answered at the firewall, where the ADR puts
        // it; `RoleMap` remains the only file in the tree that names a `ROLE_`, and the permissions
        // the badge collection actually asks for (`membership.badges.view`, `.acquire`, `.remove`) are declared
        // as a class attribute on one controller each, per ADR 009 rule 14: `ShowBadgeCollectionController`,
        // `AcquireBadgeController`, `LoseBadgeController` and `DiscardBadgeController`. `app:access`
        // reads those declarations, which is the whole reason the rule asks for one class per route.
        //
        // The regex stays this coarse on purpose. A path pattern cannot tell `GET /me/badges` from
        // `POST /me/badges/discard` without becoming a second, worse copy of the permission map,
        // and this file's own note at the top says so: `access_control` is wrong the first time
        // somebody adds a sub-resource with a different audience.
        //
        // ## Re-read after ADR 009 rule 8, one line per rule
        //
        // `access_control` is evaluated after a firewall has been chosen and has always matched on
        // the path for every request, whichever firewall took it. So moving the firewall selector
        // off the path changed none of these three rules' *scope*; what it changed is which
        // authenticator can satisfy one, and which entry point answers when nobody does. All three
        // still mean what they meant, and here is the check rather than the assertion:
        //
        // - `^/login`, `PUBLIC_ACCESS`. Unchanged. A reader with no credential lands on `web`, as
        //   before, and reaches the form. A request carrying a bearer token now lands on `api`
        //   instead and still reaches it, which is new and is harmless: it draws a login form for a
        //   client that did not need one. The `POST` is the firewall's, and `form_login` is only on
        //   `web`, so a submission carrying a bearer token would not be intercepted; nothing does
        //   that, and the answer would be a blank form rather than anything unsafe.
        // - `^/register`, `PUBLIC_ACCESS`. Unchanged, and for the same reason. The page is a `GET`
        //   with no credential by definition; the write it posts to is `POST /members`, which is
        //   `#[Unguarded]` and is not named here.
        // - `^/me`, `IS_AUTHENTICATED_FULLY`. Unchanged in meaning and **wider in reach, which is
        //   the fix**. It used to be satisfiable only by a session, because `/me` was inside the
        //   `web` path list and nothing else could serve it. Now `/me/badges` with a bearer token is
        //   the `api` firewall's, `access_token` produces a fully authenticated token, and the same
        //   rule is satisfied. That is ADR 009 rule 15 arriving at the firewall: `/me/...` and
        //   `/members/{principal}/...` are one resource seen from two perspectives, so the
        //   perspective must not also pick the credential.
        //
        // Nothing is added here by this commit. The temptation was to name the paths the catch-all
        // now covers, and that is exactly the path list this commit deleted, one layer down.
        'access_control' => [
            ['path' => '^/login', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/register', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/me', 'roles' => 'IS_AUTHENTICATED_FULLY'],
        ],
    ],
    'when@test' => [
        'security' => [
            'password_hashers' => [
                // By default, password hashers are resource intensive and take time. This is
                // important to generate secure password hashes. In tests however, secure hashes
                // are not important, waste resources and increase test times. The following
                // reduces the work factor to the lowest possible values.
                PasswordAuthenticatedUserInterface::class => [
                    'algorithm' => 'auto',
                    'cost' => 4, // Lowest possible value for bcrypt
                    'time_cost' => 3, // Lowest possible value for argon
                    'memory_cost' => 10, // Lowest possible value for argon
                ],
            ],
        ],
    ],
]);
