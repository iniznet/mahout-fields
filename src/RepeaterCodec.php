<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidRepeaterPayload;
use Iniznet\Mahout\Fields\Exception\RepeaterTooLarge;

/**
 * The repeater codec: the versioned JSON envelope {"v":1,"items":[...]}.
 *
 * Three properties are the payload rules, restated as this class's contract:
 *
 * 1. The payload is versioned — eight bytes that remove a whole category of
 *    painful migration later.
 * 2. It encodes the STORAGE SHAPE, never a DTO. The input is a list of scalars
 *    or a list of records of scalars; a consumer's object graph never reaches
 *    these bytes, so renaming a DTO property cannot change them.
 * 3. Every encode and decode carries JSON_THROW_ON_ERROR — a silent false
 *    written to the database is the worst possible outcome.
 *
 * A static pure codec holds no state and resolves no collaborator, which is
 * the corpus's own permitted category for static access.
 *
 * @phpstan-type ScalarItem  string|int|float|bool
 * @phpstan-type RecordItem  array<string, string|int|float|bool>
 * @phpstan-type RepeaterItems list<ScalarItem>|list<RecordItem>
 */
final readonly class RepeaterCodec
{
    public const int VERSION = 1;

    public const int MAX_BYTES = 65536;

    private const string ITEMS_KEY = 'items';

    private const string VERSION_KEY = 'v';

    /**
     * Encode the storage shape. The byte cap is checked here because the
     * encoded size is known at exactly this moment and nowhere else.
     *
     * @param RepeaterItems $items
     *
     * @throws RepeaterTooLarge when the encoded payload exceeds the cap
     */
    public static function encode(array $items): string
    {
        /** @var array{v: int, items: RepeaterItems} $payload */
        $payload = [self::VERSION_KEY => self::VERSION, self::ITEMS_KEY => $items];

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $bytes = strlen($encoded);

        if ($bytes > self::MAX_BYTES) {
            throw RepeaterTooLarge::payload($bytes, self::MAX_BYTES);
        }

        return $encoded;
    }

    /**
     * Decode the storage shape back to the storage shape. A stored payload
     * that is not the versioned envelope is broken data and is refused, never
     * silently substituted with an empty list.
     *
     * @return RepeaterItems
     *
     * @throws InvalidRepeaterPayload for any malformed, mis-versioned or wrongly-shaped payload
     */
    public static function decode(string $payload): array
    {
        $decoded = self::parse($payload);

        /** @var array<string, mixed> $decoded */
        $version = $decoded[self::VERSION_KEY] ?? null;

        if (!\is_int($version)) {
            throw InvalidRepeaterPayload::notAnEnvelope();
        }

        if (self::VERSION !== $version) {
            throw InvalidRepeaterPayload::unsupportedVersion($version);
        }

        return self::validatedItems($decoded[self::ITEMS_KEY] ?? null);
    }

    /**
     * Write-time validation for a payload a consumer's own codec produced.
     * The envelope must be the versioned shape; the version itself is a read
     * concern, because a codec may legitimately write a newer version than
     * this package knows how to read.
     *
     * @throws InvalidRepeaterPayload
     */
    public static function assertPayload(string $payload): void
    {
        $decoded = self::parse($payload);

        if (!\is_array($decoded) || !\is_int($decoded[self::VERSION_KEY] ?? null)) {
            throw InvalidRepeaterPayload::notAnEnvelope();
        }

        self::validatedItems($decoded[self::ITEMS_KEY] ?? null);
    }

    private static function parse(string $payload): mixed
    {
        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw InvalidRepeaterPayload::malformed($failure->getMessage());
        }
    }

    /**
     * @param mixed $items the decoded items member, unvalidated
     *
     * @return RepeaterItems
     */
    private static function validatedItems(mixed $items): array
    {
        if (!\is_array($items) || !\array_is_list($items)) {
            throw InvalidRepeaterPayload::notAnEnvelope();
        }

        $validated = [];
        $position = 0;
        foreach ($items as $item) {
            if (\is_scalar($item)) {
                $validated[] = $item;
                ++$position;

                continue;
            }

            if (\is_array($item)) {
                $record = self::recordOfScalars($item);

                if (null !== $record) {
                    $validated[] = $record;
                    ++$position;

                    continue;
                }
            }

            throw InvalidRepeaterPayload::malformedItem($position);
        }

        return $validated;
    }

    /**
     * A record item: every key a string, every value a scalar. Anything else
     * refuses the whole item rather than coercing part of it.
     *
     * @param array<mixed> $item
     *
     * @return RecordItem|null
     */
    private static function recordOfScalars(array $item): ?array
    {
        $record = [];
        foreach ($item as $key => $value) {
            if (!\is_string($key) || !\is_scalar($value)) {
                return null;
            }

            $record[$key] = $value;
        }

        /* @var RecordItem $record */
        return $record;
    }
}
