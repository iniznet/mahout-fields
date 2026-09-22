<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Fixtures;

use Iniznet\Mahout\Fields\Contracts\FieldWriter;
use Iniznet\Mahout\Fields\ObjectRef;

/**
 * A FieldWriter that records the calls it receives and returns a fixed hash,
 * so a guard test proves which store step ran -- and which never did.
 */
final class RecordingWriter implements FieldWriter
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<array<string, string|int|float|bool|list<string|int|float|bool>|null>> */
    public array $written = [];

    #[\Override]
    public function set(string $fieldId, ObjectRef $object, string|int|float|bool|null $value): void
    {
        $this->calls[] = 'set:'.$fieldId;
    }

    #[\Override]
    public function setItems(string $fieldId, ObjectRef $object, array $items): void
    {
        $this->calls[] = 'setItems:'.$fieldId;
    }

    #[\Override]
    public function delete(string $fieldId, ObjectRef $object): void
    {
        $this->calls[] = 'delete:'.$fieldId;
    }

    #[\Override]
    public function writeGroup(string $groupId, ObjectRef $object, array $values, string $expectedHash): string
    {
        $this->calls[] = 'writeGroup:'.$groupId;
        $this->written[] = $values;

        return 'hash-after-write';
    }

    #[\Override]
    public function writeField(string $fieldId, ObjectRef $object, string|int|float|bool|array|null $value, string $expectedHash): string
    {
        $this->calls[] = 'writeField:'.$fieldId;

        return 'hash-after-field-write';
    }
}
