<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Every declared {@see Permission}, found rather than listed.
 *
 * The same mechanism {@see \App\Ui\Console\MessagesCommand} uses for `FailureCode`, and the same
 * reasoning: a list in a constant is a list somebody forgets to extend, and the whole value of
 * declaring permissions as enum cases is that the set is **enumerable**. A registry that missed one
 * enum would report success for an application with an unguarded context in it.
 *
 * It scans a directory rather than asking the container, because an enum is not a service and never
 * will be. No tags, no compiler pass, no locator: one `Finder` over one directory, which is also
 * deliberately dumber than `config/services.php`'s glob and `config/packages/ecotone.php`'s
 * namespace scan, both of which it is imitating.
 *
 * The cost is one filesystem walk per instantiation, which is once per request because this is a
 * service. That is cheap and it is not free, and the honest note is that it would stop being
 * acceptable if this were ever called from inside a loop over rows. It is not: {@see Gate} asks a
 * question per operation, not per record.
 *
 * ## What it is for
 *
 * Two callers, and they are the two halves of ADR 008's enforcement story:
 *
 * - {@see PermissionVoter} resolves the wire string Symfony hands it back into a declared case, so
 *   an attribute nobody declared is simply not supported and the voter abstains. A typo in a
 *   generated expression fails closed;
 * - `bin/console app:access` walks these against the declared operations on all four deliveries in
 *   both directions: every write that nothing guards, which fails the run, and every permission
 *   that no operation asks for, which is reported and does not. That command is why the
 *   declarations live beside the operations rather than in each context.
 */
final class Permissions
{
    /** @var array<string, Permission>|null */
    private ?array $byWire = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function tryFrom(string $wire): ?Permission
    {
        return $this->byWire()[$wire] ?? null;
    }

    /**
     * @return list<Permission>
     */
    public function all(): array
    {
        return array_values($this->byWire());
    }

    /**
     * @return array<string, Permission>
     */
    private function byWire(): array
    {
        if (null !== $this->byWire) {
            return $this->byWire;
        }

        $found = [];

        $files = (new Finder())
            ->files()
            ->in($this->projectDir.'/src/Shared/Infrastructure/Security/Permission')
            ->name('*.php');

        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'App\\Shared\\Infrastructure\\Security\\Permission\\'.substr($file->getFilename(), 0, -4);

            if (!enum_exists($class)) {
                continue;
            }

            // Split from the `enum_exists()` above rather than combined with `||`, because
            // `is_a($class, X::class, true)` is what narrows `class-string` to
            // `class-string<Permission>` and PHPStan cannot do that through a negated disjunction.
            // The narrowing is what makes `$class::cases()` a `list<Permission>` below.
            if (!is_a($class, Permission::class, true)) {
                continue;
            }

            foreach ($class::cases() as $case) {
                $found[$case->wire()] = $case;
            }
        }

        ksort($found);

        return $this->byWire = $found;
    }
}
