<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Contract;

use Iniznet\Mahout\Fields\Admin\FieldControlProps;
use Iniznet\Mahout\Fields\Admin\FieldEditor;
use Iniznet\Mahout\Fields\Admin\FieldTypeRegistry;
use Iniznet\Mahout\Fields\Contracts\ControlRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldControl;
use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\FieldType;
use Iniznet\Mahout\Fields\Hooks;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectKind;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * The control-registry contract: the lookup the editor renders through and
 * nothing else. Both forms of a type's name answer to one control; the report
 * refuses nothing while the lookup refuses loudly; and the editor depends on
 * the contract, so a host that binds another implementation renders through
 * its own controls and never through the package's class.
 *
 * @internal
 */
final class ControlRegistryContractTest extends TestCase
{
    private const string GROUP = 'fixture_group';

    public function testThePackageRegistryIsTheContract(): void
    {
        self::assertInstanceOf(ControlRegistry::class, new FieldTypeRegistry());
    }

    public function testTheEnumCaseAndItsValueAnswerToTheSameControl(): void
    {
        $registry = new FieldTypeRegistry();

        foreach (FieldType::cases() as $type) {
            self::assertTrue($registry->has($type), $type->value);
            self::assertTrue($registry->has($type->value), $type->value);
            self::assertSame($registry->control($type), $registry->control($type->value), $type->value.' is one control named two ways');
        }
    }

    public function testAnUnservedTypeIsReportedAndRefusedAtTheLookup(): void
    {
        $registry = new FieldTypeRegistry();

        self::assertFalse($registry->has('no_such_type'), 'the report only answers the question it was asked');

        try {
            $registry->control('no_such_type');
            self::fail('a type no control serves must refuse at the lookup');
        } catch (InvalidFieldDefinition $refusal) {
            self::assertSame('no_such_type', $refusal->fieldId());
        }
    }

    public function testAFilteredControlIsResolvableThroughTheContract(): void
    {
        $replacement = new class implements FieldControl {
            #[\Override]
            public function render(FieldControlProps $props): string
            {
                return '<filtered/>';
            }
        };

        \add_filter(Hooks::EDITOR_CONTROLS, static function (array $controls) use ($replacement): array {
            $controls[FieldType::Text->value] = $replacement;

            return $controls;
        });

        $registry = new FieldTypeRegistry();

        self::assertSame($replacement, $registry->control(FieldType::Text), 'the contract answers the filtered map');
        self::assertSame($replacement, $registry->control(FieldType::Text->value), 'and answers it under either form of the name');
    }

    public function testTheEditorRendersThroughTheContractAndNotTheConcreteRegistry(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup(self::GROUP, ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Meta),
        ]));

        $stub = new class implements ControlRegistry {
            #[\Override]
            public function control(FieldType|string $type): FieldControl
            {
                return new class implements FieldControl {
                    #[\Override]
                    public function render(FieldControlProps $props): string
                    {
                        return '<contract-backed-control field="'.$props->fieldId.'"/>';
                    }
                };
            }

            #[\Override]
            public function has(FieldType|string $type): bool
            {
                return true;
            }
        };

        $editor = new FieldEditor($stub, $this->registry, $this->reader);
        $markup = $editor->render($editor->props(self::GROUP, $postId, ObjectKind::Post, ''));

        self::assertStringContainsString('<contract-backed-control field="fixture_text"/>', $markup);
        self::assertStringNotContainsString('mahout-fields-control', $markup, 'the built-in control markup is unreachable when the contract answers elsewhere');
    }
}
