<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Models\Id;

/**
 * One withdrawn bearer token: a `jti` and the instant somebody withdrew it.
 *
 * ## What it replaces, and why the replacement is two tables
 *
 * ADR 009 rule 9 in one row. The shape this corrects made the credential row's primary key *be*
 * the opaque bearer token, which stored the token in clear text and meant that revoking a token
 * deleted the password login sharing its row. Two lifetimes were sharing one row, and ADR 009's
 * table splits them by asking what each row is for: `ui_credential` lives as long as the account,
 * this one lives until the token it names would have expired anyway.
 *
 * `AccessTokens::LIFETIME` is what makes that second sentence actionable: a row older than the
 * token lifetime names a `jti` that no longer verifies, so pruning is a `DELETE` on `revoked_at`
 * and not a decision anybody has to take again. Nothing prunes yet, and that is written down rather
 * than promised: fifteen minutes of rows is not a table anybody has to sweep before there is a
 * second user of this application.
 *
 * ## A revocation list, not a token store
 *
 * The list holds only what was withdrawn, so the common path writes nothing at all. That is the
 * property that keeps this ADR 003 **path 1** and keeps the token stateless in every sense that
 * matters: a token this application issued and nobody revoked exists in no table of ours, and the
 * check on each request is one lookup by primary key.
 *
 * It is an assertion about a `jti` and not about a member, deliberately. Revoking every token a
 * member holds is a different question with a different answer (delete the credential, which is
 * what {@see ActorTokens} re-reads on every call), and a row per member here would be a second
 * place for that fact to live.
 */
final class RevokedToken
{
    /**
     * @param Id<AccessToken> $jti
     */
    private function __construct(
        private readonly Id $jti,
        private readonly \DateTimeImmutable $revokedAt,
    ) {
    }

    /**
     * The one named constructor. It takes the instant rather than reading a clock, because every
     * other writer in this tree takes one: a `ClockInterface` is injected at the edge and the value
     * travels, which is what makes the moment a caller's decision rather than a class's habit.
     *
     * @param Id<AccessToken> $jti
     */
    public static function at(Id $jti, \DateTimeImmutable $revokedAt): self
    {
        return new self($jti, $revokedAt);
    }

    /**
     * @return Id<AccessToken>
     */
    public function jti(): Id
    {
        return $this->jti;
    }

    public function revokedAt(): \DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
