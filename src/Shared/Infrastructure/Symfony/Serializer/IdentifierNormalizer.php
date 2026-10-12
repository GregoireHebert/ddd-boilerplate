<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Symfony\Serializer;

use App\Shared\Domain\Models\Id;
use Ecotone\Messaging\Attribute\Converter;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class IdentifierNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * `Id<mixed>` rather than `Id`: the phantom type is irrelevant to a normalizer, which sees a
     * ULID and writes a string, and leaving it unspecified is a level 6 error.
     *
     * @param Id<mixed> $data
     */
    #[\Override]
    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        return $data->toString();
    }

    #[\Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Id;
    }

    /**
     * @param string $data
     *
     * @return Id<mixed>
     */
    #[\Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Id
    {
        if (!Id::isValid($data)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(message: 'Invalid identifier.', useMessageForUser: true, data: $data, expectedTypes: ['uuid'], path: $context['deserialization_path'] ?? null);
        }

        return Id::fromString($data);
    }

    #[\Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_a($type, Id::class, true);
    }

    #[\Override]
    public function getSupportedTypes(?string $format): array
    {
        return [
            Id::class => true,
        ];
    }
}
