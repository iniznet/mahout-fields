<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Admin\FieldEditor;
use Iniznet\Mahout\Fields\Contracts\FieldUiPolicy;
use Iniznet\Mahout\Fields\Exception\InvalidControlOverride;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\FieldUi;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectKind;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * The UI policy's contract, proved at the render: the defaults render the
 * built-in styled control, a per-field styled=false renders the same control
 * bare, a named replacement replaces the control, and a name that is not a
 * control is refused at the render site -- never skipped, because a silent
 * skip would render a field the host believes it replaced.
 *
 * @internal
 */
final class FieldUiPolicyTest extends TestCase
{
    private const string GROUP = 'fixture_group';

    public function testTheDefaultsAreTheBuiltInControlStyled(): void
    {
        $ui = new FieldUi();

        self::assertTrue($ui->styled);
        self::assertNull($ui->control);
    }

    public function testAnUnstyledFieldRendersTheBuiltInControlBare(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $this->writer->set('fixture_text', ObjectRef::post($postId), 'bare');
        $editor = $this->editor(new class implements FieldUiPolicy {
            #[\Override]
            public function styled(): bool
            {
                return true;
            }

            #[\Override]
            public function fields(): array
            {
                return ['fixture_text' => new FieldUi(styled: false)];
            }
        });

        $markup = $editor->render($editor->props(self::GROUP, $postId, ObjectKind::Post, ''));

        self::assertStringContainsString('mahout-fields-unstyled', $markup, 'the bare marker is the one class the default stylesheet never targets');
        self::assertStringNotContainsString('mahout-fields-control--text', $markup);
        self::assertStringNotContainsString('mahout-fields-error', $markup);
        self::assertStringNotContainsString('mahout-fields-help', $markup);
    }

    public function testAStyledFieldKeepsEveryDefaultClass(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $this->writer->set('fixture_text', ObjectRef::post($postId), 'styled');
        $editor = $this->editor();

        $markup = $editor->render($editor->props(self::GROUP, $postId, ObjectKind::Post, ''));

        self::assertStringContainsString('mahout-fields-control--text', $markup);
    }

    public function testANamedControlReplacesTheBuiltIn(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $this->writer->set('fixture_text', ObjectRef::post($postId), 'replaced');
        $editor = $this->editor(new class implements FieldUiPolicy {
            #[\Override]
            public function styled(): bool
            {
                return true;
            }

            #[\Override]
            public function fields(): array
            {
                return ['fixture_text' => new FieldUi(control: StubControl::class)];
            }
        });

        $markup = $editor->render($editor->props(self::GROUP, $postId, ObjectKind::Post, ''));

        self::assertStringContainsString('data-stub-field="fixture_text"', $markup);
        self::assertStringNotContainsString('mahout-field-fixture_text', $markup, 'the built-in input is gone, not rendered beside its replacement');
    }

    public function testANameThatIsNotAControlIsRefusedAtTheRenderSite(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $this->writer->set('fixture_text', ObjectRef::post($postId), 'x');
        $editor = $this->editor(new class implements FieldUiPolicy {
            #[\Override]
            public function styled(): bool
            {
                return true;
            }

            #[\Override]
            public function fields(): array
            {
                return ['fixture_text' => new FieldUi(control: \stdClass::class)];
            }
        });

        $this->expectException(InvalidControlOverride::class);
        $editor->render($editor->props(self::GROUP, $postId, ObjectKind::Post, ''));
    }

    private function editor(?FieldUiPolicy $policy = null): FieldEditor
    {
        return new FieldEditor(
            new \Iniznet\Mahout\Fields\Admin\FieldTypeRegistry(),
            $this->registry,
            $this->reader,
            $policy,
        );
    }

    private function group(): FieldGroup
    {
        return new FieldGroup(self::GROUP, ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table, label: 'Text'),
        ], label: 'Fixture panel');
    }
}
