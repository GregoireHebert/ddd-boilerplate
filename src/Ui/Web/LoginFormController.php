<?php

declare(strict_types=1);

namespace App\Ui\Web;

use App\Shared\Infrastructure\Security\Unguarded;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * `/login`, the Twig delivery's front door and the only page in the tree that is not about badges.
 *
 * # Does this body run? On the `GET` yes, on the `POST` never
 *
 * This is the question [ADR 009](../../../docs/ADR/009_one-url-two-representations.md) rule 14 makes
 * worth asking out loud, because the answer is different for the two classes this file was split
 * into and neither answer is visible from a route table.
 *
 * **The `GET` is a real page and this body draws it.** It is the only thing this class does.
 *
 * **The `POST` never arrives here, and since ADR 009 rule 16 there are two different things it does
 * not arrive from.** `form_login` and `json_login` both listen on `check_path` on the `web` firewall
 * in `config/packages/security.php`, and the firewall runs before the router, so the submission is
 * consumed by `FormLoginAuthenticator` or by `JsonLoginAuthenticator` and this method is not called.
 * Which of the two is decided by `Content-Type`, and what they hand back differs by more than a
 * rendering: a form gets a session and a redirect, JSON gets a bearer token from
 * {@see \App\Shared\Infrastructure\Security\AccessTokenGrant}. The method is still declared, because
 * the form posts to this URL and a router that answered `405` to a submission would turn a firewall
 * misconfiguration into a method error nobody can interpret. A blank form is the better symptom.
 *
 * That is ADR 008's first question answered exactly where the ADR puts it: **at the firewall, before
 * our code.** A controller that verified a password would be a second home for authentication, in a
 * delivery, which is the shape three ADRs have now removed from three different places.
 *
 * # Why there is no `LoginAuthenticateController`
 *
 * The split that produced this class was asked for as two halves, the form and the authentication,
 * and the configuration says there is no second half to write. `form_login` declares
 * `login_path: app_login` and `check_path: app_login`, **the same route**, so `/login` is one route
 * answering two methods and rule 14 asks for one class per route rather than one per method. A
 * `LoginAuthenticateController` today would be a class with no `#[Route]` of its own, which is a
 * class the router cannot reach and `bin/console app:access` cannot see: exactly the unenumerable
 * shape rule 14 exists to remove.
 *
 * **Rule 16 made that argument stronger rather than weaker, which is worth saying because the
 * opposite was the guess.** There are now two authenticators on this path, so the temptation is to
 * write a class per authenticator. There is still one route, and `app:access` still prints one row
 * for it; a second class would be a second row for a URL that cannot be reached twice. What differs
 * between the two sign-ins is the credential they mint, and that is declared where credentials are
 * declared, in `config/packages/security.php` and in `AccessTokenGrant`, not in a controller that
 * runs for neither of them.
 *
 * It becomes a real class the day `check_path` names a route of its own, and then it is
 * {@see LogoutController}'s twin rather than this one's: a declaration that never executes, holding
 * the `POST` and its own `#[Unguarded]`. That is a change to the firewall configuration before it is
 * a change to this directory, so it belongs to whoever takes it there.
 *
 * What did come out of this file is {@see LogoutController}, because `logout` is configured with a
 * path of its own and was therefore always a second route.
 *
 * # The failure is Symfony's, in Symfony's words
 *
 * `AuthenticationUtils` hands back the last `AuthenticationException` and the identifier that was
 * tried. The template renders `messageKey` through the `security` catalogue and not through
 * `FailureMessages`, because ADR 008 settles the debt ADR 007 left open:
 *
 * > We do not re-render an authentication failure. It happens before any of our code runs, so there
 * > is no command, no handler and no domain exception; Symfony's `security` catalogue renders it, in
 * > fifty-seven locales, and duplicating it into `failures` would be the second English that ADR 007
 * > rejected everywhere else.
 *
 * So no `FailureCode`, no entry in `translations/failures+intl-icu.*.xlf`, and nothing for
 * `bin/console app:messages` to check. That command's count is unchanged by this page.
 *
 * # `AbstractController`, which {@see BadgeDesignController} did not need
 *
 * That controller is a plain final class taking its one dependency in the constructor, and this one
 * would rather be the same.
 *
 * **It could be.** This paragraph used to say it could not, because
 * `tests/phpstan/Architecture/ArchitectureTestCase::vendors()` "does not list `Twig`". It does, and
 * has since ADR 001 gained a section called "Twig is a vendor a delivery may name"; the selector is
 * in `vendors()` with a docblock explaining why the narrower option was refused. Injecting
 * `Twig\Environment` here would pass `phpat.infrastructureIndependence` exactly as
 * {@see BadgeDesignController} does.
 *
 * So extending `AbstractController` is a **preference and not a rule**, and the honest thing is to
 * say which preference: `render()` builds the `Response` as well as the string, and the service
 * subscriber keeps the constructor empty on a class that has nothing else to take. The cost is that
 * two shapes now live in one directory, and a reader deserves to know the difference is taste
 * rather than a constraint. Settling on one is worth a commit and is not this one's.
 *
 * Nothing else on the base class is used, which is why {@see LogoutController} extends nothing at
 * all: a class that never renders has no reason to inherit the one method that made this one a
 * subclass.
 *
 * # Who may
 *
 * Rule 14 asks every `Ui/Web` controller for one declaration, and this one is unguarded for the same
 * reason `POST /members` is: a permission is a question about an actor, and this is the page where an
 * actor begins. Asking the gate whether somebody may sign in would be asking it about the person who
 * has not signed in yet.
 */
#[Unguarded(because: 'signing in is where an actor begins, so there is nobody yet for a permission to be a question about; the firewall owns the submission and this class only draws the form')]
final class LoginFormController extends AbstractController
{
    /**
     * One route, one action, one file. `POST` is declared and never reached; see the docblock above.
     */
    #[Route(path: '/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function __invoke(AuthenticationUtils $authentication): Response
    {
        return $this->render('security/login.html.twig', [
            'error' => $authentication->getLastAuthenticationError(),
            'last_username' => $authentication->getLastUsername(),
        ]);
    }
}
