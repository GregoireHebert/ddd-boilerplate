# `.claude/`

Claude Code's configuration for this repository. It exists so that an agent
working here reaches the same decisions the ADRs already took, and so that
breaking one of them is loud rather than silent.

Nothing in here decides anything. **The ADRs lead, the code follows**, and this
directory follows both: every rule below is a restatement of a rule in
`docs/ADR/`, with the citation attached. When an ADR moves, a skill here owes it
an edit, and a skill that has drifted from its ADR is worse than no skill.

`CLAUDE.md` at the repository root is untouched and is still the always-loaded
index. The skills here carry the per-task detail that would not fit in it.

## What is here

```
.claude/
├── README.md          this file
├── settings.json      permissions allowlist, and the guard hook
├── hooks/
│   └── adr-guard.sh   a PreToolUse guard that warns and never blocks
└── skills/            ten task-shaped skills, loaded on demand
```

## The skills

One per decision area, named for the task rather than for the document, because
the task is what somebody types.

| skill                   | loads when                                                 | the document behind it                 |
|-------------------------|------------------------------------------------------------|----------------------------------------|
| `storing-an-aggregate`  | adding persistence, a mapping, a repository, a gateway     | ADR 003 + `docs/storage-map.md`        |
| `reading-and-filtering` | anything returning a list, filtering, paginating, counting | ADR 004                                |
| `validating-a-payload`  | checking input, adding a constraint or an exception        | ADR 005                                |
| `exposing-an-operation` | adding an endpoint, a resource, an RPC method              | ADR 006                                |
| `failure-messages`      | any message a caller could read; codes and the catalogue   | ADR 007                                |
| `guarding-an-operation` | permissions, ownership, entitlement, the firewall          | ADR 008                                |
| `rendering-a-representation` | a page, a template, `Accept`, a `Ui/Web` controller   | ADR 009                                |
| `wiring-a-class`        | the container, Ecotone or phpat cannot see a new class     | CLAUDE.md, `config/`, `tests/phpstan/` |
| `verify-the-tree`       | before calling a change done                               | CLAUDE.md, the `app:*` commands        |
| `writing-an-adr`        | writing or amending a decision record                      | `docs/ADR/` house style                |

Each one ends where the ADR begins: they carry the checklist and the traps, and
they point at the document for the argument. Read the ADR when the question is
*why*; the four walk commands (`app:paths`, `app:reads`, `app:messages` and
`app:access`) are usually faster than either when the question is *what does it
do today*.

## The guard hook

`hooks/adr-guard.sh` runs on every `Bash`, `Write` and `Edit`. It **warns and
never blocks**, which is deliberate: this tree keeps deliberately broken code
(see "Known in-flight breakage" in `CLAUDE.md`), so a veto would be wrong here.
Exit status is always 0 and the tool call always proceeds.

Sixteen rules, each one an ADR decision a regex can see:

| it notices                                                     | because                                                                 |
|----------------------------------------------------------------|-------------------------------------------------------------------------|
| `console doctrine:schema:update`                               | two entity managers share one connection; it drops the other's tables   |
| consuming a real Ecotone or Messenger channel                  | the dev queues are shared, and tests must never reach them              |
| a `.yaml` under `config/`                                      | Symfony config here is PHP; the YAML equivalents were deleted           |
| `sprintf` in a `Domain/Exception/`                             | a failure carries a code and parameters, never a sentence (ADR 007 r2)  |
| a `/` in a declared failure code                               | it breaks `ValidationException::getId()` route generation (ADR 005 l13) |
| a quoted `ROLE_` outside `Shared/Infrastructure/Security/`     | ask for a permission, never a role (ADR 008 r1)                         |
| a translator outside `Ui` or `Shared/Infrastructure/Translation/` | only a delivery renders (ADR 007 r7)                                 |
| `Symfony\Component\Security` outside `Ui` or `Shared/Infrastructure/Security/` | `Security::getUser()` returns null in the worker (ADR 008 r17) |
| `Doctrine\ORM\Mapping` in a `Domain/`                          | mapping is XML under `Infrastructure` (ADR 003)                         |
| a `Domain/Repository/` interface returning a collection        | a repository answers by identity (ADR 004 r1)                           |
| `input:` or `output:` on a resource                            | every write is its own resource (ADR 005 l16)                           |
| an `#[ApiResource]` with no `description:`                     | the teaching docblock gets published as the tag description             |
| a numeric literal in an `#[Assert\...]`                        | a constraint's arguments must be domain constants (ADR 005 l9)          |
| a primitive id in a command DTO                                | a command carries value objects (ADR 005 r14)                           |
| `->flush()` in a repository                                    | `EcotoneDbalConfiguration` already does, and owns the transaction       |
| an em dash or en dash in a `.md`                               | the prose convention; `docs/` currently holds zero                      |

Every pattern is checked against the whole of `src/` and matches nothing that is
already correct, including the cases that look like violations and are not:
`output: false` on the RPC resource, `ROLE_` in `RoleMap` and its neighbours,
`findByHandle` and `findByMembers` returning one aggregate, and
`DoctrineMemberErasureRepository::flush()`. Rules that read code are gated to
`*.php`, so a document quoting PHP as a counter-example does not trip one.

**That claim is a claim, so re-run it.** Three rules spent the `Ui/Infrastructure`
dissolution firing on twenty correct files, because the vocabulary they exempt by
path moved to `Shared/Infrastructure/` and the patterns did not follow. A fourth,
`input:`/`output:`, matched nothing at all for the same reason. To check:

```shell
find src -name '*.php' | while read -r f; do
  jq -n --arg f "$PWD/$f" --rawfile c "$f" \
    '{tool_name:"Write",tool_input:{file_path:$f,content:$c}}' \
    | .claude/hooks/adr-guard.sh | jq -r --arg f "$f" 'select(.) | "FIRES \($f)"'
done
```

**If a rule starts firing on good code, the pattern is wrong, not the code.**
Narrow it in the script; each rule is a few lines with a comment naming its ADR.

To turn the guard off, delete the `hooks` block from `settings.json`, or run
`/hooks` to review it.

## The permissions

`settings.json` allows, without prompting: reads, `make phpstan` and
`vendor/bin/phpstan`, `vendor/bin/phpunit`, `php -l`, the read-only console
commands (`debug:*`, `ecotone:list`, `api:openapi:export`, `app:messages`,
`app:access`),
`composer show`/`validate`, and the read-only git commands.

**`app:paths` and `app:reads` are allowed too, and they write to the database.**
They are the project's own demo commands against a local dev database and
CLAUDE.md presents them as the way to exercise the tree, which is why they are
on the list; `--keep` leaves their data behind. Remove those two lines if you
would rather be asked.

Nothing is denied. That is the same decision as the guard: this repository is a
teaching tree whose breakage is sometimes the lesson.

## Personal overrides

Put anything you do not want committed in `.claude/settings.local.json`, which
overrides this file. It is not currently in `.gitignore`.
