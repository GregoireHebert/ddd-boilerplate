<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Models\PrincipalAggregate;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * {@see CurrentActor} for the Twig delivery, where the caller proved themselves once with a form and
 * carries a cookie afterwards.
 *
 * ## Why this is a separate class from {@see TokenCurrentActor}
 *
 * The bodies are the same except for one enum case, and merging them into one class taking an
 * {@see Authority} in its constructor would be shorter. It would also need two container aliases to
 * distinguish the two services, which means the distinction moves out of the code and into
 * `config/services.php`, where PHPStan cannot see it and `git grep Authority::Session` finds nothing.
 *
 * ADR 008's table is four rows of "this delivery, this mechanism, this authority", and one class per
 * row is what makes that table greppable. The duplication is nine lines and it is the point of the
 * file: the reason it looks identical is that everything downstream of an `Actor` is identical, and
 * the reason it is a separate file is that the thing that differs is the only thing this layer
 * exists to record.
 *
 * ## The cookie is for a first-party client and nothing else
 *
 * ADR 008 keeps the webapp on its own **stateful** firewall, and this paragraph used to add that the
 * separation also kept a session cookie away from API paths, so that no cross-origin request nobody
 * designed could carry one. **That second claim died with ADR 009 rule 8** and is corrected here
 * rather than quietly dropped: the firewalls are now told apart by the credential, so the stateful
 * one is the catch-all and every API path is behind it.
 *
 * What is left standing is the first claim and it is the one that matters to this class: the two
 * firewalls are still two, this is still the one that reads a session, and nothing downstream of an
 * {@see Actor} can tell which of them produced it. What took over the job the path split was
 * incidentally doing is `SameSite=Lax` on the session cookie, which is the mechanism that is
 * actually about cross-site requests; the path split was never more than a side effect that happened
 * to help. Worth knowing before CORS is configured, because that is where it would be given away.
 *
 * ## Choosing this one
 *
 * Nothing is aliased to `CurrentActor`; see {@see TokenCurrentActor}'s docblock. A Twig controller
 * names this one:
 *
 * ```php
 * public function __construct(
 *     #[Autowire(service: SessionCurrentActor::class)] private CurrentActor $actors,
 * ) {}
 * ```
 */
final readonly class SessionCurrentActor implements CurrentActor
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

        // See {@see TokenCurrentActor::actor()} for why this arm exists and why it is unreachable
        // until `Trust` lands.
        if (PrincipalKind::Member !== $user->actor()->kind) {
            return Actor::anonymous();
        }

        // The principal comes off the `Actor` the provider built, not off `getUserIdentifier()`,
        // which since ADR 009 rule 10 answers the username. See {@see TokenCurrentActor::actor()}.
        return Actor::member($user->actor()->subject(PrincipalAggregate::class), Authority::Session);
    }
}
