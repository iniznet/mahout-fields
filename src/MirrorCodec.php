<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidMirrorPayload;

/**
 * The revision mirror codec: the versioned payload {"v":1,"schema":1,"hash":...,"rows":[...]}.
 *
 * Three properties are the payload rules, restated as this class's contract:
 *
 * 1. The payload is versioned, and so is its row shape -- the schema member
 *    says which one a stored payload carries.
 * 2. It encodes the STORAGE shape -- one row per Table-bound field, in group
 *    declaration order -- never a consumer's object graph.
 * 3. Every encode and decode carries JSON_THROW_ON_ERROR, and a stored
 *    payload that is not this shape is refused, never substituted.
 *
 * The hash is sha256 over the canonical JSON of the row set alone. It is the
 * reference the lost-update guard compares against, computed here and nowhere
 * else, exactly as the repeater's byte cap is known at its encode site.
 *
 * Encode trusts its caller's types -- the snapshot is package code, checked
 * statically. Decode distrusts everything: the payload came out of a table.
 *
 * A static pure codec holds no state and resolves no collaborator, which is
 * the corpus's own permitted category for static access.
 *
 * @phpstan-type RowValue   string|int|float|bool
 * @phpstan-type ScalarRow  array{field: string, value: RowValue}
 * @phpstan-type ItemRow    array{field: string, items: list<RowValue>}
 * @phpstan-type LeafRow    array{field: string, leaves: list<array{address: string, value: RowValue}>}
 * @phpstan-type MirrorRow  ScalarRow|ItemRow|LeafRow
 * @phpstan-type MirrorRows list<MirrorRow>
 * @phpstan-type Payload    array{hash: string, rows: MirrorRows}
 */
final readonly class MirrorCodec
{
    public const int VERSION = 1;

    public const int SCHEMA = 2;

    private const string ROWS_KEY = 'rows';

    private const string HASH_KEY = 'hash';

    private const string VERSION_KEY = 'v';

    private const string SCHEMA_KEY = 'schema';

    /**
     * Encode the payload. The hash is computed here, at the one moment the
     * row set is known, and carried beside the rows it hashes.
     *
     * @param MirrorRows $rows
     */
    public static function encode(array $rows): string
    {
        $rows = self::normalisedRows($rows);

        /** @var array{v: int, schema: int, hash: string, rows: MirrorRows} $payload */
        $payload = [
            self::VERSION_KEY => self::VERSION,
            self::SCHEMA_KEY => self::SCHEMA,
            self::HASH_KEY => self::hash($rows),
            self::ROWS_KEY => $rows,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode the payload back to its hash and rows. Anything malformed,
     * mis-versioned or wrongly shaped refuses the whole payload.
     *
     * @return Payload
     *
     * @throws InvalidMirrorPayload
     */
    public static function decode(string $groupId, string $payload): array
    {
        $decoded = self::parse($groupId, $payload);

        $version = $decoded[self::VERSION_KEY] ?? null;
        $hash = $decoded[self::HASH_KEY] ?? null;

        if (!\is_int($version) || !\is_string($hash)) {
            throw InvalidMirrorPayload::notAnEnvelope($groupId);
        }

        if (self::VERSION !== $version) {
            throw InvalidMirrorPayload::unsupportedVersion($groupId, $version);
        }

        return [
            self::HASH_KEY => $hash,
            self::ROWS_KEY => self::decodedRows($groupId, $decoded[self::ROWS_KEY] ?? null),
        ];
    }

    /**
     * The reference hash of a row set: sha256 over its canonical JSON. The
     * empty row set -- no Table-bound field holds a value -- has its own
     * hash, which is what a form carries before the first guarded save.
     *
     * @param MirrorRows $rows
     */
    public static function hash(array $rows): string
    {
        return hash('sha256', json_encode(self::normalisedRows($rows), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param MirrorRows $rows
     *
     * @return MirrorRows
     */
    private static function normalisedRows(array $rows): array
    {
        $normalised = [];
        foreach ($rows as $row) {
            $normalised[] = isset($row['leaves'])
                ? ['field' => $row['field'], 'leaves' => $row['leaves']]
                : (isset($row['items'])
                    ? ['field' => $row['field'], 'items' => $row['items']]
                    : ['field' => $row['field'], 'value' => $row['value']]);
        }

        return $normalised;
    }

    /**
     * @return array<mixed, mixed>
     *
     * @throws InvalidMirrorPayload
     */
    private static function parse(string $groupId, string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw InvalidMirrorPayload::malformed($groupId, $failure->getMessage());
        }

        if (!\is_array($decoded)) {
            throw InvalidMirrorPayload::notAnEnvelope($groupId);
        }

        return $decoded;
    }

    /**
     * @return MirrorRows
     *
     * @throws InvalidMirrorPayload
     */
    private static function decodedRows(string $groupId, mixed $rows): array
    {
        if (!\is_array($rows) || !\array_is_list($rows)) {
            throw InvalidMirrorPayload::notAnEnvelope($groupId);
        }

        $validated = [];
        $position = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw InvalidMirrorPayload::malformedRow($groupId, $position);
            }

            $validated[] = self::decodedRow($groupId, $row, $position);
            ++$position;
        }

        return $validated;
    }

    /**
     * @param array<mixed, mixed> $row
     *
     * @return MirrorRow
     *
     * @throws InvalidMirrorPayload
     */
    private static function decodedRow(string $groupId, array $row, int $position): array
    {
        $fieldId = $row['field'] ?? null;

        if (!\is_string($fieldId)) {
            throw InvalidMirrorPayload::malformedRow($groupId, $position);
        }

        $leaves = $row['leaves'] ?? null;

        if (\is_array($leaves) && \array_is_list($leaves)) {
            foreach ($leaves as $leaf) {
                if (!\is_array($leaf) || !\is_string($leaf['address'] ?? null) || !\is_scalar($leaf['value'] ?? null)) {
                    throw InvalidMirrorPayload::malformedRow($groupId, $position);
                }
            }

            /** @var LeafRow $validated */
            $validated = ['field' => $fieldId, 'leaves' => $leaves];

            return $validated;
        }

        $items = $row['items'] ?? null;

        if (\is_array($items) && \array_is_list($items)) {
            foreach ($items as $item) {
                if (!\is_scalar($item)) {
                    throw InvalidMirrorPayload::malformedRow($groupId, $position);
                }
            }

            /** @var ItemRow $validated */
            $validated = ['field' => $fieldId, 'items' => $items];

            return $validated;
        }

        $value = $row['value'] ?? null;

        if (!\is_scalar($value)) {
            throw InvalidMirrorPayload::malformedRow($groupId, $position);
        }

        return ['field' => $fieldId, 'value' => $value];
    }
}
