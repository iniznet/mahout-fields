<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\PersonalData;
use Iniznet\Mahout\Fields\PersonalDataEraser;
use Iniznet\Mahout\Fields\PersonalDataExporter;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * The privacy paths: a user-scoped field's declaration decides what an
 * export shows and what an erasure request touches. Each path walks its OWN
 * eligible fields, one per page -- an Erase field never appears in the
 * export, an Export field is never deleted -- so the pages are bounded
 * however many fields a site declares.
 *
 * @internal
 */
final class PersonalDataTest extends TestCase
{
    private const string EMAIL = 'export-fixture@example.invalid';

    public function testAnExportedValueAppearsUnderItsDeclaredLabel(): void
    {
        [$userId, $exporter] = $this->fixtures();
        $this->writer->set('fixture_public', ObjectRef::user($userId), 'public answer');

        $unknown = $exporter->export('nobody@example.invalid', 1);

        self::assertTrue($unknown['done'], 'an unknown email is done with no data');
        self::assertSame([], $unknown['data']);

        $page = $exporter->export(self::EMAIL, 1);

        self::assertSame('Public bio', $page['data'][0]['name'] ?? null, 'the label is the declaration\'s, never inferred from the data');
        self::assertSame('public answer', $page['data'][0]['value'] ?? null);
        self::assertSame('user-'.$userId, $page['data'][0]['item_id'] ?? null);
    }

    public function testTheExportWalksOnlyItsEligibleFields(): void
    {
        [$userId, $exporter] = $this->fixtures();
        $this->writer->set('fixture_public', ObjectRef::user($userId), 'one');
        $this->writer->set('fixture_notes', ObjectRef::user($userId), 'two');
        $this->writer->set('fixture_secret', ObjectRef::user($userId), 'never exported');

        $first = $exporter->export(self::EMAIL, 1);
        $second = $exporter->export(self::EMAIL, 2);
        $third = $exporter->export(self::EMAIL, 3);

        self::assertSame('Public bio', $first['data'][0]['name'] ?? null);
        self::assertFalse($first['done']);
        self::assertSame('Notes', $second['data'][0]['name'] ?? null, 'the erase-policy field is never in the export');
        self::assertTrue($third['done'], 'beyond the last export field the export is done');
    }

    public function testAnErasePolicyDeletesTheValueThroughTheWriter(): void
    {
        [$userId, , $eraser] = $this->fixtures();
        $this->writer->set('fixture_secret', ObjectRef::user($userId), 'hold this');

        $result = $eraser->erase(self::EMAIL, 1);

        self::assertTrue($result['items_removed']);
        self::assertNull($this->reader->value('fixture_secret', ObjectRef::user($userId)));
    }

    public function testAnAnonymizePolicyReplacesTheValueThroughCore(): void
    {
        [$userId, , $eraser] = $this->fixtures();
        $this->writer->set('fixture_anonymised', ObjectRef::user($userId), 'person@example.invalid');

        $result = $eraser->erase(self::EMAIL, 2);

        self::assertTrue($result['items_removed']);
        $value = $this->reader->value('fixture_anonymised', ObjectRef::user($userId));
        self::assertNotNull($value, 'an anonymised value is replaced, not deleted');
        self::assertNotSame('person@example.invalid', $value, 'the original value never survives an anonymise');
    }

    public function testARetainPolicyReportsAndWritesNothing(): void
    {
        [$userId, , $eraser] = $this->fixtures();
        $this->writer->set('fixture_retained', ObjectRef::user($userId), 'audit record');

        $result = $eraser->erase(self::EMAIL, 3);

        self::assertTrue($result['items_retained']);
        self::assertFalse($result['items_removed']);
        self::assertCount(1, $result['messages']);
        self::assertStringContainsString('fixture_retained', $result['messages'][0]);
        self::assertSame('audit record', $this->reader->value('fixture_retained', ObjectRef::user($userId)));
    }

    public function testAnErasureWalksOneFieldPerPageUntilDone(): void
    {
        [, , $eraser] = $this->fixtures();

        self::assertFalse($eraser->erase(self::EMAIL, 1)['done']);
        self::assertTrue($eraser->erase(self::EMAIL, 4)['done'], 'beyond the last eligible field the erasure is done');
    }

    // ------------------------------------------------------------------

    /** @return array{0: int, 1: PersonalDataExporter, 2: PersonalDataEraser} */
    private function fixtures(): array
    {
        $userId = (int) self::factory()->user->create([
            'user_email' => self::EMAIL,
            'role' => 'subscriber',
        ]);

        $this->registry->register(new FieldGroup('fixture_user_group', ObjectContext::User, [
            new TextField('fixture_public', StorageTarget::Meta, PersonalData::export('Public bio')),
            new TextField('fixture_notes', StorageTarget::Meta, PersonalData::export('Notes')),
            new TextField('fixture_secret', StorageTarget::Meta, PersonalData::erase()),
            new TextField('fixture_anonymised', StorageTarget::Meta, PersonalData::anonymize('email')),
            new TextField('fixture_retained', StorageTarget::Meta, PersonalData::retain('retained for audit')),
        ]));

        return [$userId, new PersonalDataExporter($this->registry, $this->reader), new PersonalDataEraser($this->registry, $this->reader, $this->writer)];
    }
}
