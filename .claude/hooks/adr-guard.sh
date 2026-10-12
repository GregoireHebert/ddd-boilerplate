#!/usr/bin/env bash
#
# adr-guard.sh -- a PreToolUse guard for the DDD/CQS.
#
# It WARNS and never blocks. Every rule below is one an ADR already decided and
# that a regex can see; the guard exists so that breaking one is loud rather
# than silent, not so that it becomes impossible. This tree deliberately keeps
# broken code (see "Known in-flight breakage" in CLAUDE.md), so a veto would be
# wrong here.
#
# Every pattern was checked against the tree at the time it was written and
# matched nothing that is already correct. If one of them starts firing on good
# code, the pattern is wrong, not the code: narrow it here.
#
# Input:  the PreToolUse hook payload, on stdin.
# Output: nothing at all when the call is clean; otherwise JSON carrying a
#         `systemMessage` for the human and an `additionalContext` for Claude.
#         Exit status is always 0.

set -u

payload=$(cat)

command -v jq >/dev/null 2>&1 || exit 0

tool=$(printf '%s' "$payload" | jq -r '.tool_name // ""' 2>/dev/null) || exit 0
file=$(printf '%s' "$payload" | jq -r '.tool_input.file_path // ""' 2>/dev/null)
cmd=$(printf '%s' "$payload" | jq -r '.tool_input.command // ""' 2>/dev/null)
body=$(printf '%s' "$payload" \
  | jq -r '[.tool_input.content?, .tool_input.new_string?] | map(select(. != null)) | join("\n")' 2>/dev/null)

out=""
note() { out="${out}
- $1"; }

# Does the written text contain this basic-regex pattern? `-e` so a pattern may
# begin with a dash.
has() { printf '%s' "$body" | grep -q -e "$1"; }
# Same, extended regex.
hasE() { printf '%s' "$body" | grep -q -E -e "$1"; }

# ---------------------------------------------------------------- shell rules

case "$cmd" in
  *console*doctrine:schema:update*)
    note "\`doctrine:schema:update\` is not usable in this project. Two entity managers share one connection, and it diffs one manager's metadata against the whole database, so each one drops the other's tables. Use \`doctrine:schema:create --em=default\` and \`--em=read_model\`, or a migration. (CLAUDE.md \"Commands\", docs/storage-map.md path 3.)"
    ;;
esac

case "$cmd" in
  *"ecotone:run"*|*"messenger:consume"*)
    note "Consuming a real channel publishes to the shared dev RabbitMQ. Tests must never reach it: \`MessageChannelBuilderFactory\` swaps in in-memory channels under \`APP_ENV=test\` and \`config/packages/messenger.php\` points the transports at \`in-memory://\`. Check which environment this runs in. (CLAUDE.md \"Testing\".)"
    ;;
esac

# ------------------------------------------------------------ file-write rules

if [ "$tool" = "Write" ] || [ "$tool" = "Edit" ]; then

  # Is this PHP? Every rule below that reads code is gated on it, so a document
  # quoting PHP as a counter-example (a skill, an ADR, a card) never trips one.
  is_php=no
  case "$file" in *.php) is_php=yes ;; esac

  # Symfony config is PHP here. The YAML equivalents were deleted on purpose.
  case "$file" in
    */config/*.yaml|*/config/*.yml)
      note "Symfony config in this project is PHP, not YAML: every \`config/**.php\` returns \`App::config([...])\` and \`config/reference.php\` types those arrays. The YAML equivalents were deleted; do not reintroduce them. (CLAUDE.md \"Configuration style\".)"
      ;;
  esac

  # ADR 007 rule 2: a failure carries a code and named parameters, never a sentence.
  case "$file" in
    */Domain/Exception/*)
      if [ "$is_php" = yes ] && { has 'sprintf(' || has 'vsprintf('; }; then
        note "A domain exception must not build a sentence. It carries a declared \`code()\` and a named \`parameters()\` map; the English lives in \`translations/failures+intl-icu.en.xlf\` and nowhere else. (ADR 007 rule 2, \`DeclaredFailure\`.)"
      fi
      ;;
  esac

  # ADR 005 lock 13 / ADR 007 rule 3: a declared code may not contain a slash.
  case "$file" in
    *Failure.php)
      if [ "$is_php" = yes ] && hasE "= *'[a-z0-9]+/"; then
        note "A failure code may not contain a \`/\`: \`ValidationException::getId()\` composes the problem IRI out of the violation codes and a slash breaks route generation. The separator is a dot, matching the routing-key convention. (ADR 005 lock 13.)"
      fi
      ;;
  esac

  # ADR 008 rule 1: ask for a permission, never for a role. Scoped to `src/`,
  # because the rule is about a call site: `config/packages/security.php` is
  # where the policy legitimately names roles, and `config/reference.php` is the
  # generated shape of it.
  #
  # The exempt directory is `Shared/Infrastructure/Security/`, not
  # `Ui/Infrastructure/Security/`: the vocabulary moved when `Ui/Infrastructure`
  # was dissolved, and this pattern spent that move firing on `RoleMap`, `Gate`,
  # `PermissionVoter`, `Guarded` and `Permission`, all of them correct.
  # The pattern wants a role *literal*, so it requires the opening quote. Bare
  # `ROLE_` also matched prose: `MemberResource`'s docblock explains why
  # `RoleMap` grants its two permissions to `ROLE_MEMBER` and nothing else, and
  # naming a role in order to argue about it is not naming one at a call site.
  if [ "$is_php" = yes ] && hasE "['\"]ROLE_"; then
    case "$file" in
      */Shared/Infrastructure/Security/*) : ;;
      */src/*)
        note "Ask for a permission, never for a role. A role is somebody's answer to the question and it is what a Keycloak or a Casbin replaces; naming one at a call site hard-codes the subsystem the design exists to keep swappable. \`RoleMap\` and its neighbours under \`Shared/Infrastructure/Security/\` are the only files that may name one. (ADR 008 rule 1.)"
        ;;
    esac
  fi

  # ADR 007 rule 7: only a delivery renders. Enforced by phpat too.
  #
  # `FailureMessages` left `App\Ui` with the `Ui/Infrastructure` dissolution and
  # now carries the `Translation\` PSR-4 prefix, so it is exempt here exactly as
  # `SharedArchitectureTest::no_translator_outside_ui()` excludes that namespace.
  case "$file" in
    */src/Ui/*|*/src/Shared/Infrastructure/Translation/*) : ;;
    */src/*)
      if [ "$is_php" = yes ] && { has 'Symfony\\Component\\Translation' || has 'Symfony\\Contracts\\Translation' || has '->trans('; }; then
        note "Only a delivery may name a translator. \`Shared/Infrastructure/Translation/FailureMessages\` is the one class in the tree that calls \`trans()\`; a domain, an application service and a context's infrastructure all produce facts and have no reader. phpat's \`no_translator_outside_ui\` will fail on this. (ADR 007 rule 7.)"
      fi
      ;;
  esac

  # ADR 008 rule 17: only a delivery may name the security component.
  #
  # Same move, same exemption: the vocabulary and the firewall adapters live in
  # `Shared/Infrastructure/Security/` and legitimately name the component, which
  # is what `SharedArchitectureTest::no_security_outside_ui()` excludes. The
  # violation this rule is actually afraid of is autowiring, anywhere else, so
  # `Security\Core\Exception\` is not it: catching Symfony's `AccessDeniedException`
  # to turn it into our `Forbidden` is error mapping, which is what
  # `Shared/Infrastructure/ApiPlatform/Provider/ErrorTypingProvider` is for.
  case "$file" in
    */src/Ui/*|*/src/Shared/Infrastructure/Security/*) : ;;
    */src/*)
      if [ "$is_php" = yes ] \
        && { hasE 'Symfony\\Component\\Security\\(Core|Http)\\(Authent|Author|User|Security)' \
          || has 'Symfony\\Bundle\\SecurityBundle'; }; then
        note "Only a delivery, or \`Shared/Infrastructure/Security/\`, may name \`Symfony\\\\Component\\\\Security\`. The violation to be afraid of is \`Security::getUser()\` autowired into a context's infrastructure service: it compiles, works in a request, and returns null in the worker and on the console. phpat's \`no_security_outside_ui\` will fail on this. (ADR 008 rule 17.)"
      fi
      ;;
  esac

  # ADR 003: the domain class carries no Doctrine metadata; mapping is XML under Infrastructure.
  case "$file" in
    */Domain/*)
      if [ "$is_php" = yes ] && has 'Doctrine\\ORM\\Mapping'; then
        note "A domain model carries no Doctrine mapping. The mapping is XML under the context's \`Infrastructure/Persistence/Doctrine/ORM/Mapping/\`, which is what keeps the phpat domain rules intact. (ADR 003, docs/storage-map.md path 1.)"
      fi
      ;;
  esac

  # ADR 004 rule 1: a repository answers by identity; a read model answers by criteria.
  case "$file" in
    */Domain/Repository/*)
      if [ "$is_php" = yes ] && { hasE 'public function [a-zA-Z]+\([^)]*\) *: *(array|iterable|Collection)' \
        || has 'Criteria' || has 'PageOf' || has 'SliceOf'; }; then
        note "A repository answers by identity; a read model answers by criteria. A \`Domain/Repository/\` interface that hands back a collection is a promise at least two of ADR 003's five storage paths cannot keep. Put the question in the context's \`Application/ReadModel/\` and return DTOs. (ADR 004 rule 1.)"
      fi
      ;;
  esac

  # ADR 005 lock 16: every write is its own resource.
  #
  # The paths are `src/Ui/Api/Resources/` and the RPC resource's own home under
  # `Shared/Infrastructure/ApiPlatform/JsonRpc/`, which is what
  # `resource_class_directories` scans. The older
  # `*/ApiPlatform/Resources/*|*/ApiPlatform/RPC/*` matched neither after the
  # move and this rule was silently dead.
  case "$file" in
    */Ui/Api/Resources/*|*/ApiPlatform/JsonRpc/*)
      if [ "$is_php" = yes ] && hasE '(input|output): *[A-Za-z][A-Za-z0-9_\\]*::class'; then
        note "\`input:\` and \`output:\` are not used in this tree. A registration is not a member: every write is its own \`#[ApiResource]\` with one operation, so the generated schema is named for the thing rather than for the mechanism. (ADR 005 lock 16.)"
      fi
      ;;
  esac

  # ADR 006 / CLAUDE.md: the generated OpenAPI document is a deliverable.
  if [ "$is_php" = yes ] && has '#\[ApiResource' && ! has 'description:'; then
    note "An \`#[ApiResource]\` needs \`description:\`. Without it API Platform falls back to the class PHPDoc and \`OpenApiFactory\` publishes that as the OpenAPI tag description, which ships the teaching docblock to callers as the public reference. Tag descriptions themselves are declared once in \`Shared/Infrastructure/ApiPlatform/OpenApi/TagDescriptions\`. (CLAUDE.md \"The generated OpenAPI document is a deliverable\".)"
  fi

  # ADR 005 lock 9: a constraint's arguments must be domain constants.
  if [ "$is_php" = yes ] && hasE '#\[Assert\\[A-Za-z]+\([^)]*[0-9]'; then
    note "A Symfony constraint's arguments must be domain constants, never literals: \`Assert\\Length(min: Handle::MIN_LENGTH)\`, never \`min: 3\`. One home for each value, and drift stops being a matter of discipline. (ADR 005 lock 9; the same rule covers a message parameter and an RPC \`Param\` schema.)"
  fi

  # ADR 005 rule 14: a command carries value objects; an event carries primitives.
  case "$file" in
    */Application/Command/*|*/Domain/Command/*)
      if [ "$is_php" = yes ] && hasE 'string \$[a-zA-Z]*[Ii]d\b'; then
        note "A command carries value objects; an event carries primitives. A command is an intention addressed to one handler inside one context and is never published, so it takes an \`Id<A>\` or whatever value object the field parses to, never a string. A primitive here means the edge cannot accumulate and a handler has to re-parse. (ADR 005 rule 14.)"
      fi
      ;;
  esac

  # CLAUDE.md "Transactions": no repository flushes.
  case "$file" in
    *DoctrineMemberErasureRepository.php) : ;;
    */Persistence/Repository/*)
      if [ "$is_php" = yes ] && has '->flush('; then
        note "No repository flushes. \`EcotoneDbalConfiguration\` already opens a DB transaction and flushes per async message and on the command bus. The one exception is \`DoctrineMemberErasureRepository\`, and its docblock says why. (CLAUDE.md \"Transactions\".)"
      fi
      ;;
  esac

  # The user's own prose rule, and the ADRs currently hold zero em dashes.
  case "$file" in
    *.md)
      if printf '%s' "$body" | grep -q -E -e '—|–'; then
        note "No em dashes or en dashes in this repository's prose: use a semicolon, a comma or a full stop. Every document under \`docs/\` currently holds zero of them."
      fi
      ;;
  esac

fi

[ -z "$out" ] && exit 0

header="ADR guard (advisory, nothing was blocked):"
message="${header}${out}"

jq -n --arg m "$message" '{
  systemMessage: $m,
  hookSpecificOutput: {
    hookEventName: "PreToolUse",
    additionalContext: $m
  }
}'

exit 0
