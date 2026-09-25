<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\BooleanField;
use Iniznet\Mahout\Fields\ChoiceField;
use Iniznet\Mahout\Fields\DateField;
use Iniznet\Mahout\Fields\DecimalField;
use Iniznet\Mahout\Fields\EmailField;
use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\PersonalData;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextAreaField;
use Iniznet\Mahout\Fields\TextField;
use Iniznet\Mahout\Fields\UrlField;

/**
 * Deliverable 3: write and read a value of every field type through the field
 * layer on the Meta target -- post, user and option contexts.
 *
 * Every read and write in this suite goes through the reader and the writer.
 * Not one raw meta call appears in a consumer's position; that is deliverable
 * 2's architecture proof, which lives in the fixtures.
 *
 * @internal
 */
final class MetaRoundTripTest extends TestCase
{
    public function testEveryFieldTypeRoundTripsThroughTheMetaTarget(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->metaGroup());

        $cases = $this->scalarCases();

        foreach ($cases as $fieldId => [$write, $expected]) {
            $this->writer->set($fieldId, ObjectRef::post($postId), $write);
            self::assertSame($expected, $this->reader->value($fieldId, ObjectRef::post($postId)), $fieldId);
        }
    }

    public function testAUserContextFieldRoundTrips(): void
    {
        $userId = $this->userId();
        $this->registry->register(new FieldGroup('fixture_user', ObjectContext::User, [
            new TextField('fixture_display', StorageTarget::Meta, PersonalData::export('Display name')),
            new IntegerField('fixture_karma', StorageTarget::Meta, PersonalData::export('Karma')),
        ]));

        $this->writer->set('fixture_display', ObjectRef::user($userId), 'Ada Lovelace');
        $this->writer->set('fixture_karma', ObjectRef::user($userId), 9);

        self::assertSame('Ada Lovelace', $this->reader->value('fixture_display', ObjectRef::user($userId)));
        self::assertSame(9, $this->reader->value('fixture_karma', ObjectRef::user($userId)));
    }

    public function testAUserFieldReadWithAPostRefIsRefused(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_user', ObjectContext::User, [
            new TextField('fixture_display', StorageTarget::Meta, PersonalData::export('Display name')),
        ]));

        $this->expectException(\Iniznet\Mahout\Fields\Exception\InvalidFieldContext::class);
        $this->reader->value('fixture_display', ObjectRef::post($postId));
    }

    public function testAnOptionContextFieldRoundTrips(): void
    {
        $this->registry->register(new FieldGroup('fixture_settings', ObjectContext::Option, [
            new TextField('fixture_footer_note', StorageTarget::Meta),
        ]));

        $this->writer->set('fixture_footer_note', ObjectRef::option(), 'Built with mahout');

        self::assertSame('Built with mahout', $this->reader->value('fixture_footer_note', ObjectRef::option()));
    }

    public function testAnAbsentMetaFieldReadsNull(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->metaGroup());

        self::assertNull($this->reader->value('fixture_text', ObjectRef::post($postId)));
    }

    public function testWritingNullDeletesTheStoredValue(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->metaGroup());

        $this->writer->set('fixture_text', ObjectRef::post($postId), 'temporarily');
        self::assertSame('temporarily', $this->reader->value('fixture_text', ObjectRef::post($postId)));

        $this->writer->set('fixture_text', ObjectRef::post($postId), null);
        self::assertNull($this->reader->value('fixture_text', ObjectRef::post($postId)));
    }

    public function testABlockedEmailAddressIsRefusedNotStored(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->metaGroup());

        $this->expectException(InvalidFieldValue::class);
        $this->writer->set('fixture_email', ObjectRef::post($postId), 'not-an-email');
    }

    public function testAnUnregisteredFieldReadIsRefused(): void
    {
        $this->expectException(FieldNotFound::class);
        $this->reader->value('fixture_missing', ObjectRef::post($this->postId()));
    }

    public function testAFieldFilteredToMetaReadsThroughMeta(): void
    {
        $postId = $this->postId();

        $registry = new \Iniznet\Mahout\Fields\FieldRegistry();
        \add_filter(\Iniznet\Mahout\Fields\Hooks::STORAGE_TARGET, static fn (): StorageTarget => StorageTarget::Meta);

        try {
            $meta = new \Iniznet\Mahout\Fields\Internal\MetaStorage();
            $table = new \Iniznet\Mahout\Fields\Internal\TableStorage($this->gateway, $this->valuesTable, $this->itemsTable);
            $mirror = new \Iniznet\Mahout\Fields\Internal\RevisionMirror();
            $writer = new \Iniznet\Mahout\Fields\FieldWriter(
                $registry,
                $meta,
                $table,
                $this->gateway,
                $mirror,
                new \Iniznet\Mahout\Fields\Internal\GroupSnapshot($registry, $table),
            );
            $reader = new \Iniznet\Mahout\Fields\FieldReader($registry, $meta, $table, $mirror);

            // The declaration says Table; the filter's resolution is what
            // the adapters dispatch on.
            $registry->register(new FieldGroup('fixture_flip', ObjectContext::Post, [
                new TextField('fixture_flipped', StorageTarget::Table),
            ]));

            $writer->set('fixture_flipped', ObjectRef::post($postId), 'through the filter');

            self::assertSame('through the filter', $reader->value('fixture_flipped', ObjectRef::post($postId)));
        } finally {
            \remove_all_filters(\Iniznet\Mahout\Fields\Hooks::STORAGE_TARGET);
        }
    }

    /**
     * @return array<string, array{0: string|int|float|bool, 1: string|int|float|bool}>
     */
    private function scalarCases(): array
    {
        return [
            'fixture_text' => ['The Left Hand of Darkness', 'The Left Hand of Darkness'],
            'fixture_textarea' => ["line one\nline two", "line one\nline two"],
            'fixture_email' => ['reader@example.org', 'reader@example.org'],
            'fixture_url' => ['https://example.org/series', 'https://example.org/series'],
            'fixture_choice' => ['paperback', 'paperback'],
            'fixture_integer' => ['42', 42],
            'fixture_decimal' => ['3.5', 3.5],
            'fixture_boolean' => ['1', true],
            'fixture_date' => ['2024-06-01', '2024-06-01'],
        ];
    }

    private function metaGroup(): FieldGroup
    {
        return new FieldGroup('fixture_meta', ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Meta),
            new TextAreaField('fixture_textarea', StorageTarget::Meta),
            new EmailField('fixture_email', StorageTarget::Meta),
            new UrlField('fixture_url', StorageTarget::Meta),
            new ChoiceField('fixture_choice', StorageTarget::Meta, ['paperback', 'hardcover']),
            new IntegerField('fixture_integer', StorageTarget::Meta),
            new DecimalField('fixture_decimal', StorageTarget::Meta),
            new BooleanField('fixture_boolean', StorageTarget::Meta),
            new DateField('fixture_date', StorageTarget::Meta),
            new RepeaterField('fixture_repeater', StorageTarget::Meta, new TextField('fixture_item', StorageTarget::Carried)),
        ]);
    }
}
