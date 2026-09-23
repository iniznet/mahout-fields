<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Exception\InvalidPanelDeclaration;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\FieldPanel;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * The panel value: a group paired with the post type whose edit screen renders
 * it. The pairing is the value's whole content, and an unpaired panel is not a
 * panel -- it would name a metabox on every screen or on none.
 *
 * @internal
 */
final class FieldPanelTest extends TestCase
{
    public function testThePanelCarriesItsScreenAndItsDeclaration(): void
    {
        $group = new FieldGroup('fixture_group', ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table),
        ]);

        $panel = new FieldPanel('post', $group);

        self::assertSame('post', $panel->postType);
        self::assertSame($group, $panel->group, 'the panel pairs, it does not copy');
    }

    public function testAPanelNamingNoScreenIsRefused(): void
    {
        $group = new FieldGroup('fixture_group', ObjectContext::Post, []);

        try {
            new FieldPanel('', $group);
            self::fail('a panel with no post type is not a declaration');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertSame('fixture_group', $refusal->groupId());
        }
    }
}
