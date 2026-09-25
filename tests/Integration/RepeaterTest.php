<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;
use Iniznet\Mahout\Fields\Exception\RepeaterTooLarge;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\LeafAddress;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\RepeaterItem;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * Repeaters: one scalar per leaf, at an address, on both targets. Scalar
 * items round-trip as lists, composite items as member-keyed records, a
 * nested repeater as a list inside its parent's record — and no envelope,
 * no JSON, anywhere in storage.
 *
 * @internal
 */
final class RepeaterTest extends TestCase
{
    public function testAMetaRepeaterRoundTripsAsLeafRows(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_credits', ObjectContext::Post, [
            new RepeaterField('fixture_credits_list', StorageTarget::Meta, new TextField('fixture_credit_item', StorageTarget::Carried)),
        ]));

        $this->writer->setItems('fixture_credits_list', ObjectRef::post($postId), ['Ursula K. Le Guin', 'Isaac Asimov', 'Octavia Butler']);

        $items = $this->reader->items('fixture_credits_list', ObjectRef::post($postId));

        self::assertSame(['Ursula K. Le Guin', 'Isaac Asimov', 'Octavia Butler'], $items);
    }

    public function testAMetaRepeaterStoresOneScalarRowPerLeaf(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_credits', ObjectContext::Post, [
            new RepeaterField('fixture_credits_list', StorageTarget::Meta, new TextField('fixture_credit_item', StorageTarget::Carried)),
        ]));

        $this->writer->setItems('fixture_credits_list', ObjectRef::post($postId), ['first', 'second']);

        // No envelope: the stored value under each address is the scalar
        // itself, and the address is the meta key.
        self::assertSame('first', \get_post_meta($postId, 'fixture_credits_list.0', true));
        self::assertSame('second', \get_post_meta($postId, 'fixture_credits_list.1', true));
    }

    public function testAnItemsTableRepeaterRoundTripsAtExplicitAddresses(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_chapters', ObjectContext::Post, [
            new RepeaterField('fixture_chapter_list', StorageTarget::Table, new TextField('fixture_chapter_item', StorageTarget::Carried)),
        ]));

        $this->writer->setItems('fixture_chapter_list', ObjectRef::post($postId), ['The Necklace', 'The Storm', 'The Gift']);

        $row0 = $this->rawLeaf('fixture_chapter_list', $postId, '0');
        $row1 = $this->rawLeaf('fixture_chapter_list', $postId, '1');
        $row2 = $this->rawLeaf('fixture_chapter_list', $postId, '2');

        self::assertNotNull($row0);
        self::assertSame('The Necklace', $row0['value_text']);
        self::assertNull($row0['value_int']);
        self::assertSame('The Storm', (string) $row1['value_text']);
        self::assertSame('The Gift', (string) $row2['value_text']);

        self::assertSame(['The Necklace', 'The Storm', 'The Gift'], $this->reader->items('fixture_chapter_list', ObjectRef::post($postId)));
    }

    public function testReplacingAnItemsTableRepeaterDropsTheOldAddresses(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_chapters', ObjectContext::Post, [
            new RepeaterField('fixture_chapter_list', StorageTarget::Table, new TextField('fixture_chapter_item', StorageTarget::Carried)),
        ]));

        $this->writer->setItems('fixture_chapter_list', ObjectRef::post($postId), ['a', 'b', 'c']);
        $this->writer->setItems('fixture_chapter_list', ObjectRef::post($postId), ['x']);

        // The replace is one transaction: the old addresses are gone, and no
        // stale row survives under an address the new write no longer fills.
        self::assertSame(['x'], $this->reader->items('fixture_chapter_list', ObjectRef::post($postId)));
        self::assertNull($this->rawLeaf('fixture_chapter_list', $postId, '1'));
        self::assertNull($this->rawLeaf('fixture_chapter_list', $postId, '2'));
    }

    public function testAnIntegerItemsTableRepeaterRoundTripsThroughTheIntColumn(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_ranks', ObjectContext::Post, [
            new RepeaterField('fixture_rank_list', StorageTarget::Table, new IntegerField('fixture_rank_item', StorageTarget::Carried)),
        ]));

        $this->writer->setItems('fixture_rank_list', ObjectRef::post($postId), [10, 20, 30]);

        self::assertSame([10, 20, 30], $this->reader->items('fixture_rank_list', ObjectRef::post($postId)));

        $row = $this->rawLeaf('fixture_rank_list', $postId, '1');
        self::assertNotNull($row);
        self::assertNull($row['value_text']);
        self::assertSame('20', $row['value_int']);
    }

    public function testACompositeRepeaterRoundTripsAsMemberKeyedRecords(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_roles', ObjectContext::Post, [
            new RepeaterField('fixture_role_list', StorageTarget::Table, new RepeaterItem([
                new TextField('fixture_role', StorageTarget::Carried),
                new TextField('fixture_name', StorageTarget::Carried),
            ])),
        ]));

        $this->writer->setItems('fixture_role_list', ObjectRef::post($postId), [
            ['fixture_role' => 'author', 'fixture_name' => 'Ursula K. Le Guin'],
            ['fixture_role' => 'editor', 'fixture_name' => 'Terry Carr'],
        ]);

        $items = $this->reader->items('fixture_role_list', ObjectRef::post($postId));

        self::assertSame([
            ['fixture_role' => 'author', 'fixture_name' => 'Ursula K. Le Guin'],
            ['fixture_role' => 'editor', 'fixture_name' => 'Terry Carr'],
        ], $items);

        // One row per leaf: the member is the row's own query aid.
        $row = $this->rawLeaf('fixture_role_list', $postId, '0.fixture_role');
        self::assertNotNull($row);
        self::assertSame('fixture_role', $row['member']);
        self::assertSame('author', $row['value_text']);
    }

    public function testANestedRepeaterRoundTripsThroughTheAddressChain(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_sections', ObjectContext::Post, [
            new RepeaterField('fixture_section_list', StorageTarget::Table, new RepeaterItem([
                new TextField('fixture_title', StorageTarget::Carried),
                new RepeaterField('fixture_blocks', StorageTarget::Carried, new TextField('fixture_block', StorageTarget::Carried)),
            ])),
        ]));

        $this->writer->setItems('fixture_section_list', ObjectRef::post($postId), [
            ['fixture_title' => 'Intro', 'fixture_blocks' => ['alpha', 'beta']],
            ['fixture_title' => 'Close', 'fixture_blocks' => ['gamma']],
        ]);

        $items = $this->reader->items('fixture_section_list', ObjectRef::post($postId));

        self::assertSame([
            ['fixture_title' => 'Intro', 'fixture_blocks' => ['alpha', 'beta']],
            ['fixture_title' => 'Close', 'fixture_blocks' => ['gamma']],
        ], $items);

        // The nested leaves are rows of the same table, ancestry in the address.
        self::assertSame('beta', $this->rawLeaf('fixture_section_list', $postId, '0.fixture_blocks.1')['value_text'] ?? null);
        self::assertSame('gamma', $this->rawLeaf('fixture_section_list', $postId, '1.fixture_blocks.0')['value_text'] ?? null);
    }

    public function testANestedMetaRepeaterStoresLeavesAsAddressedMetaKeys(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_sections', ObjectContext::Post, [
            new RepeaterField('fixture_section_list', StorageTarget::Meta, new RepeaterItem([
                new TextField('fixture_title', StorageTarget::Carried),
                new RepeaterField('fixture_blocks', StorageTarget::Carried, new TextField('fixture_block', StorageTarget::Carried)),
            ])),
        ]));

        $this->writer->setItems('fixture_section_list', ObjectRef::post($postId), [
            ['fixture_title' => 'Intro', 'fixture_blocks' => ['alpha']],
        ]);

        self::assertSame('Intro', \get_post_meta($postId, 'fixture_section_list.0.fixture_title', true));
        self::assertSame('alpha', \get_post_meta($postId, 'fixture_section_list.0.fixture_blocks.0', true));

        $items = $this->reader->items('fixture_section_list', ObjectRef::post($postId));

        self::assertSame([['fixture_title' => 'Intro', 'fixture_blocks' => ['alpha']]], $items);
    }

    public function testAnOptionRepeaterRoundTripsUnderTheOwnedPrefix(): void
    {
        $this->registry->register(new FieldGroup('fixture_site_tags', ObjectContext::Option, [
            new RepeaterField('fixture_site_tag_list', StorageTarget::Meta, new TextField('fixture_site_tag_item', StorageTarget::Carried)),
        ]));

        $object = ObjectRef::option();
        $this->writer->setItems('fixture_site_tag_list', $object, ['dawn', 'dusk']);

        // The leaves live under the package's option prefix — the same
        // prefix the enumeration reads — and no unprefixed orphan option is
        // created beside them.
        self::assertSame(['dawn', 'dusk'], $this->reader->items('fixture_site_tag_list', $object));
        self::assertSame('dawn', \get_option('mahout_fields/fixture_site_tag_list.0'));
        self::assertFalse(\get_option('fixture_site_tag_list.0', false), 'no unprefixed leaf option is written');

        // A second identical save is a no-op, not a refusal: core returns
        // false for an unchanged option and the adapter verifies before it
        // believes. A shrinking save deletes the dropped leaf by exact key.
        $this->writer->setItems('fixture_site_tag_list', $object, ['dawn', 'dusk']);
        self::assertSame(['dawn', 'dusk'], $this->reader->items('fixture_site_tag_list', $object));

        $this->writer->setItems('fixture_site_tag_list', $object, ['dawn']);
        self::assertSame(['dawn'], $this->reader->items('fixture_site_tag_list', $object));
        self::assertFalse(\get_option('mahout_fields/fixture_site_tag_list.1', false), 'the dropped leaf is deleted by exact key');

        \delete_option('mahout_fields/fixture_site_tag_list.0');
    }

    public function testAnAddressPastTheByteCapIsRefused(): void
    {
        $postId = $this->postId();
        $member = implode('', array_fill(0, 60, 'a'));
        $leaf = new TextField($member, StorageTarget::Carried);
        $this->registry->register(new FieldGroup('fixture_caps', ObjectContext::Post, [
            new RepeaterField('fixture_cap_list', StorageTarget::Table, new RepeaterItem([
                new RepeaterField($member.'1', StorageTarget::Carried, new RepeaterItem([
                    new RepeaterField($member.'2', StorageTarget::Carried, new RepeaterItem([$leaf])),
                ])),
            ])),
        ]));

        // The three-level address with 60-byte member ids crosses the cap the
        // meta_key column and the leaves table share.
        $this->expectException(InvalidFieldWrite::class);
        $this->writer->setItems('fixture_cap_list', ObjectRef::post($postId), [
            [$member.'1' => [[$member.'2' => ['x']]]],
        ]);
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

    public function testARepeaterOfRepeatersPastTheDepthCapIsRefusedAtDeclaration(): void
    {
        $leaf = new TextField('fixture_leaf', StorageTarget::Carried);
        $level4 = new RepeaterField('fixture_l4', StorageTarget::Carried, $leaf);
        $level3 = new RepeaterField('fixture_l3', StorageTarget::Carried, $level4);
        $level2 = new RepeaterField('fixture_l2', StorageTarget::Carried, $level3);

        $this->expectException(InvalidFieldDefinition::class);
        $this->registry->register(new FieldGroup('fixture_deep', ObjectContext::Post, [
            new RepeaterField('fixture_l1', StorageTarget::Table, $level2),
        ]));
    }

    public function testAMemberWithStorageOfItsOwnIsRefusedAtDeclaration(): void
    {
        $this->expectException(InvalidFieldDefinition::class);
        $this->registry->register(new FieldGroup('fixture_carried', ObjectContext::Post, [
            new RepeaterField('fixture_list', StorageTarget::Table, new TextField('fixture_item', StorageTarget::Meta)),
        ]));
    }

    public function testAQueriedRepeaterMayNotBindMeta(): void
    {
        $this->expectException(InvalidStorageCombination::class);
        $this->registry->register(new FieldGroup('fixture_queried', ObjectContext::Post, [
            new RepeaterField('fixture_queried_list', StorageTarget::Meta, new TextField('fixture_queried_item', StorageTarget::Carried), queried: true),
        ]));
    }

    public function testAQueriedRepeaterBindsTheLeavesTable(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('fixture_queried', ObjectContext::Post, [
            new RepeaterField('fixture_queried_list', StorageTarget::Table, new TextField('fixture_queried_item', StorageTarget::Carried), queried: true),
        ]));

        $this->writer->setItems('fixture_queried_list', ObjectRef::post($postId), ['one', 'two']);

        self::assertSame(['one', 'two'], $this->reader->items('fixture_queried_list', ObjectRef::post($postId)));
    }

    public function testTheAddressGrammarRejectsALeadingZeroPosition(): void
    {
        $this->expectException(InvalidFieldWrite::class);
        LeafAddress::of('fixture_list.01');
    }
}
