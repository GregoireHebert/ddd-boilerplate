<?php

declare(strict_types=1);

namespace ApiPlatform\JsonRpc;

/**
 * One declared parameter of one method.
 *
 * These four fields are OpenRPC's **Content Descriptor** (`name` and `schema` required,
 * `description` and `required` optional), which is not a coincidence: this class started life as
 * `array<string, string>`, a name mapped to a sentence, and that was a Content Descriptor with
 * three quarters of it missing. Making the missing part explicit is what let `rpc.discover` become
 * possible, and it improved the OpenAPI document on the way past, where `params` used to be
 * "an object, or an array" with nothing said about what was in it.
 *
 * ## The schema cites the domain
 *
 * ADR 005 locks it for option A's constraints and the same rule holds here: the arguments are
 * domain constants, never literals.
 *
 * ```php
 * new Param('color', 'The colour half of the pattern.', ['type' => 'string', 'enum' => Color::values()]);
 * ```
 *
 * `Color::values()` is the same callback `#[Assert\Choice(callback:)]` calls, so the palette is
 * published to the OpenAPI schema, the OpenRPC document and the REST constraint from one place. A
 * literal list here would be a fourth copy that nobody would notice going stale.
 *
 * ## It is not a validator
 *
 * Nothing reads {@see self::$schema} at request time and nothing ever should. It is published, and
 * the authority over what a parameter may be stays where ADR 005 put it, in the value object's
 * constructor, reached through {@see \App\Shared\Application\Validation\Violations}. A schema that
 * decided anything would be a second place to change a rule, and it would answer in a vocabulary
 * that has no `propertyPath` and no published code.
 *
 * `$required` is the same kind of declaration: it says the document should mark the parameter
 * required, not that anything enforces it. An absent parameter reaches the method as `''` and comes
 * back as the violation its own constructor raised.
 */
final readonly class Param
{
    /**
     * @param array<string, mixed> $schema JSON Schema, published and never evaluated
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $schema,
        public bool $required = true,
    ) {
    }

    /**
     * Every identifier this application accepts is the same shape (ADR 002: one ULID-backed `Id`
     * for every aggregate in every context), so it is described once here rather than four times
     * across the methods.
     *
     * The pattern is `Symfony\Component\Uid\Ulid::isValid()` transcribed: 26 characters of
     * Crockford base32 in either case, first character `0` to `7`. Transcribed is the weak word in
     * that sentence, and it is the honest one. This is published documentation and nothing
     * evaluates it, so a drift between the two costs a caller a misleading schema and never a wrong
     * answer; `Id::fromString()` remains the only thing that decides.
     */
    public static function identifier(string $name, string $description, bool $required = true): self
    {
        return new self($name, $description, [
            'type' => 'string',
            'pattern' => '^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$',
            'examples' => ['01M35G5RKN7ST0K16W80CJQ8KK'],
        ], $required);
    }

    /**
     * The positional order is the declaration order, so this is how {@see RpcCall::parameters()}
     * learns what §4.2's by-position form maps onto.
     *
     * @param list<self> $params
     *
     * @return list<string>
     */
    public static function names(array $params): array
    {
        return array_map(static fn (self $param): string => $param->name, $params);
    }

    /**
     * The object schema for a by-name `params` member, for the OpenAPI document. OpenRPC wants the
     * list itself and builds nothing.
     *
     * @param list<self> $params
     *
     * @return array<string, mixed>
     */
    public static function objectSchema(array $params): array
    {
        $properties = [];
        $required = [];

        foreach ($params as $param) {
            $properties[$param->name] = [...$param->schema, 'description' => $param->description];

            if ($param->required) {
                $required[] = $param->name;
            }
        }

        return [] === $required
            ? ['type' => 'object', 'properties' => $properties]
            : ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    /**
     * @return array{name: string, description: string, required: bool, schema: array<string, mixed>}
     */
    public function toContentDescriptor(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'required' => $this->required,
            'schema' => $this->schema,
        ];
    }
}
