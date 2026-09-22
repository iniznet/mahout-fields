<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Admin\Control\RepeaterControl;
use Iniznet\Mahout\Fields\Admin\Control\TextControl;
use Iniznet\Mahout\Fields\Admin\FieldTypeRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldControl;
use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidFilterResult;
use Iniznet\Mahout\Fields\FieldType;
use Iniznet\Mahout\Fields\Hooks;
use Iniznet\Mahout\Fields\Tests\TestCase;

/**
 * The editor registry: every built-in type resolves to a control; a filter
 * result is a trust boundary -- a non-array, a non-control value or a dropped
 * built-in is refused loudly, never coerced and never silently missing.
 *
 * @internal
 */
final class FieldTypeRegistryTest extends TestCase
{
    public function testEveryBuiltInTypeResolvesToAControl(): void
    {
        $registry = new FieldTypeRegistry();

        foreach (FieldType::cases() as $type) {
            self::assertInstanceOf(FieldControl::class, $registry->control($type), $type->value);
        }
    }

    public function testTheRepeaterTypeResolvesToItsOwnControl(): void
    {
        self::assertInstanceOf(RepeaterControl::class, (new FieldTypeRegistry())->control(FieldType::Repeater));
        self::assertInstanceOf(TextControl::class, (new FieldTypeRegistry())->control(FieldType::Text));
    }

    public function testADroppedBuiltInIsRefusedAtLookup(): void
    {
        \add_filter(Hooks::EDITOR_CONTROLS, static fn (array $controls): array => array_filter(
            $controls,
            static fn (FieldControl $c): bool => !$c instanceof TextControl,
        ));

        try {
            (new FieldTypeRegistry())->control(FieldType::Text);
            self::fail('a dropped built-in type must refuse loudly, not render nothing');
        } catch (InvalidFieldDefinition $refusal) {
            self::assertSame('text', $refusal->fieldId());
        } finally {
            \remove_all_filters(Hooks::EDITOR_CONTROLS);
        }
    }

    public function testAFilterMayReplaceAControl(): void
    {
        $replacement = new class implements FieldControl {
            #[\Override]
            public function render(\Iniznet\Mahout\Fields\Admin\FieldControlProps $props): string
            {
                return '<custom/>';
            }
        };

        \add_filter(Hooks::EDITOR_CONTROLS, static function (array $controls) use ($replacement): array {
            $controls[FieldType::Text->value] = $replacement;

            return $controls;
        });

        $registry = new FieldTypeRegistry();

        self::assertSame($replacement, $registry->control(FieldType::Text), 'the filter is the host\'s seam to replace a control');
        self::assertNotSame($replacement, $registry->control(FieldType::Email), 'untouched types keep their built-in control');
    }

    public function testANonArrayFilterResultIsRefused(): void
    {
        \add_filter(Hooks::EDITOR_CONTROLS, static fn (mixed $controls): string => 'not-a-map');

        try {
            new FieldTypeRegistry();
            self::fail('a non-array filter result must be refused');
        } catch (InvalidFilterResult $refusal) {
            self::assertSame(Hooks::EDITOR_CONTROLS, $refusal->hook());
        } finally {
            \remove_all_filters(Hooks::EDITOR_CONTROLS);
        }
    }

    public function testANonControlFilterValueIsRefused(): void
    {
        \add_filter(Hooks::EDITOR_CONTROLS, static fn (array $controls): array => [...$controls, 'TextControl' => 'not-a-control']);

        try {
            new FieldTypeRegistry();
            self::fail('a non-control filter value must be refused');
        } catch (InvalidFilterResult) {
            self::addToAssertionCount(1);
        } finally {
            \remove_all_filters(Hooks::EDITOR_CONTROLS);
        }
    }
}
