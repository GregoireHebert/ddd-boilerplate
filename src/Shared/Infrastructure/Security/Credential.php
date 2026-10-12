<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Models\Id;
use App\Shared\Domain\Models\PrincipalAggregate;

/**
 * What the demo stores so that somebody can prove they are a member.
 *
 * ## ADR 003 path 1, and it is the cleanest example of one in the tree
 *
 * A flat row whose shape matches the class: six columns, no value object beyond {@see Id}, no
 * embeddable, no child collection, no custom type of its own. `docs/storage-map.md` describes path 1
 * as "written and read by identity alone, never filtered and never part of any aggregate's
 * invariants", and this row is all three: nothing lists credentials, nothing paginates them, and no
 * aggregate's rule mentions one. The username lookup is an identity lookup on a unique column, not
 * a filter; the difference is that it returns one row or none and there is no ordering, no window
 * and no criteria to combine.
 *
 * ## Why it is owned by `Ui` and not by a bounded context
 *
 * ADR 008 takes this decision and it is the one that raises an eyebrow. A credential has no business
 * behaviour, no invariant and no language of its own; giving it a context would produce a context
 * with one entity and nothing to decide, which is the thing DDD warns about rather than the thing it
 * asks for. `Membership` never learns that authentication exists, exactly as
 * {@see SecurityUser} says `Member` never learns it.
 *
 * The trigger that would change that is written down now so it is recognised later: **when
 * credentials grow invariants** (lockout after N failures, password history, MFA enrolment, recovery
 * codes) they have a state machine and a language, and at that point they are an `Identity` context
 * and this table moves with them. Not before.
 *
 * ## The identifier a human types is the username, and it is not the handle
 *
 * ADR 009 rule 10: **credentials are identified by something a human typed**, never by a ULID. The
 * first implementation of this class had no such field at all and asked somebody to sign in with a
 * `Member` identifier, which is ADR 007 rule 9 with a form around it: a ULID is on no screen and in
 * no password manager, so it is not a value you can ask a person for.
 *
 * A **username** and not an email address, and the choice is worth the paragraph because rule 10
 * allows either. An email address is a second thing about a person: it needs verifying, it changes,
 * it is the input to a recovery flow nothing here can perform, it is personal data that ADR 003's
 * erasure saga would then owe an erasure of, and "is this address confirmed" is a state machine.
 * Every one of those is the `Identity` context trigger two paragraphs up, arriving in the field
 * itself. A username is one unique string a person chose and can type, with no second question
 * attached to it, and it is the only shape that leaves this row on path 1. Nothing in this tree
 * sends anybody anything, so an email column would be a contact detail with no consumer, which is
 * the argument ADR 007 already made for not putting a locale on an aggregate.
 *
 * It is **not** the handle, and the two coexist on purpose: {@see SecurityUser::getUserIdentifier()}
 * argues that a renameable handle is a session that silently belongs to nobody, and a handle is
 * renameable by design (membership/03). So a member has two human-readable handles, one presentational
 * and changeable and one a credential, and the fact that they are different columns in different
 * tables owned by different layers is the point rather than duplication.
 *
 * What the row deliberately does not carry is any format rule. How long a username may be and what
 * it may contain is policy nobody has decided, and the table enforces the one thing that has to be
 * true whatever the answer: it is unique.
 *
 * ## The three constants, and why they are not a contradiction of the paragraph above
 *
 * That paragraph was written when the only caller was `bin/console app:credentials:issue`, which an
 * operator runs at a terminal. ADR 009's "Registration is also where the password is set" gave the
 * row a second caller, `POST /members`, and a stranger's payload is not an operator's hand: the edge
 * now has to say what it will accept, and ADR 005 option A says it has to say it with domain
 * constants rather than with numbers written into an attribute.
 *
 * So the constants are **the edge's rule and not the row's**. The table still enforces uniqueness
 * and nothing else, `Credential::issue()` still takes any string, and the console still writes a
 * one-character username if an operator asks it to. What the three of them buy is a registration
 * form whose bounds are published in the OpenAPI schema and cited by
 * `MemberRegistrationResource` instead of
 * repeated in it.
 *
 * There is still no charset rule, and that absence is the same decision as before rather than an
 * oversight: rule 10 allows an email address *or* a username, and any pattern narrow enough to be
 * worth writing would rule one of the two out. Length is the one bound that is true of both.
 *
 * {@see self::PASSWORD_MIN_LENGTH} sits here although no password ever does, and that is on purpose:
 * it is a fact about what this credential will accept as proof, and the alternative homes are a
 * constraint attribute (a literal, which option A forbids) or a config key (invisible to the class
 * that owns the concept). Nothing about it weakens "this class takes a hash and never a plaintext".
 *
 * ## One member has one credential, and that follows from the `sub` claim
 *
 * `Credential.orm.xml` carries a second unique constraint, `uniq_credential_member`, and
 * {@see \App\Ui\Console\Security\IssueCredentialCommand} refuses a second row for the same member
 * before it gets there. No ADR states that as a product rule, and it is not one: it is what the
 * token design already decided, arriving in the schema.
 *
 * {@see AccessTokens::issue()} puts the **member** in `sub`, not the credential and not the
 * username, because ADR 008's Keycloak section says an identity server arrives as a provider
 * mapping `sub` onto an `Id<PrincipalAggregate>`. Resolving a presented token therefore goes member-first:
 * {@see Credentials::forMember()} is asked for the row that carries the roles, and its signature
 * answers one row or none. Two rows for one member would make `findOneBy()` pick one of them by
 * whatever order the database felt like, so the same token would grant different permissions on
 * different days, and there would be nothing in the claim to say which row was meant. The
 * constraint is what makes that lookup an identity lookup rather than a query with an arbitrary
 * answer, which is also what keeps this table on ADR 003 path 1.
 *
 * So it is not a policy about how many logins a person may have. **It is the uniqueness the `sub`
 * claim assumes**, and the day somebody wants two logins for one member the thing that has to move
 * first is what a token carries.
 *
 * ## It is not the bearer token either, and that is ADR 009 rule 9
 *
 * The first implementation made this row's primary key the opaque bearer token, so the token lived
 * in clear text and revoking it deleted the password login sharing the row. A revocable token is a
 * JWT whose `jti` is a row in {@see RevokedToken}, the credential's identifier went back to being an
 * ordinary surrogate, and the two lifetimes ADR 009's table separates are separate again.
 *
 * ## A session is stored nowhere of ours
 *
 * ADR 009 rule 9's first half, and the reason there is no third table: Symfony manages a session,
 * expires it and garbage-collects it, and a row beside it would be a second lifetime to keep in step
 * with the first that could tell us nothing the session does not already know.
 *
 * ## The roles are here, and that is deliberate
 *
 * {@see Actor} carries no grants at all, on purpose: grants held on the actor are a cache with no
 * invalidation. {@see AccessTokens} refuses to put them in the token for the same reason. They are
 * looked up per call instead, and this column is where the lookup lands
 * ({@see ActorTokens}). {@see RoleMap} then turns a role into permissions, and it stays the only
 * file in the tree that names one.
 */
final class Credential
{
    /**
     * The bounds a registration payload is held to. See "The three constants" above for why an edge
     * rule lives on the class that owns the concept, and why there is no pattern beside them.
     */
    public const int USERNAME_MIN_LENGTH = 3;

    public const int USERNAME_MAX_LENGTH = 40;

    /**
     * Twelve, and the number is a choice rather than a citation. It is long enough that the usual
     * eight-character advice is not what this tree teaches, and short enough that nobody reaches for
     * a password manager to register a demo account. If it ever has to be argued rather than picked,
     * the argument belongs in ADR 008 and this constant is what moves.
     *
     * `app:credentials:issue` does not apply it, and that is the same split as the two above: an
     * operator issuing a credential by hand is trusted, a stranger posting one is not.
     */
    public const int PASSWORD_MIN_LENGTH = 12;

    /**
     * @param Id<self>     $id
     * @param Id<PrincipalAggregate>   $memberId
     * @param list<string> $roles
     */
    private function __construct(
        private readonly Id $id,
        private readonly Id $memberId,
        private readonly string $username,
        private readonly string $passwordHash,
        private readonly array $roles,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * The one named constructor. It takes a hash rather than a password, because hashing is
     * Symfony's `PasswordHasherInterface`'s job and a class that accepted a plaintext would be a
     * class somebody eventually stored one in.
     *
     * @param Id<self>     $id
     * @param Id<PrincipalAggregate>   $memberId
     * @param list<string> $roles
     */
    public static function issue(Id $id, Id $memberId, string $username, string $passwordHash, array $roles, \DateTimeImmutable $createdAt): self
    {
        return new self($id, $memberId, $username, $passwordHash, $roles, $createdAt);
    }

    /**
     * @return Id<self>
     */
    public function id(): Id
    {
        return $this->id;
    }

    /**
     * @return Id<PrincipalAggregate>
     */
    public function memberId(): Id
    {
        return $this->memberId;
    }

    /**
     * What somebody types into the sign-in form, and what `loadUserByIdentifier()` is given.
     */
    public function username(): string
    {
        return $this->username;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
