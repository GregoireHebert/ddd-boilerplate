<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Models\Id;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The repository over {@see RevokedToken}, and like {@see Credentials} it answers by identity only.
 *
 * ADR 004 rule 1: a repository answers by identity, a read model answers by criteria. Both methods
 * here are primary-key operations. Nothing lists revocations and nothing should: a list of
 * withdrawn tokens is an operator report about `jti` values nobody can read, which is ADR 007
 * rule 9 arriving in a report instead of in a message.
 *
 * ## It flushes, and that is an open question rather than a settled exception
 *
 * `EcotoneDbalConfiguration` opens a transaction and flushes once per command and per async
 * message, which is why CLAUDE.md says no repository in this tree flushes, with one documented
 * exception that is `DoctrineMemberErasureRepository` and not this.
 *
 * The fact that puts this method in the same shape as {@see Credentials::add()}:
 *
 * > **Nothing that writes here is ever on a bus.** A revocation is typed by an operator into
 * > `bin/console app:tokens:revoke`, which puts no command on the command bus, because there is no
 * > context that owns credentials. Without the flush below the command would report success and
 * > write nothing.
 *
 * Whether that is enough to make a second exception is a decision for ADR 003 or ADR 008 and not
 * for this docblock, which is why it is handed back as a question instead of argued here.
 * {@see self::isRevoked()} is the other half and it is a read, reached from a firewall listener
 * before routing, writing nothing.
 */
final readonly class RevokedTokens
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Asked once per request that presents a token, by {@see JwtTokenHandler}, which is what
     * ADR 009 means by "checked on each request".
     *
     * A lookup by primary key on a table that holds only what was withdrawn, so the common answer
     * is a miss on an index. That is the cost of making a stateless token revocable, stated plainly:
     * one indexed lookup per request, against a database round trip the firewall was making anyway
     * to load the credential.
     *
     * @param Id<AccessToken> $jti
     */
    public function isRevoked(Id $jti): bool
    {
        return null !== $this->entityManager->getRepository(RevokedToken::class)->find($jti);
    }

    /**
     * Idempotent by construction: the `jti` is the primary key, so revoking a token twice is one
     * row either way. The caller checks {@see self::isRevoked()} first so it can say which of the
     * two happened, not so the second one would be an error.
     */
    public function revoke(RevokedToken $revocation): void
    {
        $this->entityManager->persist($revocation);

        // See the class docblock: nothing that writes here is on a bus, so there is no flush coming
        // from anywhere else, and whether that licenses this line is an open ADR question.
        $this->entityManager->flush();
    }
}
