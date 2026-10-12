<?php

declare(strict_types=1);

namespace App\Ui\Console\Security;

use App\Shared\Application\Security\Forbidden;
use App\Shared\Application\Security\NotAuthenticated;
use App\Shared\Infrastructure\Security\Actor;
use App\Shared\Infrastructure\Security\CurrentActor;
use App\Shared\Infrastructure\Security\Gate;
use App\Shared\Infrastructure\Security\Guarded;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The console's half of ADR 008 option B: the row of the delivery table that reads
 * "the `--as` option, and the gate call, contributed by one helper".
 *
 * ```php
 * #[AsCommand(name: 'app:likes:cast', description: '...')]
 * #[Guarded(MatchingPermission::CastLike)]
 * final class CastLikeCommand extends Command
 * {
 *     protected function configure(): void
 *     {
 *         $this->guard->declareOn($this);       // contributes --as
 *     }
 *
 *     protected function execute(InputInterface $input, OutputInterface $output): int
 *     {
 *         $actor = $this->guard->authorize($this);   // question 2, once, at the edge
 *     }
 * }
 * ```
 *
 * Two calls, and the same {@see Guarded} declaration the API Platform factory reads. That is the
 * whole argument for an attribute of ours rather than API Platform's `security:` string, made
 * concrete: `security:` feeds one delivery, and this file is the second one reading the same line.
 * It is {@see \App\Ui\Console\Query\FilterSetOptions} again, which turns ADR 004's option B
 * declaration into console options while
 * {@see \ApiPlatform\Metadata\DeclaredFiltersFactory} turns it into query
 * parameters.
 *
 * ## Why the option and the check are one class
 *
 * They are one decision. A command that asks for a permission needs somebody to ask it *of*, so a
 * command that is guarded is a command that must be able to say who it is acting for; and a command
 * that takes `--as` and never consults the gate has an impersonation switch with nothing behind it.
 * Splitting them would let each half ship without the other, and the half that ships alone is
 * always the option.
 *
 * ## What it refuses with, and why it throws rather than returning an exit code
 *
 * {@see NotAuthenticated} when nobody is asking, {@see Forbidden} when somebody is and does not
 * hold the permission. Those are the two refusals {@see Actor::subject()} already raises, for the
 * same two reasons, and reusing them is the point: ADR 007's rule is that a failure carries a
 * declared code and never a sentence, and there are exactly two codes for this and they are already
 * in the catalogue.
 *
 * It throws rather than rendering, which is a smaller decision than it looks. ADR 005's console
 * commands render a `ConstraintViolationList` to exit code 22 because a violation list is a
 * *shape* a caller parses; a refusal is not a list and has nothing to accumulate with. A command
 * that wants the sentence rather than the code catches it and asks
 * {@see \Translation\FailureMessages}, which is the one class in the tree
 * allowed to call `trans()`.
 *
 * ## `--as` is root, and the gate is not what stops that
 *
 * {@see AssertedActor} is where that is written down in full. The short version is that this helper
 * does not ask the gate for permission to *impersonate*, only for permission to do the thing: a
 * check whose subject can rewrite the check is theatre, and anyone who can run the console already
 * holds the database credentials. What the design buys instead is that the assertion is visible.
 * {@see \App\Shared\Infrastructure\Security\Authority::Assertion} reaches the audit line below, so an
 * operator assertion and a login never produce the same log line.
 *
 * ## An unguarded command is a state, not a gap
 *
 * `app:outbox:relay`, `app:subscriptions:project`, `app:paths` and `app:reads` act as the
 * installation. They declare no {@see Guarded}, they never call this helper, and that is correct:
 * ADR 008 rule 7 says an operation reached with no actor is *unguarded* rather than privileged, and
 * there is no `Actor::system()` for it to be privileged as. `bin/console app:access` lists them and
 * does not fail over them, because nothing in a console command's signature says whether it writes.
 */
final readonly class ConsoleGuard
{
    public function __construct(
        /**
         * Named rather than aliased, which {@see CurrentActor} explains: aliasing one delivery's
         * implementation would make the other three exceptions to it, and the whole point of the
         * table there is that no delivery is the default.
         */
        #[Autowire(service: AssertedActor::class)]
        private CurrentActor $actors,
        private Gate $gate,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Contributes `--as` to a command, from `configure()`.
     *
     * The option name is {@see AssertedActor::OPTION}, declared on the listener that reads it, so
     * the writer and the reader of the option name the same constant rather than the same string.
     *
     * It is `VALUE_REQUIRED` and it has no default, because there is no sensible member to act for
     * when nobody said. A command run without it acts for {@see Actor::anonymous()}, and a guarded
     * command run without it is therefore refused, which is the honest outcome: an operator
     * assertion is a thing somebody typed.
     */
    public function declareOn(Command $command): void
    {
        $command->addOption(
            AssertedActor::OPTION,
            null,
            InputOption::VALUE_REQUIRED,
            'Act for this member. An operator assertion, not a login: it is recorded as one and it is root.',
        );
    }

    /**
     * ADR 008 question 2, asked once, at the edge, before anything goes on a bus.
     *
     * Returns the {@see Actor} so the caller has the subject to build its command out of, which is
     * rule 5: the subject of a write comes from the principal and never from an argument. A command
     * that took a `from` argument *and* called this would have two answers to one question.
     *
     * A command with no {@see Guarded} declaration is not refused. It gets the actor and no gate
     * call, which is what makes this safe to call from a command that is being converted: the check
     * appears when the declaration does.
     *
     * @throws NotAuthenticated when nobody is asking
     * @throws Forbidden        when somebody is and does not hold the permission
     */
    public function authorize(Command $command): Actor
    {
        $actor = $this->actors->actor();
        $guard = Guarded::on($command::class);

        if (null === $guard) {
            return $actor;
        }

        if (!$this->gate->isGranted($actor, $guard->permission)) {
            // The permission name is in the log and never in the refusal. ADR 008 rule 14: telling
            // a caller which permission they lack publishes the shape of the policy to somebody who
            // has just demonstrated they are not entitled to know it. Here the caller is an
            // operator reading the same machine's logs, which is why this line is worth writing and
            // the message still is not.
            $this->logger->warning('Console refusal. {actor} permission={permission} command={command}', [
                'actor' => $actor->describe(),
                'permission' => $guard->permission->wire(),
                'command' => $command->getName() ?? '-',
            ]);

            throw $actor->isAnonymous() ? NotAuthenticated::required() : Forbidden::refused();
        }

        // The audit line ADR 008 asks for, completed. {@see AssertedActor} wrote the provenance
        // when it resolved the actor; this is the half that names what was decided and about what.
        $this->logger->notice('Console decision. {actor} permission={permission} command={command}', [
            'actor' => $actor->describe(),
            'permission' => $guard->permission->wire(),
            'command' => $command->getName() ?? '-',
        ]);

        return $actor;
    }
}
