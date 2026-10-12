<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;


/**
 * **The policy, and the only file a subsystem replaces.**
 *
 * That is the test ADR 008 sets for itself and this class is where it is taken: the day a Keycloak
 * or a Casbin arrives, this file is deleted and {@see PermissionVoter} gets a different body.
 * Nothing else in the application changes, because nothing else in the application names a role.
 * If something does have to change, something leaked, and finding out which thing is the point of
 * keeping the policy in one readable place.
 *
 * Casbin would replace it with a model and a policy store and `Enforcer::enforce($sub, $obj, $act)`;
 * Keycloak with client roles, or with UMA 2.0 resources and scopes evaluated against an RPT; OPA
 * with Rego and an input document. All three are richer than this array and none of them is visible
 * from anywhere that asks a question.
 *
 * ## Flat, and no hierarchy
 *
 * `ROLE_MODERATOR` does not include `ROLE_MEMBER`, because a moderator is not a member: no badge, in
 * no feed, cannot be liked. Symfony's `role_hierarchy` would express an inclusion that does not
 * exist here, and a hierarchy is in any case the sort of thing a policy server is *for*. Two flat
 * lists are legible; the day they stop being legible is the day the subsystem earns itself.
 *
 * ## Every member holds every member permission, and that is not a waste
 *
 * Read the two lists and the interesting thing is how little they discriminate. That is ADR 008
 * working: a permission is "may somebody like you do this at all", and almost everything a member
 * does passes that test. What actually stops a caller is ownership (answered by the aggregate,
 * `404`) or an entitlement (answered by a local projection, `422` or a narrower representation),
 * and neither is expressible here on purpose. Move `matching.pair.extend` out of `ROLE_MEMBER` and
 * changing what the Membership Plan includes becomes a ticket in somebody else's policy server.
 *
 * `ROLE_MODERATOR`'s list is empty because `Trust` does not exist yet, and an empty list is the
 * correct representation of that: the role is declared, it grants nothing, and the first moderator
 * permission lands with the context that raises it.
 */
final readonly class RoleMap
{
    /**
     * Spelled once, because {@see self::registrationRoles()} needs to name it and ADR 008 rule 1
     * keeps every role name inside this file. Two spellings of one role is how a typo becomes a
     * credential that signs in and can do nothing.
     */
    private const string MEMBER = 'ROLE_MEMBER';

    /**
     * The declared type is what makes an EMPTY map analysable: left to inference PHPStan reads
     * `array{}` and reports every `in_array()` below as impossible, which would make an
     * ungranted boilerplate look broken.
     *
     * @var array<string, list<Permission>>
     */
    private const array GRANTS = [
        // **Empty, and this file still exists, which is the whole point of ADR 008 rule 1.**
        //
        // This is the policy, and it is the only file in the tree that may name a role. It is also
        // the only file that gets DELETED the day a Keycloak, a Casbin or an OPA arrives, which is
        // the test of whether anything else leaked: if removing this file breaks something that is
        // not a grant, a role name escaped.
        //
        // Fill it as your contexts declare permissions. One row per role, every case listed
        // explicitly, no hierarchy:
        //
        //     self::MEMBER => [
        //         MembershipPermission::ViewDirectory,
        //         MembershipPermission::RenameMember,
        //         MatchingPermission::CastLike,
        //     ],
        //
        // `bin/console app:access` fails when a declared permission is granted to nobody, so an
        // empty map plus a declared permission is a loud state rather than a quiet one.
        //
        // Note what does NOT go here, because it is the distinction ADR 008 exists to draw. There
        // is no `member.rename-anybody`: renaming somebody else is not a bigger permission, it is
        // a different question, answered by the aggregate with a `404`. And there is no
        // `membership.plan.rewind`: what a plan includes is an entitlement read from a local
        // projection, answering `422` with what would lift it. Neither is a grant, and neither may
        // be delegated to a policy server, because both need this application's store.
        self::MEMBER => [],
        'ROLE_MODERATOR' => [],
    ];

    /**
     * @param list<string> $roles the token's own role names, unresolved, because there is no
     *                            hierarchy to resolve
     */
    public function grants(array $roles, Permission $permission): bool
    {
        foreach ($roles as $role) {
            /** @var list<Permission> $granted */
            $granted = self::GRANTS[$role] ?? [];

            if (\in_array($permission, $granted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every role this policy knows about, which is the one question a caller outside this file is
     * allowed to ask about roles.
     *
     * `bin/console app:credentials:issue` writes a role list into a credential row, so somebody has
     * to name one; what ADR 008 rule 1 forbids is a **call site** hard-coding the answer, and the
     * command satisfies it by refusing a role this array does not declare and by having no default
     * of its own. So this file stays the only one that names a role, the command names the set it
     * validates against, and the day a policy server arrives and this file is deleted, the command
     * stops compiling rather than silently writing roles nothing grants.
     *
     * @return list<string>
     */
    public function declaredRoles(): array
    {
        return array_keys(self::GRANTS);
    }

    /**
     * What somebody who has just registered holds, and the reason this is a method rather than a
     * literal at the one call site.
     *
     * ADR 009's "Registration is also where the password is set" makes `POST /members` write a
     * credential row, and a credential row carries roles. `bin/console app:credentials:issue` gets
     * its answer from an operator's `--role`; a stranger registering has nobody to ask, so the
     * policy has to answer, and ADR 008 rule 1 says the policy is this file. A
     * `['ROLE_MEMBER']` written in `RegisterMemberProcessor` would
     * be the second file in the tree naming a role, which is the one property that makes this class
     * replaceable by a Keycloak or a Casbin.
     *
     * It is deliberately not "every declared role minus the privileged ones" or anything else
     * computed: a registration grants the ordinary member role and nothing beyond it, and the day
     * somebody registers as something else, that is a decision with a name and it gets its own
     * method here.
     *
     * @return list<string>
     */
    public function registrationRoles(): array
    {
        return [self::MEMBER];
    }

    /**
     * For `bin/console app:access`, which reports a permission nothing grants as loudly as an
     * operation nothing guards. Both are the same bug seen from opposite ends: a declared case that
     * no role holds is a capability nobody can reach.
     *
     * @return list<string>
     */
    public function rolesGranting(Permission $permission): array
    {
        $roles = [];

        foreach (self::GRANTS as $role => $granted) {
            /** @var list<Permission> $granted */
            if (\in_array($permission, $granted, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }
}
