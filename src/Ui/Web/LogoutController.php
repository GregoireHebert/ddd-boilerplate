<?php

declare(strict_types=1);

namespace App\Ui\Web;

use App\Shared\Infrastructure\Security\Unguarded;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/logout`, which is a route name and a declaration rather than a page.
 *
 * # Does this body run? No, and that is the design
 *
 * **Never, in a correctly configured application.** The `logout` key on the `web` firewall in
 * `config/packages/security.php` names this route, and `config/routes/security.php` has been loading
 * `security.route_loader.logout` since before there was a firewall to load it for. Symfony's logout
 * listener runs before the router, clears the session and redirects; nothing downstream of it is
 * called.
 *
 * So why does the class exist. Two reasons, and both are the reason
 * [ADR 009](../../../docs/ADR/009_one-url-two-representations.md) rule 14 asks for one file per
 * route rather than one file per thing that runs.
 *
 * **It names the route.** `logout: { path: app_logout }` points at a route name, and a route name
 * needs a controller to hang off. Every alternative puts the same declaration somewhere a reader of
 * `src/Ui/Web/` would not look for it.
 *
 * **It carries the declaration.** `bin/console app:access` walks the router and reads
 * {@see Unguarded} or `#[Guarded]` off the class behind each route, and rule 13 says a page with
 * neither is a gap rather than a shrug. A route with no class of its own would be a route with no
 * answer to "who may reach this", which is precisely the ambiguity the command exists to remove.
 *
 * A controller that is never called is therefore not dead code here; it is the declaration that
 * `/logout` is a thing this application publishes, and that nobody needs a permission for it.
 *
 * # The exception is the signal, not a fallback
 *
 * Reaching this body means the firewall is no longer covering `/logout`, which would mean a reader
 * clicking "sign out" and staying signed in. A page that quietly did nothing would hide that; the
 * throw makes a configuration mistake arrive as a configuration mistake.
 *
 * It is a `\LogicException` with a sentence in it, and that is not ADR 007 rule 1 being broken. Rule
 * 17 draws the line by asking **whether there is a person who will change what they typed because of
 * this message**, and there is not: this one can only be read by whoever edits the firewall. It
 * implements none of `Shared/Domain/Exception`'s markers, carries no `FailureCode`, and
 * `bin/console app:messages` counts what it counted before.
 *
 * # No `AbstractController`, and nothing injected
 *
 * {@see LoginFormController} extends the base class for exactly one method, `render()`, because
 * `Twig` is not on `ArchitectureTestCase::vendors()`'s list and reaching the environment through the
 * framework's service subscriber is how that rule is satisfied. This class renders nothing, has no
 * dependencies and never executes, so it inherits nothing. A class with no constructor is also the
 * one shape that still resolves if it ever falls out of the container.
 *
 * # Who may
 *
 * The mirror of {@see LoginFormController}'s answer. Signing in is where an actor begins and signing
 * out is where one ends, and a permission is a question about an actor who is present for the whole
 * of the operation.
 */
#[Unguarded(because: 'signing out is where an actor ends, and the firewall answers this path before routing, so there is nothing here for a permission to be a question about')]
final class LogoutController
{
    /**
     * One route, one action, one file. The body is unreachable while the firewall is configured; see
     * the docblock above.
     */
    #[Route(path: '/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function __invoke(): never
    {
        throw new \LogicException('Intercepted by the logout listener on the "web" firewall. Reaching this means the firewall pattern no longer covers /logout.');
    }
}
