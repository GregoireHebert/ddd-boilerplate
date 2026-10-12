<?php

declare(strict_types=1);

namespace ApiPlatform\State;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Location` on a `201`, when the thing that was created is not the thing that was posted.
 *
 * ADR 009's "Registration is also where the password is set" asks for one sentence of behaviour:
 * **the `201` names the member, not the registration**, so `Location` is `/members/{memberId}`,
 * which `MemberResource`'s `Get` answers and `public/form.js` follows. What it does not say is how,
 * and there is more than one mechanism. This class is the choice and the paragraph that owes it.
 *
 * ## What went wrong without it
 *
 * `ApiPlatform\State\Util\HttpResponseHeadersTrait` builds `Location` from whatever the state
 * processor returned, by asking the IRI converter for that object's own URL. A
 * `MemberRegistrationResource` has no
 * URL, because it declares one `Post` and no item operation, so
 * `ApiPlatform\Symfony\Routing\IriConverter::getIriFromResource()` falls through
 * `OperationNotFoundException` into `generateSkolemIri()` and the header reads
 * `/.well-known/genid/{id}`. That route is `api_platform.action.not_exposed`, which answers `404`,
 * so every successful registration sent the reader to a dead page and the script followed it there.
 *
 * ## Why a decorator, and what the alternatives cost
 *
 * Four mechanisms exist and three of them are worse.
 *
 * **`output: MemberResource`** is the vendor's own answer to "the write's class is not the read's",
 * and CLAUDE.md forbids it: `input:` and `output:` are not used in this tree, and "every write is
 * its own resource" is the rule that replaced them.
 *
 * **Returning a `MemberResource` from the processor** is that same thing done by hand, minus the
 * declaration. It also reverses a decision the registration resource's docblock already took ("one
 * class, one schema, one name in the document"), makes the `201` body disagree with the `201` schema
 * in a generated document CLAUDE.md calls a deliverable, and would need the processor to read the
 * member back to fill in `patternLabel` and `status`.
 *
 * **`itemUriTemplate:` on the `Post`** is a real vendor option and the closest near-miss. It is read
 * by `ApiPlatform\Serializer\SerializerContextBuilder`, so it rewrites the **body**: `@id` becomes
 * `/members/{memberId}`, `@type` becomes `Member` and `@context` becomes `/contexts/Member`. That is
 * the skolem `@id` that [`membership/01`](../../../../../../docs/kanban/membership/01_register-a-member.md)
 * argues is correct rather than a gap, replaced by a lie about which schema the body follows. And it
 * does not fix `Location` anyway, because the header is computed with no serializer context.
 *
 * So the least machinery that answers the actual question is one decorator, and the declaration it
 * reads is one extra property on the operation. No new attribute class, no metadata factory, and no
 * second class anywhere that knows a registration creates a member.
 *
 * ## Where it sits in the chain
 *
 * `api_platform.state_processor.main` is decorated by `SerializeProcessor` (200) and `WriteProcessor`
 * (100), and a **lower** decoration priority is the outer wrapper;
 * {@see RenderingProcessor} sits between them at 150. Priority 120 puts this **inside the write and
 * outside the response**, which is the only place it can be:
 *
 * - inside the write, so `$data` is what the state processor returned and carries the identifier the
 *   domain minted;
 * - outside the response, so the inner chain has already produced the `Response` whose header is
 *   wrong and there is something to correct.
 *
 * ## It does not swallow a failure
 *
 * The vendor catches every exception the IRI converter can raise and quietly answers with no
 * `Location` at all. This one does not, and the reason is that the declaration is static: a
 * `uriTemplate` that resolves once resolves every time, so a failure here means somebody wrote a
 * template no route matches, and the readable moment for that is the first request rather than a
 * silent return to the skolem this class exists to remove.
 *
 * `Content-Location` is deliberately left as the vendor set it. It names the location of *this*
 * representation, and a registration genuinely has none; `Location` names the thing that now exists,
 * and that is the one the card and the script care about.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
#[AsDecorator(decorates: 'api_platform.state_processor.main', priority: 120)]
final readonly class CreatedLocationProcessor implements ProcessorInterface
{
    /**
     * The operation's declaration: which operation answers for the thing this write created. The
     * extra property is ours rather than the vendor's, so it is spelled without the `_api_` prefix
     * the vendor reserves for its own.
     *
     * **Its value is an operation name and not a `uriTemplate`, and that is not a style choice.**
     * `ApiPlatform\Metadata\Operation\Factory\OperationMetadataFactory::create()` matches
     * `getUriTemplate() === $value || getName() === $value` and returns the first hit in resource
     * declaration order. `/members/{memberId}` is declared by three resources in this tree, so
     * asking for the template answered with `MemberErasureResource`'s
     * `DELETE` and a registration `201` died with "Unable to generate an IRI". A name is unique by
     * construction, because it is the Symfony route name; `bin/console debug:router` prints it.
     */
    public const string OPERATION = 'created_item_operation';

    /**
     * @param ProcessorInterface<mixed, mixed> $inner
     */
    public function __construct(
        private ProcessorInterface $inner,
        private IriConverterInterface $iriConverter,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $response = $this->inner->process($data, $operation, $uriVariables, $context);

        $created = $operation->getExtraProperties()[self::OPERATION] ?? null;

        if (
            !$response instanceof Response
            || !\is_string($created)
            || !\is_object($data)
            || Response::HTTP_CREATED !== $response->getStatusCode()
        ) {
            return $response;
        }

        // `item_uri_template` is the IRI converter's own context key for "this object is represented
        // by that operation". It makes two things happen at once, both in the vendor: the operation
        // is resolved by `OperationMetadataFactory`, and `IdentifiersExtractor::getIdentifierValue()`
        // is allowed to read the uri variable off an object of a different class, which is exactly
        // the registration-to-member step. Nothing here builds a path out of strings.
        $response->headers->set(
            'Location',
            $this->iriConverter->getIriFromResource(
                $data,
                UrlGeneratorInterface::ABS_PATH,
                null,
                ['item_uri_template' => $created],
            ),
        );

        return $response;
    }
}
