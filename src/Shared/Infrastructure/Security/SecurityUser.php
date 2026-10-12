<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What Symfony means by a user, which is not what this application means by a member.
 *
 * Symfony wants a `UserInterface` with roles and an identifier. `Membership` has a `Member` with a
 * handle, a badge and a badge collection. Making the aggregate implement the framework interface is the
 * shortcut, and it is the same widening ADR 005 spent a section **removing** for the validator and
 * ADR 007 removed again for the translator. Three ADRs is enough to call it a rule:
 *
 * > A framework interface on an aggregate is a delivery concern that has found a way into the
 * > model.
 *
 * So this class holds an {@see Actor} and a role list, it lives in `Ui`, and `Member` never learns
 * that authentication exists.
 *
 * ## It carries the password hash, and only because the component's contract needs it
 *
 * ADR 008 says this class "implements `PasswordAuthenticatedUserInterface` and `UserInterface` and
 * it knows nothing about badges", and the authentication commit is where the first half of that
 * becomes load-bearing: `CheckCredentialsListener` hands the user to a `PasswordHasherInterface`,
 * and `config/packages/security.php` keys its hasher on that very interface. There is no way to
 * verify a form login without the hash being reachable from here, short of writing an authenticator,
 * which ADR 008 lists under what was deliberately *not* written.
 *
 * So the hash is a constructor argument, it defaults to `null`, and the null arm is the one that
 * matters: {@see ActorTokens::for()} builds a `SecurityUser` for an actor that never presented a
 * password at all (a console assertion, for instance), and that object must not be able to pretend
 * it could authenticate one.
 *
 * What still never reaches this object is a **token** or a session id, or anything else a caller
 * presented. This is the *authenticated* shape, built after something else decided the caller is who
 * they say they are, and the one credential on it is here because Symfony asks for it by name.
 *
 * The role list is the exception that proves which rule is which, so it is worth being exact about.
 * It **is** a grant list and it **is** a constructor argument, because `getRoles()` is on
 * `UserInterface` and it is where a token's `getRoleNames()` comes from, which is the only thing
 * {@see PermissionVoter} reads; a `SecurityUser` with no roles is one no permission can be granted
 * to. What ADR 008 forbids is a grant held on the
 * {@see Actor}, which carries none and says why in its own docblock: grants on the actor are a cache
 * with no invalidation. These are not cached. They are read off the credential row on the call that
 * built this object ({@see ActorTokens}, {@see CredentialProvider}), they live exactly as long as
 * the object does, and {@see RoleMap} turns them into permissions when the gate is asked. The
 * `Actor` inside travels; this wrapper does not.
 *
 * ## It wraps an `Actor` rather than duplicating one
 *
 * The alternative is a `SecurityUser` holding an `Id<Member>` and a `CurrentActor` rebuilding an
 * `Actor` out of it, which is two representations of one fact drifting apart the first time a
 * principal kind is added. Holding the `Actor` means {@see ActorTokens} can compare identity in one
 * `equals()` and the delivery can hand back what it was given.
 *
 * ## It carries the username, because that is what Symfony means by an identifier
 *
 * ADR 009 rule 10 moved the login handle off the ULID and onto {@see Credential::username()}, and
 * `getUserIdentifier()` is the method the framework asks for it with: it is what a session holds, it
 * is what `refreshUser()` goes back through, and it is what a throttling key and a log line are
 * built from. So the username is a field here and the principal stays inside the `Actor`, which is
 * the split the rest of this file is about: Symfony's idea of who this is, and ours.
 */
final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private Actor $actor,
        private string $username,
        private array $roles,
        private ?string $passwordHash = null,
    ) {
    }

    public function actor(): Actor
    {
        return $this->actor;
    }

    /**
     * `null` when this user was built for somebody who did not present a password, which is every
     * caller the console produces. `CheckCredentialsListener` treats a null hash as a failed
     * verification, which is the answer we want: an object that could not have logged in cannot be
     * used to log in.
     */
    #[\Override]
    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * The username, which is what a human typed into the sign-in form and what a credential row is
     * found by.
     *
     * **Never the handle.** A handle is renameable ([membership/03](../../../../docs/kanban/membership/03_change-your-handle.md))
     * and a user identifier that changes when somebody edits their profile is a session that
     * silently belongs to nobody. A member therefore has two human-readable handles, and the fact
     * that they live in different tables owned by different layers is the design rather than
     * duplication.
     *
     * **And never the ULID**, which is what this method used to answer and what ADR 009 rule 10
     * corrects: a ULID is a value the reader cannot use (ADR 007 rule 9), so asking somebody to
     * sign in with one is that mistake with a form around it. The principal is still here, inside
     * the {@see Actor}, which is where a `CurrentActor` reads it from.
     *
     * The empty string is reachable from one place only, {@see ActorTokens}, when it fabricates a
     * token for a principal with no credential row; that object grants nothing and cannot be used
     * to sign in, which is the same fail-closed arm as the null password hash above.
     */
    #[\Override]
    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /**
     * Nothing to erase. The one credential this object holds is a hash, not a plaintext, and the
     * class is `readonly` so there is nothing to null in any case. Symfony 7.3 deprecated this
     * method for exactly the reason the empty body demonstrates: it was a hook for classes that kept
     * a plaintext around long enough to need clearing, and one that never does has nothing to say.
     */
    #[\Override]
    public function eraseCredentials(): void
    {
    }
}
