<?php

declare(strict_types=1);

namespace App\Ui\Console\Security;

use App\Shared\Domain\Exception\InvalidIdentifierException;
use App\Shared\Domain\Models\Id;
use App\Shared\Infrastructure\Security\Actor;
use App\Shared\Infrastructure\Security\Authority;
use App\Shared\Infrastructure\Security\Credential;
use App\Shared\Infrastructure\Security\Credentials;
use App\Shared\Infrastructure\Security\RoleMap;
use App\Shared\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Models\PrincipalAggregate;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `bin/console app:credentials:issue <memberId> <username> [--role=<role>] [--password=...]`
 *
 * The `--role` values are the ones {@see \App\Shared\Infrastructure\Security\RoleMap} declares, and
 * this line does not name one on purpose: ADR 008 rule 1 keeps every role inside that one file, and
 * a usage string is a call site like any other.
 *
 * **Nothing in the tree could mint a credential**, which the authentication commit reported and
 * nobody fixed: `Credentials::add()` existed with no caller, so the firewall was reading a table
 * that could only ever be empty. This is the caller.
 *
 * ## An operator command, which is a category ADR 008 already has
 *
 * No `--as`, no {@see \App\Shared\Infrastructure\Security\Guarded} declaration and no gate call. It acts
 * as the installation, like `app:outbox:relay` and `app:subscriptions:project`, and ADR 008 rule 7
 * is why that is a state rather than a gap: an operation reached with no actor is **unguarded**
 * rather than privileged, there is no `Actor::system()`, and the absence of a principal is not a
 * principal. Who may run it is decided by who may reach the container, which is a deployment
 * question and not one a PHP class improves.
 *
 * That is the same honest sentence `--as` gets and it is worth repeating here, because this command
 * is strictly more powerful than that one: it does not assert an identity, it creates the thing an
 * identity is proved with.
 *
 * ## There is an endpoint now, and this command is what is left over
 *
 * This section used to say "no endpoint, deliberately", on the grounds that a registration which
 * also mints a credential is a decision somebody has to take rather than a thing to slip in.
 * Somebody took it: ADR 009's "Registration is also where the password is set" makes `POST /members`
 * write the row, through `RegisterMemberProcessor`, and until it did,
 * a member who registered could never sign in and had no way to find that out.
 *
 * So this is an operator's tool and no longer the only door. What it is still for is the leftover
 * half of a write that spans two stores with no transaction over them: ADR 009 has the registration
 * write the member first and the credential second precisely so that the recoverable failure is
 * **a member with no credential**, and this command is what an operator finishes it with. The other
 * two callers are a credential that has to be issued out of band and a demo database somebody is
 * seeding.
 *
 * Two things it still does that the endpoint does not, both because an operator is trusted and a
 * stranger is not: it takes `--role` rather than asking the policy
 * ({@see RoleMap::registrationRoles()} is the endpoint's answer), and it applies none of
 * {@see \App\Shared\Infrastructure\Security\Credential}'s length bounds.
 *
 * ## What it does not check, and why
 *
 * That the member exists. `ui_credential.member_id` is not a foreign key and cannot be: ADR 001
 * forbids reaching into another context's tables, and `Ui` asking `Membership` over the query bus
 * before writing a row of its own would make minting a credential depend on a read model that
 * ADR 004 says answers by criteria. So a typo produces a credential for nobody, which surfaces as a
 * domain not-found the first time it is used, and the command prints the identifier back so the
 * operator can see what they typed.
 *
 * ## Exit codes
 *
 * `SUCCESS` or `FAILURE`, and no 22. ADR 005's exit code 22 is what a `ConstraintViolationList`
 * renders to, and there is no payload here to accumulate violations over: a missing argument is
 * refused by the console before this body runs, and the two things that can be wrong are a
 * malformed identifier and a name somebody already has.
 */
#[AsCommand(name: 'app:credentials:issue', description: 'Issue a credential so a member can sign in (ADR 008, ADR 009 rule 10)')]
final class IssueCredentialCommand extends Command
{
    public function __construct(
        private readonly Credentials $credentials,
        private readonly RoleMap $roles,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('memberId', InputArgument::REQUIRED, 'The member this credential proves');
        $this->addArgument('username', InputArgument::REQUIRED, 'What this person types to sign in. Not their handle');
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Asked for interactively when it is not given, so it stays out of the shell history');
        // No default, and the help lists what RoleMap declares rather than naming one here. ADR 008
        // rule 1 forbids a call site hard-coding a role; a credential row holds roles, so somebody
        // has to say which, and the honest place for that is the operator's hand.
        $this->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, \sprintf('Repeatable. One of: %s', implode(', ', $this->roles->declaredRoles())));
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            /** @var Id<PrincipalAggregate> $memberId */
            $memberId = Id::fromString((string) $input->getArgument('memberId'));
        } catch (InvalidIdentifierException) {
            $io->error('That is not an identifier.');

            return Command::FAILURE;
        }

        $username = trim((string) $input->getArgument('username'));

        if ('' === $username) {
            $io->error('A credential needs a name somebody can type.');

            return Command::FAILURE;
        }

        // Two reads before the write rather than catching a unique-constraint violation, because
        // the two cases have different sentences and an operator is the one reading them. The
        // constraints in `Credential.orm.xml` are still what actually enforces it; this is the
        // readable half, and a race between two operators at one terminal is not a threat model.
        //
        // The second of the two is one member, one credential, and why that is true rather than a
        // product rule somebody preferred is written in {@see Credential}'s docblock: it is the
        // uniqueness a token's `sub` claim already assumes.
        if (null !== $this->credentials->forUsername($username)) {
            $io->error('That name is taken.');

            return Command::FAILURE;
        }

        if (null !== $this->credentials->forMember($memberId)) {
            $io->error('That member already has a credential. A member has one, which is what makes resolving a token an identity lookup.');

            return Command::FAILURE;
        }

        $password = $input->getOption('password');

        if (!\is_string($password) || '' === $password) {
            $password = (string) $io->askHidden('Password');
        }

        if ('' === $password) {
            $io->error('A credential needs a password.');

            return Command::FAILURE;
        }

        /** @var list<string> $roles */
        $roles = array_values(array_filter((array) $input->getOption('role'), \is_string(...)));
        $undeclared = array_diff($roles, $this->roles->declaredRoles());

        if ([] !== $undeclared) {
            // A role nothing declares grants nothing, because `RoleMap::grants()` would find no
            // entry for it. Writing it into a row would produce a credential that signs in and can
            // do nothing, which is a bug that looks like a policy.
            $io->error(\sprintf('No such role: %s. RoleMap declares: %s.', implode(', ', $undeclared), implode(', ', $this->roles->declaredRoles())));

            return Command::FAILURE;
        }

        if ([] === $roles) {
            $io->warning('No --role given, so this credential can sign in and do nothing. That is a legitimate thing to want and it is rarely what somebody meant.');
        }

        /** @var Id<Credential> $credentialId */
        $credentialId = Id::generate();

        $credential = Credential::issue(
            $credentialId,
            $memberId,
            $username,
            // The hasher is keyed on `PasswordAuthenticatedUserInterface` in
            // `config/packages/security.php`, so it needs an instance to resolve an algorithm
            // from. This one is thrown away: `Credential` takes a hash and never a plaintext,
            // which is what stops a class from being one somebody eventually stored one in.
            $this->hasher->hashPassword(new SecurityUser(Actor::member($memberId, Authority::None), $username, $roles), $password),
            $roles,
            $this->clock->now(),
        );

        $this->credentials->add($credential);

        // The audit line, written by the thing that decided. There is no actor to name, which is
        // exactly what ADR 008 means by unguarded, so the line names what was created instead.
        $this->logger->notice('Credential issued. credential={credential} member={member} username={username} roles={roles}', [
            'credential' => $credentialId->toString(),
            'member' => $memberId->toString(),
            'username' => $username,
            'roles' => implode(',', $roles),
        ]);

        $io->success(\sprintf('Issued %s for member %s.', $username, $memberId->toString()));
        $io->writeln(\sprintf('Sign in at /login, or take a bearer token with <info>bin/console app:tokens:issue %s</info>.', $username));

        return Command::SUCCESS;
    }
}
