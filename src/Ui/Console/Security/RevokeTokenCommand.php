<?php

declare(strict_types=1);

namespace App\Ui\Console\Security;

use App\Shared\Domain\Exception\InvalidIdentifierException;
use App\Shared\Domain\Models\Id;
use App\Shared\Infrastructure\Security\AccessToken;
use App\Shared\Infrastructure\Security\AccessTokens;
use App\Shared\Infrastructure\Security\RevokedToken;
use App\Shared\Infrastructure\Security\RevokedTokens;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/console app:tokens:revoke <jti>`
 *
 * **This command is the difference between ADR 009's design and a bare JWT.** ADR 008 warned that
 * "a JWT is a cached authorization decision with a TTL and no recall", and rule 9 answers it with a
 * `jti` and a row; a row nothing can write is not recall, it is a table.
 *
 * ## It takes a `jti` and not a token
 *
 * A withdrawn token is named by its identifier, which `bin/console app:tokens:issue` prints for
 * exactly this purpose. Accepting the whole token instead would mean an operator pasting a live
 * bearer credential into a shell history to get rid of it, which is the shape of mistake ADR 009
 * is already correcting one layer down.
 *
 * ## An operator command, and no actor
 *
 * No `--as`, no permission, no gate call. ADR 008 rule 7: an operation reached with no actor is
 * unguarded rather than privileged. Who may revoke is who may reach the container.
 *
 * There is deliberately no `--all-for-member`. Withdrawing everything somebody holds is a different
 * question with an answer that already exists: delete their credential, and {@see \App\Shared\Infrastructure\Security\ActorTokens}
 * re-reads the row on every call, so the roles go with it. A second mechanism for the same fact is
 * the thing a revocation list must not become.
 */
#[AsCommand(name: 'app:tokens:revoke', description: 'Withdraw a bearer token by its jti (ADR 009 rule 9)')]
final class RevokeTokenCommand extends Command
{
    public function __construct(
        private readonly RevokedTokens $revoked,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('jti', InputArgument::REQUIRED, \sprintf('The identifier bin/console app:tokens:issue printed. Withdrawing it matters for at most %s', AccessTokens::LIFETIME));
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            /** @var Id<AccessToken> $jti */
            $jti = Id::fromString((string) $input->getArgument('jti'));
        } catch (InvalidIdentifierException) {
            $io->error('That is not an identifier. A jti is the ULID bin/console app:tokens:issue printed.');

            return Command::FAILURE;
        }

        // Revoking is idempotent, because the `jti` is the primary key. The check is here so the
        // operator learns which of the two happened, not so the second one would be an error; a
        // command that failed on an already-revoked token would make "revoke it again to be sure"
        // look like something went wrong.
        if ($this->revoked->isRevoked($jti)) {
            $io->info('Already revoked.');

            return Command::SUCCESS;
        }

        $this->revoked->revoke(RevokedToken::at($jti, $this->clock->now()));

        $this->logger->notice('Access token revoked. jti={jti}', ['jti' => $jti->toString()]);

        $io->success(\sprintf('Revoked. The row matters until the token would have expired anyway, which is at most %s from when it was issued.', AccessTokens::LIFETIME));

        return Command::SUCCESS;
    }
}
