<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Models\Id;
use App\Shared\Domain\Models\PrincipalAggregate;

/**
 * A bearer token, as this application means it: a JWT, its `jti`, its subject and its expiry, in one
 * value.
 *
 * ## Why there is a class at all
 *
 * A JWT is a string, and the obvious design is to pass that string around. It does not survive
 * contact with ADR 009 rule 9, which makes the `jti` the thing a revocation row holds: the minting
 * side has to hand the `jti` back so an operator can revoke it, and the verifying side has to hand
 * it forward so {@see RevokedTokens} can be asked about it. Two callers needing the same three
 * claims is what a value is for, and {@see AccessTokens} is then the only class in the tree that
 * knows what a claim is called.
 *
 * ## The `jti` is an `Id`, and the phantom tag is this class
 *
 * ADR 002 identifies everything with a ULID-backed {@see Id} carrying a phantom `@template`, and a
 * token identifier is an identifier like any other. The tag is `Id<self>` rather than
 * `Id<RevokedToken>`, which is the tempting alternative and is wrong for a reason worth writing
 * down: almost no token is ever revoked, so a tag naming the revocation row would name the
 * exception. The identifier is the token's; the row is one thing that may later be written about
 * it. {@see \App\Shared\Infrastructure\Persistence\Outbox\OutboxMessage} tags its own identifier
 * the same way.
 *
 * ## It is a value and not an entity, and nothing stores it
 *
 * ADR 009's table is explicit: `ui_credential` holds the account, `ui_revoked_token` holds a `jti`
 * and an instant, and **the token itself is stored nowhere of ours**. That is the whole difference
 * between this design and the opaque token it replaces, which kept the bearer token in a primary
 * key in clear text. A token this application has issued and never revoked leaves no trace here at
 * all, which is also why `config/services.php` excludes this file: it is a value an edge builds,
 * never a service and never an entity.
 */
final readonly class AccessToken
{
    /**
     * @param Id<self>   $jti
     * @param Id<PrincipalAggregate> $subject
     */
    private function __construct(
        public Id $jti,
        public Id $subject,
        public \DateTimeImmutable $expiresAt,
        public string $encoded,
    ) {
    }

    /**
     * The one named constructor, used by both sides of {@see AccessTokens}: `issue()` passes the
     * string it just signed, `verify()` passes the string it was just presented with. There is no
     * second constructor because there is no second shape; a token being minted and a token being
     * read differ in where the four values came from and in nothing else.
     *
     * @param Id<self>   $jti
     * @param Id<PrincipalAggregate> $subject
     */
    public static function of(Id $jti, Id $subject, \DateTimeImmutable $expiresAt, string $encoded): self
    {
        return new self($jti, $subject, $expiresAt, $encoded);
    }
}
