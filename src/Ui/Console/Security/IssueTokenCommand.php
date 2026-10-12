<?php

declare(strict_types=1);

namespace App\Ui\Console\Security;

use App\Shared\Infrastructure\Security\AccessTokens;
use App\Shared\Infrastructure\Security\Credentials;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/console app:tokens:issue <username>`
 *
 * A bearer token for the stateless firewall, printed so an operator can paste it into a `curl`.
 *
 * ## Why this is a console command and not an endpoint
 *
 * Because nobody has decided what the endpoint is. A token endpoint is a write with a request
 * shape, a failure taxonomy and an audience, which is ADR 006's three tests and ADR 009's question
 * about which representations it has; none of that is this commit's to answer, and ADR 008's own
 * list of what it still owes does not sequence one. So the token is minted where every other
 * unargued capability in this tree is minted, by an operator at a terminal, and the gap stays
 * visible instead of being filled by a route nobody argued for.
 *
 * ## It asks for no password, and that is the same honest sentence `--as` gets
 *
 * > **The console is root.** Anyone who can run it has the database credentials, and no amount of
 * > PHP changes that.
 *
 * So this does not verify a password before minting, because a check whose subject can rewrite the
 * check is theatre. It is an operator command in ADR 008's sense, acting as the installation, with
 * no `--as`, no {@see \App\Shared\Infrastructure\Security\Guarded} declaration and no gate call. What
 * the design buys is not that this is safe; it is that the token it produces is indistinguishable
 * downstream from one a real sign-in would produce, so what it exercises is the real path.
 *
 * ## What it prints, and the one thing worth keeping
 *
 * The token, its `jti` and its expiry. The `jti` is the one to keep: it is what
 * `bin/console app:tokens:revoke` takes, and a token that has been handed out and not recorded is
 * one nobody can withdraw before it expires on its own.
 */
#[AsCommand(name: 'app:tokens:issue', description: 'Mint a bearer token for a credential (ADR 009 rule 9)')]
final class IssueTokenCommand extends Command
{
    public function __construct(
        private readonly Credentials $credentials,
        private readonly AccessTokens $tokens,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'The credential to mint a token for');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $credential = $this->credentials->forUsername((string) $input->getArgument('username'));

        if (null === $credential) {
            // An operator at their own terminal, so this one may say which of the two it was;
            // ADR 008 rule 14 governs what a *caller* is told, and the caller here is the person
            // who also owns the database.
            $io->error('No such credential. Issue one with bin/console app:credentials:issue.');

            return Command::FAILURE;
        }

        // The subject is the member and not the credential, which is `AccessTokens`' decision and
        // the reason a rename of the login handle does not invalidate a live token.
        $token = $this->tokens->issue($credential->memberId());

        $this->logger->notice('Access token issued. jti={jti} member={member} expires={expires}', [
            'jti' => $token->jti->toString(),
            'member' => $token->subject->toString(),
            'expires' => $token->expiresAt->format(\DATE_ATOM),
        ]);

        $io->success(\sprintf('Valid until %s.', $token->expiresAt->format(\DATE_ATOM)));
        $io->definitionList(
            ['jti' => $token->jti->toString()],
            ['member' => $token->subject->toString()],
        );
        $io->writeln($token->encoded);

        return Command::SUCCESS;
    }
}
