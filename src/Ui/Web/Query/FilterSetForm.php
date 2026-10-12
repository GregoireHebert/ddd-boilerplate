<?php

declare(strict_types=1);

namespace App\Ui\Web\Query;

use App\Shared\Application\Filter\Filter;
use App\Shared\Application\Filter\FilterKind;
use App\Shared\Application\Filter\FilterSet;
use App\Shared\Application\Filter\Window;
use App\Shared\Application\ReadModel\Total;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Option B's third surface, and the one [ADR 004](docs/ADR/004_filter.md) named and nobody built.
 *
 * Its two siblings are {@see \ApiPlatform\Metadata\DeclaredFiltersFactory}
 * and {@see \App\Ui\Console\Query\FilterSetOptions}. **Read both before changing this one**: the
 * three are the same class three times, each turning one {@see FilterSet} into the vocabulary its
 * delivery renders, and none of them knows the other two exist.
 *
 * | reader                   | delivery      | what it produces                           |
 * |--------------------------|---------------|--------------------------------------------|
 * | `DeclaredFiltersFactory` | API Platform  | `QueryParameter`s on every operation        |
 * | `FilterSetOptions`       | the console   | `InputOption`s and their `--help` lines     |
 * | this                     | a browser     | a {@see FormView} of fields, types, choices |
 *
 * ADR 004's own words for the job are "generate a surface per delivery: OpenAPI `QueryParameter`s,
 * `InputOption`s with the right mode, form fields with types and choices". The third clause is this
 * file. Until it existed, option B was a design with two thirds of its argument built, and
 * `docs/kanban/coverage.md` finding 2 said so.
 *
 * ## It renders a form and never receives one
 *
 * **Nothing here ever calls `handleRequest()` or `submit()`, and the return type is what enforces
 * it.** A {@see FormView} cannot be asked to bind anything; a `FormInterface` could, and a reviewer
 * would have to notice that nobody does.
 *
 * That is not fussiness, it is [ADR 004](docs/ADR/004_filter.md) rule 11. Parsing a query string is
 * {@see FilterSet::parse()}'s, it throws
 * {@see \App\Shared\Application\ReadModel\BadFilterInput}, and every delivery renders that one
 * failure as a 400 or as exit code 22. A Symfony form that validated its own fields would be a
 * second failure channel on the same values, answering with Symfony's constraint violations for the
 * subset it understood, which is the exact defect `DeclaredFiltersFactory`'s `constraints: []`
 * exists to prevent one delivery over. **So the form carries no constraints, and it is not that
 * nobody got round to adding them.**
 *
 * The same sentence explains `csrf_protection: false`. A token on a `GET` form would travel in the
 * query string of a URL whose whole purpose is to be copied and sent to somebody.
 *
 * ## What it takes from the declaration, and what it decides for itself
 *
 * A filter's prose is the declaration's and is used verbatim, exactly as the other two readers use
 * it: `Filter::$description` is the OpenAPI `description`, the `--help` line, and the hint under
 * the control. One sentence, written once, read by three deliveries, which is the whole of what
 * option B buys.
 *
 * The *window* is the opposite, and both siblings already say so. `FilterSetOptions` renames
 * `itemsPerPage` to `--items-per-page` "because a console option that was camelCase would look like
 * a mistake"; this one labels it "Per page" and gives it a `min` and a `max` the number input
 * enforces in the browser. **What a delivery calls the window, and what shape of control it is, is
 * that delivery's knowledge**, and ADR 004 puts it in the UI layer on purpose.
 *
 * ## A position is not a question, so `page` and `after` get no field
 *
 * {@see Window::parameters()} lists `page` and `itemsPerPage`, or `after` and `size`, and this
 * class deliberately skips the first of each pair. A filter bar is where a reader changes *what
 * they are asking*; a page number and a cursor are where they are in the answer to the question
 * they asked a moment ago. Carrying the page across a filter change would show "page 7" of a set
 * that may now hold three rows.
 *
 * The surface is not lost, it moves: a page number is drawn by
 * `templates/components/_pagination.html.twig` as numbered links, and a cursor by a "more" link.
 * **Drawing it twice would give a reader two controls for one value, and they would disagree the
 * first time one of them was touched.** Submitting the bar therefore starts again at page one,
 * which is what every filter bar on the web does and what a reader already expects.
 *
 * `total` is a field, and it is the opposite case: how hard to count *is* part of the question, it
 * is the one parameter in this project where the caller chooses what the request costs, and a
 * control is the most honest possible place to put that choice. Its placeholder names the default
 * rather than preselecting a mode, so an untouched bar asks for exactly what
 * `MemberDirectoryFilters` declared.
 *
 * ## Why a Twig extension rather than something a controller hands over
 *
 * Because there is no controller. [ADR 009](docs/ADR/009_one-url-two-representations.md) rule 4
 * deleted it: the directory page is the `text/html` representation of `GET /members`, rendered by
 * {@see \ApiPlatform\State\RenderingProcessor} from the state provider's own
 * return value, and that processor hands a template exactly three variables (`data`, `operation`,
 * `uri_variables`) under rule 12. A fourth, added so that a page could draw a filter bar, is the
 * failure rule 12 exists to name.
 *
 * So the template asks for the form itself, by naming the declaration, the way
 * `templates/membership/badge_model/collection.html.twig` already names an enum through Twig's own
 * `enum_cases()`. `BadgeExtension` is the precedent in this
 * tree for a one-function extension in a delivery namespace.
 *
 * It lives under `Ui/Web/` although ADR 009 rule 4 narrowed that directory to pages no resource
 * represents. A form type is not a page; the plan for `membership/06` closed that as decision 2 and
 * the design system's `guidelines/twig.md` names this exact path.
 */
final class FilterSetForm extends AbstractExtension
{
    /**
     * Optional, and the default is the whole reason it is written this way.
     *
     * {@see \ApiPlatform\State\RenderingProcessor} does the same thing for
     * Twig and copies the idea from `ApiPlatform\Symfony\Bundle\SwaggerUi\SwaggerUiProcessor`:
     * name the missing package in the failure, so it arrives as a sentence a developer can act on
     * rather than as a null dereference. **The difference is where it throws, and that difference
     * is measured rather than guessed.** A typed, non-optional `FormFactoryInterface` makes
     * `AutowirePass` refuse the whole container when `symfony/form` is absent:
     *
     * ```
     * Cannot autowire service "App\Ui\Web\Query\FilterSetForm": argument "$forms" of method
     * "__construct()" has type "Symfony\Component\Form\FormFactoryInterface" but this class was
     * not found.
     * ```
     *
     * which is every console command and every page in the tree down to report that one filter bar
     * has an unmet dependency. An optional argument is skipped by the same pass, so the failure
     * stays where the dependency is, and {@see self::view()} raises it.
     *
     * **Measured, and the order is not the one I expected.** With `symfony/form` absent from the
     * shared tree, `GET /members` read as `text/html` answers a 500 titled *Did you forget to run
     * "composer require symfony/form"? Unknown function "form_start" in
     * "components/_filter_bar.html.twig"*, and `self::view()` is never reached: Twig
     * resolves `form_start` when it compiles the partial, which is before any function in it runs,
     * and `FormExtension` is the package's own Twig extension so the bundle's hint fires first.
     * Every other page in the tree still answers, which is the property the optional argument was
     * written for: `/register` and `/login` were `200` in the same minute. **So the guard below is
     * the second line and not the first**, and it is still the right shape, because a template that
     * called `filter_form()` without `form_start` would reach it with nothing else to say.
     *
     * The processor can throw in its constructor because it is built once, at the edge of a request
     * that already chose a representation. A Twig extension is built whenever the environment
     * initialises, which is every rendered page in the application, so a constructor that threw
     * here would take down `/login` to complain about `/members`.
     */
    public function __construct(private readonly ?FormFactoryInterface $forms = null)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('filter_form', $this->view(...)),
        ];
    }

    /**
     * `{{ filter_form('App\\Membership\\Application\\ReadModel\\MemberDirectoryFilters', path, query) }}`.
     *
     * The declaration is named by class rather than discovered from the operation, and that is a
     * choice worth defending. `RenderingProcessor` does hand the template its `operation`, and the
     * `#[DeclaredFilters]` attribute is on the resource class behind it, so this could have read the
     * attribute and asked nobody. It would then be generating the form from the *API's* surface,
     * one step further from the declaration than its two siblings are, and the page would silently
     * follow a resource that stopped declaring filters. Naming the class keeps all three readers
     * the same distance from the same file, and keeps the dependency where a reader of the template
     * can see it.
     *
     * `$values` is the raw query string, `app.request.query.all`, and it is put back into the
     * controls unparsed. A value that will not parse still belongs in the field the reader typed it
     * into; `templates/components/_field.html.twig` says the same thing about a `422`, and
     * `templates/components/_notice.html.twig` is where ADR 004's 400 goes instead.
     *
     * @param class-string         $declaration a class with a static `declaration(): FilterSet`
     * @param string               $action      where the bar submits, which is the page it is on
     * @param array<string, mixed> $values      the current query string, as strings
     */
    public function view(string $declaration, string $action, array $values = []): FormView
    {
        if (null === $this->forms) {
            throw new \RuntimeException('A filter bar cannot be built since the Form component is not installed. Try running "composer require symfony/form".');
        }

        $set = self::declarationOf($declaration);

        $builder = $this->forms->createNamedBuilder(
            // The empty name is load-bearing. A named form prefixes every field, so the bar would
            // submit `filters[color]=red` and `GET /members` would see no `color` at all: the
            // declaration's own parameter names are the contract this page shares with the API and
            // with the console, and a form library's namespacing convention does not get to rename
            // them.
            '',
            FormType::class,
            self::dataFor($set, $values),
            [
                'action' => $action,
                'method' => 'GET',
                // Both false for the reason the class docblock argues at length: this form is
                // rendered and never received, so a token would be noise in a shareable URL and a
                // constraint would be a second answer to a question ADR 004 rule 11 already has
                // one answer for.
                'csrf_protection' => false,
                'required' => false,
            ],
        );

        foreach ($set->filters as $filter) {
            [$type, $options] = self::field($filter);

            $builder->add($filter->name, $type, [...$options, 'required' => false, 'help' => $filter->description]);
        }

        foreach ($set->window->parameters() as $name) {
            $field = self::windowField($name, $set->window);

            if (null === $field) {
                continue;
            }

            [$type, $options] = $field;

            $builder->add($name, $type, [...$options, 'required' => false]);
        }

        return $builder->getForm()->createView();
    }

    /**
     * Deliberately the same three lines as
     * {@see \ApiPlatform\Metadata\DeclaredFiltersFactory::declarationOf()},
     * minus the attribute read, and deliberately not extracted into something both call. A shared
     * helper would be the first piece of a `FilterSet` that knows how it is surfaced, which is the
     * coupling {@see FilterSet}'s own docblock refuses in as many words: "the arrow runs the other
     * way: each delivery reads this and builds its own surface".
     *
     * @param class-string $declaration
     */
    private static function declarationOf(string $declaration): FilterSet
    {
        if (!method_exists($declaration, 'declaration')) {
            throw new \LogicException(\sprintf('%s has no static declaration(): FilterSet, so there is nothing to build a filter bar from.', $declaration));
        }

        $set = $declaration::declaration();

        if (!$set instanceof FilterSet) {
            throw new \LogicException(\sprintf('%s::declaration() must return a FilterSet.', $declaration));
        }

        return $set;
    }

    /**
     * One arm per {@see FilterKind}, which is the same `match` the other two readers write and the
     * reason that enum is closed. Add a case there and all three of us fail to compile, which is
     * the point: a kind no delivery can draw is a kind no declaration should be able to name.
     *
     * The classes are the design system's: `.select` on a dropdown and `.input` on a text control,
     * so a generated field and a hand written one in
     * `templates/components/_field.html.twig` come out of `public/styles.css` looking the same. A
     * Symfony form theme would have been the other way to get there and would have put that markup
     * in a second home.
     *
     * @return array{class-string, array<string, mixed>}
     */
    private static function field(Filter $filter): array
    {
        return match ($filter->kind) {
            // `array_combine` because the label and the submitted value are the same string here:
            // `Filter::labels()` already decided what a case is called on the wire, pure enums
            // included, and a dropdown that showed anything else would be a second naming of the
            // same set.
            FilterKind::Enumeration => [ChoiceType::class, [
                'choices' => array_combine($filter->allowed, $filter->allowed),
                'placeholder' => 'Any',
                'attr' => ['class' => 'select'],
            ]],
            FilterKind::Text => [TextType::class, ['attr' => ['class' => 'input']]],
            FilterKind::Boolean => [CheckboxType::class, []],
            FilterKind::Date => [DateType::class, [
                'widget' => 'single_text',
                // A string in and a string out, because the only thing this control has to do is
                // put a value in the query string that `Filter::parse()` will read back.
                'input' => 'string',
                'attr' => ['class' => 'input'],
            ]],
        };
    }

    /**
     * The window, which is the half each delivery words for itself.
     *
     * `page` and `after` return null on purpose and the class docblock has the argument: a position
     * is not a question, and `templates/components/_pagination.html.twig` is where a page number is
     * drawn.
     *
     * @return array{class-string, array<string, mixed>}|null
     */
    private static function windowField(string $name, Window $window): ?array
    {
        return match ($name) {
            Window::PAGE, Window::AFTER => null,
            Window::ITEMS_PER_PAGE, Window::SIZE => [IntegerType::class, [
                'label' => 'Per page',
                // The ceiling the browser enforces is the ceiling `Window::parse()` clamps to, read
                // off the same object. It is a convenience and not the rule: a hand written URL
                // still gets clamped rather than refused, which is `Window::clamp()`'s own comment.
                'attr' => ['class' => 'input', 'min' => 1, 'max' => $window->maxSize],
                'help' => \sprintf('Up to %d. More than that is clamped, not refused.', $window->maxSize),
            ]],
            Window::TOTAL => [ChoiceType::class, [
                'label' => 'Count them',
                'choices' => self::modes(),
                // Never a preselected mode. The default belongs to the declaration, and a control
                // that preselected `exact` would be this delivery deciding what every request
                // costs, which is the decision `MemberDirectoryFilters` took when it wrote
                // `total: Total::None`.
                //
                // `total` is not nullsafe here and does not need to be, which is the comment
                // `DeclaredFiltersFactory::windowSchema()` already makes about the same line:
                // `Window::parameters()` only lists this name when the window offers the choice,
                // so reaching this arm with a null default is not a state that exists.
                'placeholder' => \sprintf('Default (%s)', $window->total->value),
                'attr' => ['class' => 'select'],
                'help' => 'Exact costs a count over the whole filtered set.',
            ]],
            default => null,
        };
    }

    /**
     * What to put back in the controls: the strings that are in the query string now, mapped onto
     * the fields that exist, and nothing else.
     *
     * **This is not a parse and must not become one.** It does not reject `color=beige`, it does
     * not fill in a default, and it does not care whether a value is in the enum; it hands the
     * string to the control and lets the reader see what they typed. The only conversions are the
     * two a form type cannot take a string for, and both are shape rather than meaning.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function dataFor(FilterSet $set, array $values): array
    {
        $data = [];

        foreach ($set->filters as $name => $filter) {
            $raw = $values[$name] ?? null;

            if (!\is_string($raw) || '' === $raw) {
                continue;
            }

            $data[$name] = FilterKind::Boolean === $filter->kind ? \in_array($raw, ['true', '1'], true) : $raw;
        }

        foreach ($set->window->parameters() as $name) {
            $raw = $values[$name] ?? null;

            // Only the window parameters that became a field. `page` and `after` are skipped here
            // for the same reason they are skipped in `windowField()`, and keeping the two loops in
            // step is why that method is the single place that decides.
            if (null === self::windowField($name, $set->window) || !\is_string($raw) || '' === $raw) {
                continue;
            }

            $data[$name] = \in_array($name, [Window::ITEMS_PER_PAGE, Window::SIZE], true) && is_numeric($raw)
                ? (int) $raw
                : $raw;
        }

        return $data;
    }

    /**
     * The counting modes as a dropdown's choices, which is {@see Total}'s `cases()` and nothing
     * else: the same list `DeclaredFiltersFactory` publishes as the parameter's `enum` and the same
     * one `Total::parse()` will read back.
     *
     * @return array<string, string>
     */
    private static function modes(): array
    {
        $values = array_map(static fn (Total $case): string => $case->value, Total::cases());

        return array_combine($values, $values);
    }
}
