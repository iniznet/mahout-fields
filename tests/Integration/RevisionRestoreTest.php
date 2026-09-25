<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\Exception\InvalidMirrorPayload;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\Internal\RevisionMirror;
use Iniznet\Mahout\Fields\MirrorCodec;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * Deliverable 5: the restore rehydrator. Core restores the mirror key at
 * priority 10; the rehydrator rewrites the group's table rows from that
 * payload at 20. An absent row removes, a payload field the group no longer
 * declares refuses loudly, and no mirror means nothing to restore onto.
 *
 * Core's own meta restore is simulated by writing the captured payload back
 * under the mirror key -- the exact bytes core copies out of a revision.
 *
 * @internal
 */
final class RevisionRestoreTest extends TestCase
{
    public function testARestoreRewritesTheRowsTheMirrorCarries(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());
        $object = ObjectRef::post($postId);

        $revisionPayload = $this->seedRevision($object, ['fixture_text' => 'first', 'fixture_integer' => 1]);
        $this->writer->writeGroup('fixture_group', $object, ['fixture_text' => 'second', 'fixture_integer' => 2], $this->mirror->currentHash($object, 'fixture_group'));
        self::assertSame('second', $this->reader->value('fixture_text', $object));

        $this->simulateCoreMetaRestore($object, $revisionPayload);
        $this->restorer()->restore($postId);

        self::assertSame('first', $this->reader->value('fixture_text', $object));
        self::assertSame(1, $this->reader->value('fixture_integer', $object));
    }

    public function testAnAbsentRowInAPayloadRemovesTheStoredRow(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());
        $object = ObjectRef::post($postId);

        $revisionPayload = $this->seedRevision($object, ['fixture_text' => 'first']);

        $this->writer->writeGroup('fixture_group', $object, ['fixture_text' => 'second', 'fixture_integer' => 5], $this->mirror->currentHash($object, 'fixture_group'));
        self::assertSame(5, $this->reader->value('fixture_integer', $object));

        $this->simulateCoreMetaRestore($object, $revisionPayload);
        $this->restorer()->restore($postId);

        self::assertSame('first', $this->reader->value('fixture_text', $object));
        self::assertNull($this->reader->value('fixture_integer', $object), 'the payload without the row removes it');
        self::assertNull($this->rawValueRow('fixture_integer', $postId));
    }

    public function testAPayloadFieldTheGroupNoLongerDeclaresIsRefused(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());
        $object = ObjectRef::post($postId);

        $this->seedRevision($object, ['fixture_text' => 'first']);
        $this->writer->writeGroup('fixture_group', $object, ['fixture_text' => 'second'], $this->mirror->currentHash($object, 'fixture_group'));

        // The mirror is newer than the code: it carries a field the group
        // no longer declares.
        $renamed = MirrorCodec::encode([
            ['field' => 'fixture_text', 'value' => 'first'],
            ['field' => 'fixture_renamed_away', 'value' => 'x'],
        ]);
        $this->simulateCoreMetaRestore($object, $renamed);

        try {
            $this->restorer()->restore($postId);
            self::fail('an undeclared payload field must be refused, never skipped');
        } catch (InvalidMirrorPayload $refusal) {
            self::assertSame('fixture_group', $refusal->groupId());
            self::assertSame('unknown_field', $refusal->reason());
        }

        self::assertSame('second', $this->reader->value('fixture_text', $object), 'a refused restore wrote nothing');
    }

    public function testAMissingMirrorRemovesEveryRowOfTheGroup(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());
        $object = ObjectRef::post($postId);

        $this->writer->writeGroup('fixture_group', $object, ['fixture_text' => 'first', 'fixture_integer' => 1], MirrorCodec::hash([]));

        \delete_post_meta($postId, RevisionMirror::keyFor('fixture_group'));
        $this->restorer()->restore($postId);

        self::assertNull($this->reader->value('fixture_text', $object));
        self::assertNull($this->reader->value('fixture_integer', $object));
    }

    public function testARepeaterRowIsRestoredInPositionOrder(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->repeaterGroup());
        $object = ObjectRef::post($postId);

        $revisionPayload = $this->seedRevision($object, ['fixture_items' => ['paperback', 'hardcover']]);
        $this->writer->writeGroup('fixture_group', $object, ['fixture_items' => ['omnibus']], $this->mirror->currentHash($object, 'fixture_group'));

        $this->simulateCoreMetaRestore($object, $revisionPayload);
        $this->restorer()->restore($postId);

        self::assertSame(['paperback', 'hardcover'], $this->reader->items('fixture_items', $object));
    }

    /**
     * One seeded revision: the group's first guarded write, whose mirror
     * payload is what core would copy into the revision.
     *
     * @param array<string, string|int|float|bool|list<string|int|float|bool>> $values
     */
    private function seedRevision(ObjectRef $object, array $values): string
    {
        $this->writer->writeGroup('fixture_group', $object, $values, MirrorCodec::hash([]));

        $payload = $this->mirror->payloadOf($object, 'fixture_group');

        self::assertNotNull($payload, 'the guarded write must have seeded the mirror');

        return MirrorCodec::encode($payload['rows']);
    }

    /**
     * Core restored the revision: the mirror key now carries the revision's
     * payload bytes, exactly as register_meta's revisions_enabled copies it.
     */
    private function simulateCoreMetaRestore(ObjectRef $object, string $payload): void
    {
        \update_post_meta($object->id, RevisionMirror::keyFor('fixture_group'), $payload);
    }

    private function tableGroup(): FieldGroup
    {
        return new FieldGroup('fixture_group', ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table),
            new IntegerField('fixture_integer', StorageTarget::Table),
        ]);
    }

    private function repeaterGroup(): FieldGroup
    {
        return new FieldGroup('fixture_group', ObjectContext::Post, [
            new RepeaterField('fixture_items', StorageTarget::Table, new TextField('fixture_item', StorageTarget::Carried), 5),
        ]);
    }
}
