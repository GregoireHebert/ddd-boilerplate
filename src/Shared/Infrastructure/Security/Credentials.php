<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Models\Id;
use App\Shared\Domain\Models\PrincipalAggregate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The repository over {@see Credential}, and it answers by identity only.
 *
 * ADR 004 rule 1: **a repository answers by identity, a read model answers by criteria.** Both
 * lookups here are identity lookups on a unique column, so there is no read model, no port in an
 * `Application/ReadModel/`, no `Page` and no `Slice`. Nothing lists credentials and nothing ever
 * should: a list of who can sign in is an operator report, and the moment somebody wants one they
 * want an `Identity` context with a language, not a `findAll()` here.
 *
 * ## Three things it deliberately is not
 *
 * - **not a `ServiceEntityRepository`.** Same reasoning as `DoctrineLikeRepository`:
 *   there is nothing inherited that would be used, and extending it would put `findBy`, `matching`
 *   and `createQueryBuilder` on a class whose contract is three methods;
 * - **not behind a `Domain/Repository/` interface.** Every other repository in this tree implements
 *   one, because the Application layer has to ask the same question whichever of ADR 003's five
 *   paths answers it. There is no Application layer here: the caller is Symfony's firewall, through
 *   {@see CredentialProvider}, which is itself the framework's own port. A second interface in front
 *   of it would be a port in front of a port;
 * - **not a token store.** ADR 009 rule 9 took the bearer token out of this table; what a
 *   revocation holds is a `jti`, and {@see RevokedTokens} holds those.
 *
 * ## The one write, and the flush it does
 *
 * {@see self::add()} flushes. Here is the fact, and then the part that is not settled.
 *
 * The fact: **nothing that writes a credential is on a bus.** The one caller is
 * `bin/console app:credentials:issue`, which sends no command and publishes no event, because no
 * bounded context owns credentials. `EcotoneDbalConfiguration` flushes per command and per async
 * message, so there is no flush arriving from anywhere else and a `persist()` on its own would
 * write nothing at all. The two reads write nothing and run inside a firewall listener, before
 * routing.
 *
 * The part that is not settled: CLAUDE.md states "no repository flushes" with exactly one
 * documented exception, `DoctrineMemberErasureRepository`. This is not that one, and **a docblock
 * is not where a second one gets declared.** The question is whose: ADR 003 owns how an aggregate
 * is stored and ADR 008 owns this table. It is written down as a question in the report that
 * accompanied this file rather than argued here, and if the answer is that a console-only write
 * flushes somewhere else, this method is where it moves from.
 */
final readonly class Credentials
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * By the handle a human typed, which under ADR 009 rule 10 is what a sign-in form asks for and
     * what {@see CredentialProvider::loadUserByIdentifier()} is given.
     *
     * A lookup on a unique column rather than on the primary key, and still an identity lookup: it
     * answers with one row or none, and there is nothing to order, window or combine.
     */
    public function forUsername(string $username): ?Credential
    {
        return $this->entityManager->getRepository(Credential::class)->findOneBy(['username' => $username]);
    }

    /**
     * By the member, which is what a token's `sub` claim carries ({@see AccessTokens}) and what
     * {@see ActorTokens} asks for when it needs somebody's roles outside a request.
     *
     * @param Id<PrincipalAggregate> $memberId
     */
    public function forMember(Id $memberId): ?Credential
    {
        return $this->entityManager->getRepository(Credential::class)->findOneBy(['memberId' => $memberId]);
    }

    /**
     * See the class docblock: nothing that writes here is on a bus, and whether that is a licence to
     * flush is an open question for ADR 003 or ADR 008 rather than a thing this comment decides.
     */
    public function add(Credential $credential): void
    {
        $this->entityManager->persist($credential);
        $this->entityManager->flush();
    }
}
