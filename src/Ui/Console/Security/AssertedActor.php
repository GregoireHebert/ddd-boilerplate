<?php

declare(strict_types=1);

namespace App\Ui\Console\Security;

use App\Shared\Infrastructure\Security\Actor;
use App\Shared\Infrastructure\Security\Authority;
use App\Shared\Infrastructure\Security\CredentialProvider;
use App\Shared\Infrastructure\Security\CurrentActor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use App\Shared\Domain\Models\PrincipalAggregate;

/**
 * {@see CurrentActor} for the console, and the row of ADR 008's table that keeps the whole design
 * honest.
 *
 * ## `--as` is root, and it says so
 *
 * > Anyone who can run the console has the database credentials, and no amount of PHP changes that.
 *
 * So this is **not** authentication and is not dressed up as one. It does not consult the gate for
 * permission to impersonate, because a check whose subject can rewrite the check is theatre. What
 * the design does buy is that the *rest* of the application cannot tell the difference and does not
 * need to: `app:likes:cast --as X` runs the same processor, the same command and the same handler as
 * `POST /likes`, which is why those two console writes exist at all.
 *
 * What it also buys is that the difference is **visible where it matters**. {@see Authority::Assertion}
 * reaches the audit line, so an operator assertion and a login never produce identical log lines,
 * and that is the only place in the whole design where provenance is load-bearing.
 *
 * ## Three commands out of every four have no actor, and that is a state rather than a gap
 *
 * `app:outbox:relay`, `app:subscriptions:project`, `app:paths` and `app:reads` act as the
 * installation. They declare no `--as`, this listener finds nothing, and {@see self::actor()}
 * answers {@see Actor::anonymous()}. ADR 008 rule 7: an operation reached with no actor is
 * **unguarded** rather than privileged, there is no `Actor::system()`, and the absence of a
 * principal is not a principal.
 *
 * ## Why a listener and not an argument
 *
 * `CurrentActor::actor()` takes nothing, in all four deliveries, so that no consumer can be written
 * against one delivery's way of proving things. A console process runs exactly one command, so
 * `ConsoleCommandEvent` is the moment the input exists and there is never a second one to confuse it
 * with. The two HTTP implementations read Symfony's token storage for the same reason and with the
 * same licence: a `CurrentActor` is the one place in the application allowed to know how the caller
 * proved anything.
 *
 * Note what it does **not** do, and what {@see \App\Shared\Infrastructure\Security\ActorTokens} refuses
 * for the same reason: it never writes a token into `TokenStorage`. Pushing a fabricated token into
 * storage is how an operator assertion becomes indistinguishable from a login for everything
 * downstream.
 *
 * ## A bad `--as` is loud
 *
 * An identifier that is not a ULID, or one naming a member with no credential, throws out of the
 * listener and the command dies. That is the right volume for an operator typo: the alternative is a
 * silent fall back to anonymous, and a `--as` that quietly did nothing is worse than one that failed.
 * The resolution goes through the user provider rather than through the credential table directly,
 * which is ADR 008's own wording for this row ("an `InputOption`, and the user provider") and what
 * stops an assertion naming somebody who could never have signed in.
 *
 * It names a **member**, not a username, and ADR 009 rule 10 does not touch that. Rule 10 is about
 * the handle somebody signs in with, and this is not a sign-in: ADR 008 writes the option as
 * `--as <memberId>` and spends a section on why it is an assertion rather than authentication. So it
 * goes through {@see CredentialProvider::loadUserByMemberId()}, which is the entry point the token
 * handler uses for the same reason, while `loadUserByIdentifier()` stays the one that takes
 * something a human typed.
 *
 * ## The option itself belongs to the next commit
 *
 * ADR 008 sequences `#[Guarded]`, the metadata factories and the console helper together, and the
 * helper is what contributes `--as` and the gate call to the commands that act for somebody. Until
 * it lands, no command declares the option, `$input->hasOption('as')` is false everywhere, and this
 * listener is correct and inert. That is deliberate: authentication ships before the declaration
 * that uses it.
 */
#[AsEventListener(event: ConsoleEvents::COMMAND)]
final class AssertedActor implements CurrentActor
{
    /**
     * The option name every command acting for somebody declares. Declared here rather than in the
     * helper that adds it, so that the reader and the writer of the option name the same constant.
     */
    public const string OPTION = 'as';

    private ?Actor $asserted = null;

    public function __construct(
        private readonly CredentialProvider $credentials,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $this->asserted = null;

        $input = $event->getInput();

        if (!$input->hasOption(self::OPTION)) {
            return;
        }

        $raw = $input->getOption(self::OPTION);

        if (!\is_string($raw) || '' === $raw) {
            return;
        }

        // Through the provider, so an assertion for a member with no credential throws
        // `UserNotFoundException` here rather than producing an `Actor` nothing could have
        // authenticated. A non-ULID throws the same failure, for the same reason.
        $user = $this->credentials->loadUserByMemberId($raw);

        $actor = Actor::asserted($user->actor()->subject(PrincipalAggregate::class));

        $this->asserted = $actor;

        // The audit line ADR 008 asks for, written by the thing that decided, at the edge, before
        // anything goes on a bus. `Actor::describe()` is the fragment that carries the provenance;
        // the permission and the operation join it in the console helper's own line, next commit.
        $this->logger->notice('Console actor asserted. {actor} command={command}', [
            'actor' => $actor->describe(),
            'command' => $event->getCommand()?->getName() ?? '-',
        ]);
    }

    #[\Override]
    public function actor(): Actor
    {
        return $this->asserted ?? Actor::anonymous();
    }
}
