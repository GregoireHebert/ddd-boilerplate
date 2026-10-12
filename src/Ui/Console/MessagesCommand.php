<?php

declare(strict_types=1);

namespace App\Ui\Console;

use App\Shared\Domain\Exception\FailureCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Translation\TranslatorBagInterface;
use Translation\FailureMessages;

/**
 * ADR 007's walkthrough, joining `app:paths` (ADR 003) and `app:reads` (ADR 004).
 *
 * It is the only one of the three that can fail, and that is the entire point of it. The other two
 * demonstrate; this one asserts. Under ADR 007 the English sentence lives in
 * `translations/failures+intl-icu.en.xlf` and nowhere else, so a declared code with no entry there
 * does not degrade to English, it ships `membership.handle-too-short` to a caller. That makes the
 * catalogue's completeness a correctness property, and a correctness property needs something that
 * can say no.
 *
 * ## Why this can exist at all
 *
 * Because the codes are declared as {@see FailureCode} cases rather than found by a parser. The
 * implementation ADR 007 was started from extracts message keys by walking the AST for string
 * literals, and an AST extractor that found nine codes out of ten reports success: it has no way to
 * know about the tenth. `cases()` is a total answer.
 *
 * The scan below is for the enums themselves, not for the codes. It looks in
 * `src/<Context>/Domain/Exception/` because that is where ADR 001 puts a context's failures and
 * where a new context's enum will be, so adding a bounded context does not mean remembering to edit
 * a list here. It is the same instinct as `config/services.php` autoregistering a resource glob and
 * `config/packages/ecotone.php` scanning namespaces, and it is deliberately dumber than either: no
 * container, no tags, no compiler pass, because an enum is not a service and never will be.
 *
 * ## What it reports
 *
 * Every declared code against every `framework.enabled_locales` entry. `defines()` rather than
 * `has()`, and the difference is the check: `has()` walks the fallback catalogues, so a French
 * lookup of a code that only exists in English answers yes, which is exactly right at runtime and
 * useless here. `defines()` asks whether *this* locale has it, which is the question a translator
 * is being handed.
 *
 * ```shell
 * bin/console app:messages          # a table, and exit 1 if anything is missing
 * bin/console app:messages -v       # the rendered English too, so a typo in ICU shows up
 * ```
 */
#[AsCommand(
    name: 'app:messages',
    description: 'Check every declared failure code against every enabled locale (ADR 007)',
)]
final class MessagesCommand extends Command
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        /**
         * The `translator` service by id, because FrameworkBundle aliases `TranslatorInterface`
         * and does not alias `TranslatorBagInterface`; the same reach-around
         * {@see \Translation\FailureMessages} makes, for the same missing alias.
         */
        #[Autowire(service: 'translator')]
        private readonly TranslatorBagInterface $catalogues,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Declared failure codes, against the "'.FailureMessages::DOMAIN.'" catalogue');

        $enums = $this->failureCodeEnums();

        if ([] === $enums) {
            $io->error('No FailureCode enum found under src/*/Domain/Exception/. That is not a clean catalogue, it is a broken scan.');

            return Command::FAILURE;
        }

        $rows = [];
        $missing = 0;

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                $row = [$case->code(), (new \ReflectionClass($enum))->getShortName().'::'.$case->name];

                foreach ($this->enabledLocales as $locale) {
                    $defined = $this->catalogues->getCatalogue($locale)->defines($case->code(), FailureMessages::DOMAIN);
                    $missing += $defined ? 0 : 1;
                    $row[] = $defined ? '<info>yes</info>' : '<error>MISSING</error>';
                }

                if ($output->isVerbose()) {
                    $row[] = $this->catalogues->getCatalogue($this->enabledLocales[0] ?? 'en')
                        ->get($case->code(), FailureMessages::DOMAIN);
                }

                $rows[] = $row;
            }
        }

        $headers = ['code', 'declared as', ...$this->enabledLocales];

        if ($output->isVerbose()) {
            $headers[] = 'template ('.($this->enabledLocales[0] ?? 'en').')';
        }

        $io->table($headers, $rows);

        if (0 !== $missing) {
            $io->error(\sprintf('%d catalogue entr%s missing.', $missing, 1 === $missing ? 'y is' : 'ies are'));

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%d codes across %d enums, complete in %s.',
            \count($rows),
            \count($enums),
            implode(', ', $this->enabledLocales),
        ));

        return Command::SUCCESS;
    }

    /**
     * Every enum implementing {@see FailureCode}, found by looking where ADR 001 says a context's
     * failures live rather than by asking a container that has never heard of them.
     *
     * @return list<class-string<FailureCode>>
     */
    private function failureCodeEnums(): array
    {
        $found = [];

        $files = (new Finder())
            ->files()
            ->in($this->projectDir.'/src')
            ->path('#(^|/)Domain/Exception/#')
            ->name('*.php');

        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'App\\'.str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));

            if (!enum_exists($class) || !is_a($class, FailureCode::class, true)) {
                continue;
            }

            /** @var class-string<FailureCode> $class */
            $found[] = $class;
        }

        sort($found);

        return $found;
    }
}
