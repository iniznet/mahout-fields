<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Exception\InvalidPanelDeclaration;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\OptionScreen;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * One declared option screen: the value's invariants, including every
 * refusal. A screen pairs an option-context group with the settings page
 * that renders it, and a screen with no slug, no title, no capability, no
 * parent menu or a group whose context is not Option is not a screen -- it
 * would register a page that renders nothing or that addresses an object
 * the option context does not have.
 *
 * @internal
 */
final class OptionScreenTest extends TestCase
{
    private const string GROUP = 'fixture_group';

    public function testAScreenCarriesItsDeclaration(): void
    {
        $screen = new OptionScreen('fixture_screen', 'Fixture options', 'Fixture fields', $this->group(), 'fixture_capability', 'themes.php');

        self::assertSame('fixture_screen', $screen->pageSlug);
        self::assertSame('Fixture options', $screen->pageTitle);
        self::assertSame('Fixture fields', $screen->menuTitle);
        self::assertSame('fixture_capability', $screen->capability);
        self::assertSame('themes.php', $screen->menuParent);
        self::assertSame('fixture_group', $screen->group->id);
    }

    public function testAnEmptyDeclarationPieceIsRefused(): void
    {
        $group = $this->group();

        $empty = [
            'page slug' => static fn (): OptionScreen => new OptionScreen('', 'Fixture options', 'Fixture fields', $group, 'fixture_capability'),
            'page title' => static fn (): OptionScreen => new OptionScreen('fixture_screen', '', 'Fixture fields', $group, 'fixture_capability'),
            'menu title' => static fn (): OptionScreen => new OptionScreen('fixture_screen', 'Fixture options', '', $group, 'fixture_capability'),
            'capability' => static fn (): OptionScreen => new OptionScreen('fixture_screen', 'Fixture options', 'Fixture fields', $group, ''),
            'parent menu' => static fn (): OptionScreen => new OptionScreen('fixture_screen', 'Fixture options', 'Fixture fields', $group, 'fixture_capability', menuParent: ''),
        ];

        foreach ($empty as $what => $declare) {
            try {
                $declare();
                self::fail(sprintf('a screen with no %s is not a screen', $what));
            } catch (InvalidPanelDeclaration $refusal) {
                self::assertSame('fixture_group', $refusal->groupId(), $what);
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAScreenRefusesAGroupThatIsNotOptionContext(): void
    {
        $post = new FieldGroup('fixture_post', ObjectContext::Post, [
            new TextField('fixture_post_text', StorageTarget::Meta),
        ]);

        try {
            new OptionScreen('fixture_screen', 'Fixture options', 'Fixture fields', $post, 'fixture_capability');
            self::fail('an option screen serves the option context and nothing else');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertSame('fixture_post', $refusal->groupId());
            self::assertStringContainsString('post', $refusal->getMessage());
        }
    }

    // ------------------------------------------------------------------

    private function group(): FieldGroup
    {
        return new FieldGroup('fixture_group', ObjectContext::Option, [
            new TextField('fixture_text', StorageTarget::Meta),
        ]);
    }
}
