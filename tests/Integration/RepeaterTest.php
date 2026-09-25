<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;
use Iniznet\Mahout\Fields\Exception\RepeaterTooLarge;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterCodec;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * Deliverable 5: repeaters. The versioned JSON payload round-trips on the
 * Meta target; the items table round-trips at explicit positions; both size
 * caps throw.
 *
 * @internal
 */
final class RepeaterTest extends TestCase
{
    public function testAJsonRepeaterRoundTripsTheVersionedPayload(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_credits', ObjectContext::Post, [
            new RepeaterField('fixture_credits_list', StorageTarget::Meta, new TextField('fixture_credit_item', StorageTarget::Carried)),
        ]));

        $this->writer->setItems('fixture_credits_list', ObjectRef::post($postId), ['Ursula K. Le Guin', 'Isaac Asimov', 'Octavia Butler']);

        $items = $this->reader->items('fixture_credits_list', ObjectRef::post($postId));

        self::assertSame(['Ursula K. Le Guin', 'Isaac Asimov', 'Octavia Butler'], $items);
    }

    public function testTheStoredJsonRepeaterPayloadCarriesTheVersionEnvelope(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_credits', ObjectContext::Post, [
            new RepeaterField('fixture_credits_list', StorageTarget::Meta, new TextField('fixture_credit_item', StorageTarget::Carried)),
        ]));

        $this->writer->setItems('fixture_credits_list', ObjectRef::post($postId), ['first', 'second']);

        $raw = \get_post_meta($postId, 'fixture_credits_list', true);

        self::assertSame(['v' => 1, 'items' => ['first', 'second']], json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testAnItemsTableRepeaterRoundTripsAtExplicitPositions(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_chapters', ObjectContext::Post, [
            new RepeaterField('fixture_chapter_list', StorageTarget::Table, new TextField('fixture_chapter_item', StorageTarget::Carried)),
        ]));

        $this->writer->setItems('fixture_chapter_list', ObjectRef::post($postId), ['The Necklace', 'The Storm', 'The Gift']);

        $row0 = $this->rawItemRow('fixture_chapter_list', $postId, 0);
        $row1 = $this->rawItemRow('fixture_chapter_list', $postId, 1);
        $row2 = $this->rawItemRow('fixture_chapter_list', $postId, 2);

        self::assertNotNull($row0);
        self::assertSame('The Necklace', $row0['value_text']);
        self::assertNull($row0['value_int']);
        self::assertSame('The Storm', (string) $row1['value_text']);
        self::assertSame('The Gift', (string) $row2['value_text']);

        self::assertSame(['The Necklace', 'The Storm', 'The Gift'], $this->reader->items('fixture_chapter_list', ObjectRef::post($postId)));
    }

    public function testReplacingAnItemsTableRepeaterDropsTheOldPositions(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_chapters', ObjectContext::Post, [
            new RepeaterField('fixture_chapter_list', StorageTarget::Table, new TextField('fixture_chapter_item', StorageTarget::Carried)),
        ]));

        $this->writer->setItems('fixture_chapter_list', ObjectRef::post($postId), ['a', 'b', 'c']);
        $this->writer->setItems('fixture_chapter_list', ObjectRef::post($postId), ['x']);

        // The replace is one transaction: the old positions are gone, and no
        // stale row survives under a position the new write no longer fills.
        self::assertSame(['x'], $this->reader->items('fixture_chapter_list', ObjectRef::post($postId)));
        self::assertNull($this->rawItemRow('fixture_chapter_list', $postId, 1));
        self::assertNull($this->rawItemRow('fixture_chapter_list', $postId, 2));
    }

    public function testAnIntegerItemsTableRepeaterRoundTripsThroughTheIntColumn(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_ranks', ObjectContext::Post, [
            new RepeaterField('fixture_rank_list', StorageTarget::Table, new IntegerField('fixture_rank_item', StorageTarget::Carried)),
        ]));

        $this->writer->setItems('fixture_rank_list', ObjectRef::post($postId), [10, 20, 30]);

        self::assertSame([10, 20, 30], $this->reader->items('fixture_rank_list', ObjectRef::post($postId)));

        $row = $this->rawItemRow('fixture_rank_list', $postId, 1);
        self::assertNotNull($row);
        self::assertNull($row['value_text']);
        self::assertSame('20', $row['value_int']);
    }

    public function testAPayloadOverTheByteCapThrowsRepeaterTooLarge(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_credits', ObjectContext::Post, [
            new RepeaterField('fixture_credits_list', StorageTarget::Meta, new TextField('fixture_credit_item', StorageTarget::Carried)),
        ]));

        $oversized = str_repeat('a', RepeaterCodec::MAX_BYTES);

        $this->expectException(RepeaterTooLarge::class);
        $this->writer->setItems('fixture_credits_list', ObjectRef::post($postId), [$oversized]);
    }

    public function testAWritePastTheDeclaredItemCapThrowsRepeaterTooLarge(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_credits', ObjectContext::Post, [
            new RepeaterField('fixture_credits_list', StorageTarget::Meta, new TextField('fixture_credit_item', StorageTarget::Carried), expectedMaxItems: 2),
        ]));

        $this->expectException(RepeaterTooLarge::class);
        $this->writer->setItems('fixture_credits_list', ObjectRef::post($postId), ['a', 'b', 'c']);
    }

    public function testAQueriedRepeaterMayNotBindJson(): void
    {
        $this->expectException(InvalidStorageCombination::class);
        $this->registry->register(new FieldGroup('fixture_queried', ObjectContext::Post, [
            new RepeaterField('fixture_queried_list', StorageTarget::Meta, new TextField('fixture_queried_item', StorageTarget::Carried), queried: true),
        ]));
    }

    public function testAQueriedRepeaterBindsTheItemsTable(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_queried', ObjectContext::Post, [
            new RepeaterField('fixture_queried_list', StorageTarget::Table, new TextField('fixture_queried_item', StorageTarget::Carried), queried: true),
        ]));

        $this->writer->setItems('fixture_queried_list', ObjectRef::post($postId), ['one', 'two']);

        self::assertSame(['one', 'two'], $this->reader->items('fixture_queried_list', ObjectRef::post($postId)));
    }
}
