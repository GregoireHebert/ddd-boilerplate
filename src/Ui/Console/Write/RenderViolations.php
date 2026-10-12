<?php

declare(strict_types=1);

namespace App\Ui\Console\Write;

use App\Shared\Application\Validation\InvalidPayload;
use Symfony\Component\Console\Style\SymfonyStyle;
use Translation\FailureMessages;

/**
 * The console half of ADR 005 lock 12: *one error object, two renderers*, which ADR 007 turns into
 * *one error object, one translator, three renderers*.
 *
 * Over HTTP an `InvalidPayload` becomes a `ConstraintViolationList` inside API Platform's problem
 * document, with a `propertyPath` and a `code` per entry. Here the same object becomes one stderr
 * line per field and exit code 22, and nothing between the two renderers is duplicated: the
 * violations were built once, by the same `Violations` call, against the same domain constructors,
 * and their words come out of the same catalogue through the same {@see FailureMessages}.
 *
 * That symmetry is the argument for option B rather than a nicety. Option A's constraints live on an
 * API resource and validate an HTTP request; there is no version of them a console command can run,
 * which is why {@see RegisterMemberCommand} parses through the domain even though `POST /members`
 * does not.
 *
 * It only ever sees an `InvalidPayload`, including for stage 2. A cross-field failure such as
 * `CannotLikeSelfException` is raised outside any `at()` and would otherwise escape as an uncaught
 * throwable and exit 1, telling a script "this broke" where the API said "your payload is wrong" for
 * the identical input. The calling command converts it with `Violations::of()` instead, so one
 * renderer covers both stages and a script parses one shape.
 *
 * ## Why there is no `--locale`
 *
 * The translator is here, and it renders at `framework.default_locale`, because an operator is not
 * an end user: `app:members:register` exists to prove option B serves more than one delivery, and
 * whoever runs it is debugging this application rather than using it. ADR 007 calls that a fixed
 * locale rather than an exemption, and the difference matters: under that ADR the English lives
 * only in the catalogue, so the console does not bypass the translator, it simply never negotiates.
 *
 * A trait rather than a base class, because these commands already extend Symfony's `Command`. The
 * translator arrives as an argument rather than a property for the same reason: a trait that reads
 * `$this->something` it never declared is a trait that breaks whichever class forgets.
 */
trait RenderViolations
{
    private function renderViolations(SymfonyStyle $io, InvalidPayload $failure, FailureMessages $messages): int
    {
        $lines = [];

        foreach ($messages->translate($failure->getConstraintViolationList()) as $violation) {
            // An empty property path is stage 2: the failure belongs to the relationship between
            // two fields rather than to either of them.
            $lines[] = \sprintf(
                '%s%s (%s)',
                '' === $violation->getPropertyPath() ? '' : $violation->getPropertyPath().': ',
                $violation->getMessage(),
                (string) $violation->getCode(),
            );
        }

        $io->error($messages->render($failure));
        $io->listing($lines);

        return InvalidPayload::EXIT_CODE;
    }
}
