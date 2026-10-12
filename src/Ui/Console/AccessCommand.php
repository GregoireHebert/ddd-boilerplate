<?php

declare(strict_types=1);

namespace App\Ui\Console;

use ApiPlatform\JsonRpc\MethodRegistry;
use ApiPlatform\JsonRpc\RpcMethod;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\Shared\Infrastructure\Security\Guarded;
use App\Shared\Infrastructure\Security\Permission;
use App\Shared\Infrastructure\Security\Permissions;
use App\Shared\Infrastructure\Security\RoleMap;
use App\Shared\Infrastructure\Security\Unguarded;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouterInterface;

/**
 * ADR 008's walkthrough, and the fourth of the `app:*` commands after `app:paths` (ADR 003),
 * `app:reads` (ADR 004) and `app:messages` (ADR 007).
 *
 * It is the second one that can fail, and it fails for the same reason the first one does.
 *
 * > **This command proves that nothing is missing. It never proves that anything is right.**
 *
 * That sentence is the whole of its honesty and it is worth reading twice. `app:messages` proves
 * that every declared failure code has a catalogue entry in every enabled locale; it cannot tell
 * you the sentence is correct, or even that it is in the right language. This one proves that every
 * write a delivery exposes names a permission or a reason for having none and that every declared
 * permission is granted to somebody, and it reads those same declarations backwards to report every
 * permission that no operation asks for; it cannot tell you the permission is the right one, that
 * the reason is a good one, that the role map is sane, that the subject is taken from the
 * principal, or that ownership answers `404`. Three behaviours rest on a
 * paragraph in ADR 008 and would each fit in a test, and ADR 008 says so in its own closing section
 * rather than letting this command imply otherwise.
 *
 * ## Why this can exist at all
 *
 * Because both sides of the question are **enumerable**, and that is the single reason ADR 008
 * declares permissions as enum cases and keeps the enums in one directory beside the gate rather
 * than in each context (they moved out of `Ui` with the rest of the security vocabulary and now sit
 * in `App\Shared\Infrastructure\Security\Permission\`, which changes where the `Finder` looks and
 * nothing else about the argument):
 *
 * - the declared set is {@see Permissions::all()}, found by a `Finder` over one directory, the way
 *   {@see MessagesCommand} finds its `FailureCode` enums and for the same reason: a hand-maintained
 *   registry that missed one enum would report success for an application with an unguarded context
 *   in it;
 * - the exposed set is four registries that already exist for other reasons: API Platform's
 *   resource metadata, {@see MethodRegistry}, the router, and the console's own command list.
 *   Nothing here maintains a fifth list of operations, which is the property that stops this command
 *   drifting from the application it is describing.
 *
 * **Both halves being declarations is also why the question has two directions, and why the second
 * one cost nothing.** "Which operations name no permission" and "which permissions does no
 * operation name" are one join read in either order, so the second needed no parser, no AST walk
 * and no new declaration; it needed somebody to ask for it. It went unasked for as long as it did
 * because the gap it finds is invisible from the operation's side, where it is a row that is simply
 * not there.
 *
 * Contrast the thing it replaces, the expression-language `security:` string whose shape
 * {@see \App\Shared\Infrastructure\Security\Guarded} quotes in full, inside the one directory allowed
 * to name a role. That string is a
 * small program in a string; nothing can read its intent, so an API declared that way cannot answer
 * "which writes are unguarded" and therefore cannot be checked at all. That is the third of ADR
 * 008's five reasons for an attribute of our own, and this file is it.
 *
 * ## What it reads, per delivery
 *
 * | delivery | the operations come from                      | the declaration is                     |
 * |----------|-----------------------------------------------|----------------------------------------|
 * | ReST     | the resource metadata collection              | {@see Guarded} on the resource class, or {@see Unguarded} |
 * | RPC      | {@see MethodRegistry::all()}                  | {@see RpcMethod::permission()}         |
 * | Twig     | the router, filtered to `App\Ui\Web\`         | {@see Guarded} on the controller class, or {@see Unguarded} |
 * | console  | the `Application`'s own command list          | {@see Guarded} on the command class, or {@see Unguarded} |
 *
 * The fourth row is ADR 009 rule 14's, and it is the one this command was built without. Until it
 * existed, three writes on the badge collection page guarded themselves by calling the gate inline from
 * one of five actions on one class, and nothing could walk them; a class attribute can be walked,
 * and an attribute on one of five methods is a thing you have to remember to look for.
 *
 * **It reads the attribute, never the generated expression.**
 * {@see \ApiPlatform\Metadata\GuardedOperationsFactory} writes a `security`
 * string from the declaration, so by the time an operation reaches anything else the declared and
 * the hand-written are indistinguishable. Reading `getSecurity()` here would report a hand-written
 * expression as guarded, which is exactly the case ADR 008 wants reported as a gap: the command
 * reports what the design can see, and an operation the design cannot see is one it does not have.
 *
 * ## What counts as a gap, and what is merely listed
 *
 * Five things fail the run, and each is a bug the other ends of the design cannot catch:
 *
 * - **a ReST write with no declaration of either kind.** Option B is for every write without
 *   exception, and a write is the operation whose HTTP method is not safe. A read with no
 *   declaration is option A's territory ("anybody authenticated", decided by the firewall) and is
 *   listed without failing. A write carrying {@see Unguarded} is listed with its sentence in the
 *   permission column and does not fail either, which is the whole of ADR 009 rule 13: the command
 *   had to be able to tell "somebody decided no" from "nobody decided", and before the attribute
 *   existed both of them looked like the absence of {@see Guarded}. **It reports the sentence and
 *   never judges it**, exactly as it reports a permission without saying it is the right one;
 * - **an application RPC method with no permission.** Every method under `Ui/Api/JsonRpc/` is
 *   something this application chose to expose, read or write, and `POST /rpc` is one path carrying
 *   all of them, so the firewall can say nothing about any one. A protocol method, meaning one in
 *   {@see RpcMethod}'s own namespace, may answer `null`, and today that is `rpc.discover`;
 *   {@see MethodRegistry} already draws that line to decide who may claim the `rpc.` prefix, and
 *   {@see self::protocolNamespace()} is the second reading of it;
 * - **a `Ui/Web` controller declaring neither.** Stricter than the ReST rule on purpose, and ADR
 *   009 rule 14 is why: a `Ui/Web` controller declares one or the other with no carve-out for a
 *   read, because there is no option A here for an undeclared page to fall back on. A page
 *   anonymous readers must reach is a decision somebody took, and `/login` and `/register` say so
 *   in their own words rather than by saying nothing;
 * - **a declared permission no role grants.** The same bug seen from the other end: a case nobody
 *   holds is a capability nobody can reach, and it will be discovered by a support ticket rather
 *   than by a reader;
 * - **a class declaring {@see Guarded} and {@see Unguarded} at once**, on any of the four
 *   surfaces. ADR 009 asks for the two declarations quoted and a non-zero exit rather than a
 *   precedence rule, because a precedence rule would let somebody make a guarded operation
 *   unguarded by adding a line rather than by removing one.
 *
 * A **console command with no declaration is listed and never fails the run.** Nothing in a console
 * command's signature says whether it writes, and most of them legitimately do not have an actor:
 * `app:outbox:relay`, `app:subscriptions:project`, `app:paths` and `app:reads` act as the
 * installation. ADR 008 rule 7 is why that is a state rather than a gap: an operation reached with
 * no actor is *unguarded* rather than privileged, and the absence of a principal is not a
 * principal.
 *
 * ## The other direction, which is reported and never fatal
 *
 * Everything above walks from an operation to its permission. The last table walks from a
 * permission to its operations, and it asks two things of each declared case: **which roles grant
 * it**, and **which operations ask for it**. The first is a gap and fails the run, because a case
 * nobody holds is a capability nobody can reach. The second is a finding and does not.
 *
 * The second question is the one this command did not ask for its first few commits, and the three
 * holes closed in the batch that added it had one signature in this output: declared in the enum,
 * granted to every member in `RoleMap`, asked by nothing, while the endpoint answered `200` to
 * anybody at all. They were `membership.directory.browse` against `GET /members`,
 * `matching.feed.browse` against `GET /members/{memberId}/candidate-feed`, and
 * `membership.membership.view` against `GET /subscriptions`, which drew the first row of everybody's
 * membership as "your membership". From the operation's side each was a read with no declaration,
 * which is option A's territory and is listed without failing; from this side each was a permission
 * somebody wrote, somebody granted, and nothing ever used. **A permission that nothing asks for is
 * either a permission that should not exist or an operation that forgot to ask**, and neither
 * declaration says which.
 *
 * That last sentence is also the argument against failing the run on it, which is the instinct and
 * is wrong here. ADR 008 was written ahead of its code on purpose and says so in its own closing
 * section, and the two permission enums are the board's whole vocabulary, including the cards
 * nobody has started. A rule that failed on an unasked case would be red today, red tomorrow, and
 * red once per unbuilt card, and **a check that is expected to be red is a check nobody reads**,
 * including the four above it that are worth reading. The asymmetry is the honest one: an unguarded
 * write is reachable by a stranger right now, and a permission nothing asks for is reachable by
 * nobody. The first is a hole in the application; the second is a hole in the plan.
 *
 * So it prints, in the row and in a block beside the verdict, and the exit code is unchanged. What
 * a reader does with a row is one question, and `docs/kanban/` answers it: a case whose card is
 * built is an operation that forgot to ask, which is the bug above; a case whose card is not built
 * is the vocabulary arriving before the feature, which is this ADR's own sequencing. The first
 * column cannot help with that question, and it is worth knowing why: a case no role grants already
 * fails the run, so **every case in this list is one somebody holds and nothing uses**.
 *
 * ```shell
 * bin/console app:access               # every operation on all four deliveries, and what guards it
 * bin/console app:access --unguarded   # only what nobody decided, in both directions
 * ```
 *
 * Both modes apply the same rules and return the same exit code. `--unguarded` narrows what is
 * printed and never what is checked, because a filter that changed the verdict would be a second
 * opinion with the same name. In the permissions table it keeps the rows nothing grants and the
 * rows nothing asks for, which are the two kinds somebody has to look at.
 *
 * That rule is also why every reporter records what asks for a permission *before* it consults the
 * flag. Recording after it would report every permission as asked by nothing the moment somebody
 * passed `--unguarded`, which is the verdict changing with the flag through the back door, in the
 * direction that invents findings rather than hiding them.
 */
#[AsCommand(
    name: 'app:access',
    description: 'Walk every operation on all four deliveries against the declared permissions (ADR 008)',
)]
final class AccessCommand extends Command
{
    private const string UNGUARDED = 'unguarded';

    /**
     * RFC 9110's safe methods. Everything else changes something and is therefore a write, which is
     * the one thing ADR 008 requires a declaration for without exception.
     */
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * Classes declaring {@see Guarded} **and** {@see Unguarded}, collected by every reporter as it
     * walks and printed once at the end.
     *
     * Keyed by class, because the Twig surface reports one row per route and a controller with two
     * routes would otherwise be counted twice for one mistake.
     *
     * @var array<class-string, array{permission: string, because: string}>
     */
    private array $contradictions = [];

    /**
     * Which operations ask for each permission, collected by every reporter as it walks and read
     * once by {@see self::reportPermissions()} when all four have run.
     *
     * Keyed by {@see Permission::wire()} rather than by the case, because an array key is a string
     * and because {@see Permissions} already keys the declared set that way: two enums claiming one
     * wire string collapse there first, which is where a reader would go looking for it.
     *
     * @var array<non-empty-string, list<string>>
     */
    private array $askedBy = [];

    /**
     * The declared permissions no operation asks for, filled by {@see self::reportPermissions()}
     * and spoken in {@see self::execute()} beside the verdict rather than under the table, because
     * beside the verdict is where a reader looks to find out what the run thinks.
     *
     * @var list<non-empty-string>
     */
    private array $unasked = [];

    public function __construct(
        private readonly ResourceNameCollectionFactoryInterface $resourceNames,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadata,
        private readonly MethodRegistry $methods,
        private readonly Permissions $permissions,
        private readonly RoleMap $roles,
        /**
         * The Twig surface's registry, and the reason it is the router rather than a `Finder` over
         * `src/Ui/Web/`. This class's docblock gives the rule: the exposed set is registries that
         * already exist for other reasons, and nothing here maintains a list of operations of its
         * own. A controller class nobody routed is not an operation anybody can reach, and a glob
         * would have reported one as a gap.
         */
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(self::UNGUARDED, null, InputOption::VALUE_NONE, 'Print only what nobody decided: the operations nothing guards, and the permissions nothing asks for. The verdict is unchanged.');

        $this->setHelp(<<<'HELP'
            Walks the four delivery surfaces against the declared permissions (ADR 008).

            It proves that nothing is MISSING. It never proves that anything is RIGHT: that every
            write names a permission is a property a scanner can check, and that the permission is
            the correct one, that the subject comes from the principal, and that ownership answers
            404 rather than 403 are not. This is exactly as much as <info>app:messages</info> proves
            about the failure catalogue, and for exactly the same reason.

            It exits non-zero when a ReST write declares neither a permission nor a reason for
            having none, when a method under Ui/Api/JsonRpc/ declares none, when a controller under
            Ui/Web/ declares neither, when a declared permission is granted to no role, or when one
            class declares #[Guarded] and #[Unguarded] at once.

            An operation may say it is deliberately unguarded with #[Unguarded(because: '...')],
            and the sentence is printed in its row. POST /members is the one that started it: a
            permission is a question about an actor, and registering is the transition from nobody
            to a member.

            A console command with no declaration is listed and is not a failure: an operation with
            no actor is unguarded rather than privileged, and most console commands act as the
            installation. A Ui/Web controller with none IS a failure, because ADR 009 rule 14 gives
            a page no third state to be in.

            The last table asks the question backwards: for each declared permission, the operations
            that ask for it, across all four deliveries. A permission nothing asks for is PRINTED
            and never fails the run, because a permission may legitimately precede the operation
            that will ask for it, and ADR 008 was written ahead of its code on purpose. Read such a
            row by opening its card in docs/kanban/: a feature that is built and asks for nothing is
            an operation that forgot to ask. Three unguarded reads had exactly that signature and
            were found by hand before this column existed, which is why it exists. A permission
            granted to nobody is the column beside it and does fail the run.
            HELP);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $onlyUnguarded = true === $input->getOption(self::UNGUARDED);
        $this->contradictions = [];
        $this->askedBy = [];
        $this->unasked = [];

        $io->title('Operations, and what guards each of them');

        $gaps = 0;
        $gaps += $this->reportRest($io, $onlyUnguarded, $output->isVerbose());
        $gaps += $this->reportRpc($io, $onlyUnguarded);
        $gaps += $this->reportWeb($io, $onlyUnguarded);
        $this->reportConsole($io, $onlyUnguarded);
        $gaps += $this->reportPermissions($io, $onlyUnguarded);

        // Printed before either verdict and never instead of one. It is a finding rather than a
        // gap, so it neither fails a green run nor softens a red one, and a reader who scrolls to
        // the bottom of a failing run still meets it on the way.
        if ([] !== $this->unasked) {
            $io->warning(\sprintf(
                '%d declared permission%s that no operation asks for, marked NOTHING in the last table. A permission nothing asks for is either a permission that should not exist or an operation that forgot to ask, and no declaration says which, so this is reported and the run is not failed by it. The card in docs/kanban/ settles it: a feature that is built and asks for nothing is the bug, and a feature nobody has started yet is the vocabulary arriving before the code, which ADR 008 did on purpose.',
                \count($this->unasked),
                1 === \count($this->unasked) ? '' : 's',
            ));
        }

        if ([] !== $this->contradictions) {
            $rows = [];

            foreach ($this->contradictions as $class => $both) {
                $rows[] = [
                    (new \ReflectionClass($class))->getShortName(),
                    $both['permission'],
                    $both['because'],
                ];
            }

            $io->section('Classes answering the same question twice');
            $io->table(['declared on', '#[Guarded]', "#[Unguarded(because: '...')]"], $rows);

            $io->error(\sprintf(
                '%d class%s declaring both. The two attributes are answers to the same question and only one of them can be true; preferring one quietly would let somebody make a guarded operation unguarded by adding a line rather than by removing one.',
                \count($this->contradictions),
                1 === \count($this->contradictions) ? '' : 'es',
            ));

            return Command::FAILURE;
        }

        if (0 !== $gaps) {
            $io->error(\sprintf(
                '%d gap%s. A write nothing guards is an operation anybody may reach; a permission nobody holds is a capability nobody can reach.',
                $gaps,
                1 === $gaps ? '' : 's',
            ));

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Every write names a permission or says why it has none, and every permission is granted to somebody%s. Nothing is missing; nothing here says anything is right.',
            [] === $this->unasked ? ' and asked by something' : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * `-v` adds the expression {@see \ApiPlatform\Metadata\GuardedOperationsFactory}
     * generated onto the operation, which is the same favour `app:messages -v` does by printing the
     * rendered ICU template beside the declared code: the declaration is what somebody wrote and
     * this is what the framework will actually evaluate, and seeing the two in one row is how a
     * hand-written expression the factory did not write gives itself away.
     *
     * @return int the number of unguarded writes
     */
    private function reportRest(SymfonyStyle $io, bool $onlyUnguarded, bool $verbose): int
    {
        $rows = [];
        $gaps = 0;

        foreach ($this->resourceNames->create() as $resourceClass) {
            // `POST /rpc` is one path carrying every method there will ever be, so a declaration on
            // it could say nothing about any of them. That is the same fact about path regexes that
            // ADR 008 opens its authentication section with, and it is why the RPC surface declares
            // per method instead. The placement is the statement, exactly as `MethodRegistry` reads
            // it: a resource in the protocol's own namespace is the protocol's door, not an
            // operation.
            $isRpcDoor = str_starts_with($resourceClass, self::protocolNamespace());

            // API Platform registers its own `Error` resources, which belong to the framework's
            // problem-document machinery rather than to this application's surface. Nothing
            // declares a permission for somebody else's resource, and listing them would put rows
            // in this table that no reader could act on. The door is the one resource of ours that
            // is not under `App\`: the protocol is written as a package that could be extracted,
            // so it is named like one, and skipping it would drop `POST /rpc` out of a table whose
            // whole job is that an operation cannot go missing from it.
            if (!class_exists($resourceClass) || (!$isRpcDoor && !str_starts_with($resourceClass, 'App\\'))) {
                continue;
            }

            // ADR 009 rule 13. Read here rather than inferred from anything, because the whole
            // point is that an absence cannot be told from a decision by looking at what is there:
            // only a declaration somebody wrote can say which of the two this is.
            [$guard, $unguarded] = $this->declarationsOn($resourceClass);

            foreach ($this->resourceMetadata->create($resourceClass) as $resource) {
                // Every member of an `Operations` collection is an `HttpOperation`, which is what
                // makes `getMethod()` safe to ask without a check. A GraphQL operation would not
                // be, and this project does not have one; if it ever does, PHPStan will say so
                // here before anything else does.
                foreach ($resource->getOperations() ?? [] as $operation) {
                    $path = $operation->getUriTemplate() ?? '-';

                    // A write resource has no item operation, so API Platform mints a skolem IRI
                    // route to give JSON-LD an `@id` to put on the answer. ADR 006 says that is
                    // correct rather than a gap; it is also not an operation anybody calls, and a
                    // row per write resource saying so would be nine rows of framework plumbing in
                    // a table whose whole job is to be read.
                    if (str_starts_with($path, '/.well-known/genid')) {
                        continue;
                    }

                    $method = strtoupper($operation->getMethod());
                    $isWrite = !\in_array($method, self::SAFE_METHODS, true);
                    $required = $isWrite && !$isRpcDoor;
                    $isGap = $required && null === $guard && null === $unguarded;
                    $gaps += $isGap ? 1 : 0;

                    // Recorded per operation rather than per class: a read resource carries one
                    // declaration for every operation it serves, and each of those operations is a
                    // place where the permission is asked for. Recorded before the filter below,
                    // for the reason {@see self::asks()} gives.
                    if (null !== $guard) {
                        $this->asks($guard->permission, 'rest', $method.' '.$path);
                    }

                    if ($onlyUnguarded && null !== $guard) {
                        continue;
                    }

                    $row = [
                        $method,
                        $path,
                        $isWrite ? '<comment>write</comment>' : 'read',
                        $isRpcDoor ? 'per method, see below' : self::declared($guard?->permission, $unguarded, $isGap, 'none, option A or the firewall'),
                        (new \ReflectionClass($resourceClass))->getShortName(),
                    ];

                    if ($verbose) {
                        $row[] = $operation->getSecurity() ?? '-';
                    }

                    $rows[] = $row;
                }
            }
        }

        $headers = ['method', 'path', 'kind', 'permission', 'declared on'];

        if ($verbose) {
            $headers[] = 'generated expression';
        }

        $io->section('ReST, from the resource metadata');
        $io->table($headers, $rows);

        return $gaps;
    }

    /**
     * @return int the number of application methods declaring nothing
     */
    private function reportRpc(SymfonyStyle $io, bool $onlyUnguarded): int
    {
        $rows = [];
        $gaps = 0;

        foreach ($this->methods->all() as $name => $method) {
            $permission = $method::permission();

            // The line {@see MethodRegistry} already draws, read a second time: the protocol's own
            // namespace is what JSON-RPC does and may answer `null`, and everything under
            // `Ui/Api/JsonRpc/` is something this application chose to expose.
            $isApplication = !str_starts_with($method::class, self::protocolNamespace());
            $gaps += $isApplication && null === $permission ? 1 : 0;

            if (null !== $permission) {
                $this->asks($permission, 'rpc', $name);
            }

            if ($onlyUnguarded && null !== $permission) {
                continue;
            }

            $rows[] = [
                $name,
                $isApplication ? '<comment>application</comment>' : 'protocol',
                self::declared($permission, null, $isApplication, 'none, the protocol describing itself'),
                (new \ReflectionClass($method))->getShortName(),
            ];
        }

        $io->section('RPC, from the method registry. One path, POST /rpc, so the firewall can say nothing about any one of these');
        $io->table(['method', 'namespace', 'permission', 'declared on'], $rows);

        return $gaps;
    }

    /**
     * The fourth surface, and the one ADR 009 rule 14 built a convention for.
     *
     * **The operations come from the router**, which is the same property the other three have: the
     * resource metadata, {@see \ApiPlatform\JsonRpc\MethodRegistry} and the console's command list all exist for another
     * reason, and none of them is a list this command maintains. A `Finder` over `src/Ui/Web/` was
     * the obvious alternative and is the wrong one twice over: it would report a controller nobody
     * routed as an unreachable gap, and it would miss the one thing the router knows and a directory
     * does not, which is the method and the path a reader actually types.
     *
     * The gap rule is stricter than the ReST one and deliberately so. There, a read with no
     * declaration is option A's territory, decided by the firewall, and is listed without failing.
     * Here there is no such state: rule 14 says a `Ui/Web` controller "declares `#[Guarded]` or
     * `#[Unguarded]` on the class" with no carve-out for a read, because a page anonymous readers
     * must reach is a decision somebody took rather than a question nobody asked. `/login` and
     * `/register` are exactly that decision and they now say so.
     *
     * **Every row in this table is now one class, and the table is what proved it.** The sentence
     * here used to plan around the opposite: a controller with two routes printed twice, and that was
     * read as rule 14 being visible rather than as work left. `LoginController` was the last one, two
     * routes and two actions on one class printing one sentence onto two rows, and it is now
     * {@see \App\Ui\Web\LoginFormController} and {@see \App\Ui\Web\LogoutController} with a
     * declaration each.
     *
     * The loop still reads `Class::method` as well as a bare class, and that stays. It is what lets
     * this table show a class that has not been split yet, which is the one way the next one gets
     * found; the rule being satisfied today is not the same as the code being unable to break it.
     *
     * @return int the number of controllers declaring neither
     */
    private function reportWeb(SymfonyStyle $io, bool $onlyUnguarded): int
    {
        $rows = [];
        $gaps = 0;

        foreach ($this->router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');

            if (!\is_string($controller)) {
                continue;
            }

            // `Class::method` for an action and the bare class for an `__invoke()`, which after rule
            // 14 is every controller in this directory. Both spellings are read, because a class
            // that has not been split yet is precisely the thing this table has to be able to show.
            $class = strstr($controller, '::', true) ?: $controller;

            if (!str_starts_with($class, 'App\\Ui\\Web\\') || !class_exists($class)) {
                continue;
            }

            [$guard, $unguarded] = $this->declarationsOn($class);
            $isGap = null === $guard && null === $unguarded;
            $gaps += $isGap ? 1 : 0;

            $methods = $route->getMethods();
            $verbs = [] === $methods ? 'ANY' : implode('|', $methods);

            // The route and not the controller, because one class may still carry two routes and
            // the reader of the permissions table wants the thing a caller types.
            if (null !== $guard) {
                $this->asks($guard->permission, 'web', $verbs.' '.$route->getPath());
            }

            if ($onlyUnguarded && null !== $guard) {
                continue;
            }

            $rows[] = [
                $verbs,
                $route->getPath(),
                $name,
                // The last argument is what a row says when nobody decided and it is not a gap.
                // On this surface there is no such row: a page with no declaration is always a gap,
                // so the prose is here for the signature and can never be printed.
                self::declared($guard?->permission, $unguarded, $isGap, 'none, and rule 14 leaves a page no third state'),
                (new \ReflectionClass($class))->getShortName(),
            ];
        }

        $io->section('Twig, from the router. One route, one action, one controller file, so one row is one class');
        $io->table(['method', 'path', 'route', 'permission', 'declared on'], $rows);

        return $gaps;
    }

    /**
     * Listed, never fatal. See this class's docblock: nothing in a console command's signature says
     * whether it writes, and an operation with no actor is unguarded rather than privileged.
     */
    private function reportConsole(SymfonyStyle $io, bool $onlyUnguarded): void
    {
        $rows = [];

        foreach ($this->getApplication()?->all() ?? [] as $name => $command) {
            // Every command registered through `#[AsCommand]` arrives wrapped, because the whole
            // point of the attribute is that the container does not instantiate a command to find
            // out its name. The wrapper's class is Symfony's, so reading the attribute off it finds
            // nothing, silently, and this table was empty the first time it ran.
            if ($command instanceof LazyCommand) {
                $command = $command->getCommand();
            }

            if (!str_starts_with($command::class, 'App\\Ui\\Console\\')) {
                continue;
            }

            // Both, and the second one was missing until ADR 009 rule 14 went looking. A command
            // carrying `#[Unguarded]` printed exactly as one that forgot, which is the ambiguity
            // rule 13 exists to remove, and `app:badge:draw` was the command it was removed for.
            [$guard, $unguarded] = $this->declarationsOn($command::class);

            // A console command that names a permission asks for it through
            // {@see \App\Ui\Console\Security\ConsoleGuard}, so it belongs in the index beside the
            // three network surfaces: `app:likes:cast` is as good an answer to "what asks for
            // `matching.like.cast`" as `POST /likes` is.
            if (null !== $guard) {
                $this->asks($guard->permission, 'console', $name);
            }

            if ($onlyUnguarded && null !== $guard) {
                continue;
            }

            $rows[] = [
                $name,
                // Never a gap on this surface, which is why the third argument is `false`: nothing
                // in a console command's signature says whether it writes, and ADR 008 rule 7 makes
                // an operation with no actor unguarded rather than privileged.
                self::declared($guard?->permission, $unguarded, false, 'none, acting as the installation'),
                (new \ReflectionClass($command))->getShortName(),
            ];
        }

        $io->section('Console, from the command list. An unguarded command here is a state and not a gap');
        $io->table(['command', 'permission', 'declared on'], $rows);
    }

    /**
     * The same question from both of its other ends, and the only table that runs last because it
     * reads what the other four wrote.
     *
     * **Granted to.** A declared case no role holds is a capability nobody can reach, and it is
     * found by a support ticket unless something walks the two lists together. {@see RoleMap} is
     * the only file in the tree allowed to name a role, which is why this is the only place a role
     * name is printed.
     *
     * **Asked by.** A declared case no operation names is the other half, and the class docblock
     * has the three unguarded reads it would have caught and the argument for reporting it rather
     * than failing on it. The column is {@see self::$askedBy}, filled by the four reporters above as
     * they walked; nothing here opens a delivery a second time, which is the same property that
     * keeps this command from maintaining a list of operations of its own.
     *
     * The two columns are read together and they are not symmetrical. `NOBODY` is a gap and
     * `NOTHING` is a finding, so a run can be green with half this column saying `NOTHING` and that
     * is the ordinary state of a board whose vocabulary is complete and whose cards are not.
     *
     * @return int the number of permissions nothing grants, which is the only one of the two
     *             questions that fails the run
     */
    private function reportPermissions(SymfonyStyle $io, bool $onlyUnguarded): int
    {
        $rows = [];
        $gaps = 0;

        foreach ($this->permissions->all() as $permission) {
            $granting = $this->roles->rolesGranting($permission);
            $asked = $this->askedBy[$permission->wire()] ?? [];
            $gaps += [] === $granting ? 1 : 0;

            if ([] === $asked) {
                $this->unasked[] = $permission->wire();
            }

            // `--unguarded` keeps both kinds of row here, which is the flag meaning what it says
            // rather than widening: it prints what nobody decided, and a permission nothing asks
            // for is as undecided as an operation nothing guards. It still narrows only what is
            // printed; the counting above happens either way.
            if ($onlyUnguarded && [] !== $granting && [] !== $asked) {
                continue;
            }

            $rows[] = [
                $permission->wire(),
                (new \ReflectionClass($permission))->getShortName().'::'.$permission->name,
                [] === $granting ? '<error>NOBODY</error>' : implode(', ', $granting),
                // One asker per line rather than one cell of comma-separated prose: four
                // deliveries may ask for the same permission, `membership.badges.acquire` already
                // has three, and the point of the column is that a reader can see at a glance
                // which surfaces reach a capability.
                [] === $asked ? '<comment>NOTHING</comment>' : implode("\n", $asked),
            ];
        }

        $io->section('Declared permissions, against the policy and against the operations');
        $io->table(['permission', 'declared as', 'granted to', 'asked by'], $rows);

        return $gaps;
    }

    /**
     * One operation, on one delivery, asking for one permission.
     *
     * Every reporter calls this **before** it consults `--unguarded`, which is that flag's rule
     * applied to a second table: narrowing what is printed must not narrow what is known. Recording
     * after the filter would have reported every permission as asked by nothing the moment somebody
     * passed the flag, and the direction of that mistake is the bad one, because it invents findings
     * rather than hiding them.
     *
     * The label names the delivery as well as the operation, because the four surfaces share a
     * vocabulary of names, paths and verbs, and the question is usually about which of them. Three
     * things ask for `membership.badges.acquire`: `rpc membership.acquireBadge`,
     * `web POST /me/badges/acquire` and `console app:badges:acquire`. That is ADR 006's line
     * between a resource and a method read from the policy's side, and a label that dropped the
     * delivery would have dropped the only part of it a reader was looking for.
     *
     * @param string $delivery  one of the four surfaces, as its own section names it
     * @param string $operation what a caller types, as that delivery spells it
     */
    private function asks(Permission $permission, string $delivery, string $operation): void
    {
        $label = $delivery.' '.$operation;
        $asked = $this->askedBy[$permission->wire()] ?? [];

        // One row per operation and not per declaration read. A ReST resource is reflected once per
        // operation and a controller once per route, so the same pair can arrive twice without
        // anything being wrong.
        if (!\in_array($label, $asked, true)) {
            $asked[] = $label;
        }

        $this->askedBy[$permission->wire()] = $asked;
    }

    /**
     * The JSON-RPC protocol's own namespace, derived from {@see RpcMethod} rather than written out.
     *
     * {@see MethodRegistry::all()} draws this line with `__NAMESPACE__` to decide who may claim a
     * reserved `rpc.` name, and this file reads the same line from outside, twice: for the resource
     * that is the protocol's door, and for the method that may answer no permission at all.
     *
     * It is derived because it was written out, and the protocol package then moved out of
     * `App\Ui\Infrastructure\ApiPlatform\JsonRpc\`. Both spelled-out prefixes stopped matching in
     * the quietest way available: `POST /rpc` left the ReST table without a row saying why, and
     * `rpc.discover` was reported as an application method with no permission, which failed the run
     * for the one method that cannot have one. A command whose job is to notice an absence should
     * not be the thing that creates one, so the prefix now follows the interface wherever it goes.
     */
    private static function protocolNamespace(): string
    {
        return substr(RpcMethod::class, 0, (int) strrpos(RpcMethod::class, '\\') + 1);
    }

    /**
     * What one class says about who may reach it, read once, in one place, for all four surfaces.
     *
     * Three of them were reading it differently before ADR 009 rule 14: the ReST reporter read both
     * attributes, the console reporter read only {@see Guarded}, and the Twig surface was not read
     * at all. A helper is how that stops being a thing each reporter has to remember.
     *
     * **A class carrying both is a contradiction and this is where it is caught.** ADR 009 says so
     * and says why: the alternative is a precedence rule, and a precedence rule would mean somebody
     * can make a guarded operation unguarded by adding a line rather than by removing one. The two
     * are answers to the same question and only one of them can be true. It is collected rather
     * than thrown so that the run finishes and the reader sees every row before the verdict.
     *
     * @param class-string $class
     *
     * @return array{0: ?Guarded, 1: ?Unguarded}
     */
    private function declarationsOn(string $class): array
    {
        $guard = Guarded::on($class);
        $unguarded = Unguarded::on($class);

        if (null !== $guard && null !== $unguarded) {
            $this->contradictions[$class] = [
                'permission' => $guard->permission->wire(),
                'because' => $unguarded->because,
            ];
        }

        return [$guard, $unguarded];
    }

    /**
     * A missing declaration reads as an error where it is one and as prose where it is not, because
     * a table that shouts at every read would teach a reader to stop looking at it. The prose is the
     * caller's, because the surfaces have different reasons for an operation to be unguarded: a
     * ReST read is option A's or the firewall's, `rpc.discover` is the protocol describing itself,
     * and a console command acts as the installation.
     *
     * An {@see Unguarded} declaration outranks all of that and is printed on every surface, which
     * is ADR 009 rule 13 in one line: it hands over its author's own sentence, **printed verbatim
     * and never checked, abbreviated or rewritten**. It is the only cell in any of these tables
     * whose content this application did not generate, and that is the mechanism working rather
     * than a weakness in it: what makes the declaration worth something is that a person had to
     * write it.
     */
    private static function declared(?Permission $permission, ?Unguarded $unguarded, bool $isGap, string $whenNobodyDecided): string
    {
        if (null !== $permission) {
            return $permission->wire();
        }

        if (null !== $unguarded) {
            return \sprintf('<comment>none, deliberately: %s</comment>', $unguarded->because);
        }

        return $isGap ? '<error>NONE</error>' : \sprintf('<comment>%s</comment>', $whenNobodyDecided);
    }
}
