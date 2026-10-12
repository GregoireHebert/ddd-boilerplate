<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Models\PrincipalAggregate;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * {@see CurrentActor} for the two deliveries that authenticate with a bearer token: the ReST API and
 * `POST /rpc`. One class for both, because they sit behind one firewall and prove the same thing the
 * same way; ADR 008's per-delivery table has two rows for them and the same mechanism in each.
 *
 * ## Yes, it reads token storage, and that is exactly what it is for
 *
 * ADR 008's second rule is that the actor is an argument and never ambient, and this class reads an
 * ambient token. That is not an exception to the rule, it is where the rule is paid for:
 * `CurrentActor` implementations are **the only place in the application allowed to know how the
 * caller proved anything**. Everything downstream is handed an {@see Actor} and cannot tell a bearer
 * token from a session from `--as`, which is the property that makes four deliveries share one
 * processor.
 *
 * ## It re-stamps the authority
 *
 * {@see CredentialProvider} builds the `SecurityUser` and cannot know which firewall asked for it, so
 * it stamps {@see Authority::None} and leaves the provenance to whichever `CurrentActor` is on the
 * far side. This is that side, and this is the class that knows the answer is
 * {@see Authority::Token}. The re-stamp reads the principal off the `Actor` inside, because since
 * ADR 009 rule 10 `getUserIdentifier()` answers the username: a login handle is not a principal
 * identifier and parsing one as a ULID is the confusion that rule exists to end.
 *
 * ## Anonymous is a value, not a null
 *
 * Nothing in the firewall configuration forces a token onto every request: `access_control` says
 * which paths need anybody, and a path that needs nobody reaches here with nothing in storage. That
 * answers {@see Actor::anonymous()}, an operation reached with it is *unguarded* rather than
 * privileged, and the `401` (if there is to be one) is raised by {@see Actor::subject()} at the one
 * place the absence is knowable.
 *
 * ## Choosing this one
 *
 * There are three implementations of a one-method interface, so nothing is aliased to
 * `CurrentActor`: picking a winner would be deciding which delivery the others are exceptions to. A
 * consumer names the one it is:
 *
 * ```php
 * public function __construct(
 *     #[Autowire(service: TokenCurrentActor::class)] private CurrentActor $actors,
 * ) {}
 * ```
 */
final readonly class TokenCurrentActor implements CurrentActor
{
    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    #[\Override]
    public function actor(): Actor
    {
        $user = $this->tokens->getToken()?->getUser();

        if (!$user instanceof SecurityUser) {
            return Actor::anonymous();
        }

        // Only one principal kind can be built today, because `Actor::moderator()` deliberately does
        // not exist: its constructor would need a phantom tag for a moderator's identifier and there
        // is no `Trust` context to hold one. The arm is written rather than assumed because
        // `PrincipalKind`'s own docblock is explicit that the expensive part of a second kind is
        // auditing the call sites written when the check was vacuous, and this is one of them. It
        // fails closed: a principal this delivery cannot represent is represented as nobody.
        if (PrincipalKind::Member !== $user->actor()->kind) {
            return Actor::anonymous();
        }

        return Actor::member($user->actor()->subject(PrincipalAggregate::class), Authority::Token);
    }
}
