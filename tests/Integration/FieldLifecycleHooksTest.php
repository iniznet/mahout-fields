<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Db\Internal\WpdbConnection;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\Hooks;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * The hooks this slice emits, with their exact argument shapes. Filters always
 * return the first argument; a filter returning garbage is a trust-boundary
 * refusal, not a coercion.
 *
 * @internal
 */
final class FieldLifecycleHooksTest extends TestCase
{
    public function testRegistryLoadedFiresWithTheRegistryOnBoot(): void
    {
        $registry = null;
        \add_action(Hooks::REGISTRY_LOADED, static function (object $loaded) use (&$registry): void {
            $registry = $loaded;
        });

        $provider = new \Iniznet\Mahout\Fields\FieldsProvider();
        $container = new \Iniznet\Mahout\Kernel\Container();
        $container->set($this->gateway(), id: \Iniznet\Mahout\Db\Contracts\TableGateway::class);
        $container->set(WpdbConnection::inWordPress(), id: \Iniznet\Mahout\Db\Contracts\SqlConnection::class);
        $provider->register($container);
        $provider->boot($container);

        self::assertInstanceOf(\Iniznet\Mahout\Fields\Contracts\FieldRegistry::class, $registry);
    }

    public function testGroupRegisteredFiresPerGroup(): void
    {
        $groups = [];
        \add_action(Hooks::GROUP_REGISTERED, static function (object $group) use (&$groups): void {
            $groups[] = $group->id;
        });

        $this->registry->register(new FieldGroup('hooked_group', ObjectContext::Post, [
            new TextField('hooked_text', StorageTarget::Meta),
        ]));

        self::assertSame(['hooked_group'], $groups);
    }

    public function testSanitizedValueFilterRunsBetweenSanitisationAndStorage(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('hooked_group', ObjectContext::Post, [
            new TextField('hooked_text', StorageTarget::Meta),
        ]));

        \add_filter(Hooks::SANITIZED_VALUE, static fn (mixed $value): mixed => is_string($value) ? strtoupper($value) : $value);

        $this->writer->set('hooked_text', ObjectRef::post($postId), 'lower');

        \remove_all_filters(Hooks::SANITIZED_VALUE);

        self::assertSame('LOWER', $this->reader->value('hooked_text', ObjectRef::post($postId)));
    }

    public function testTheValueFilterFiresOnReadWithTheDocumentedArguments(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('hooked_read', ObjectContext::Post, [
            new TextField('hooked_field', StorageTarget::Meta),
        ]));

        $this->writer->set('hooked_field', ObjectRef::post($postId), 'value');

        $seen = null;
        \add_filter(Hooks::VALUE, static function (mixed $value, string $fieldId, object $context, int $objectId) use (&$seen): mixed {
            $seen = [$fieldId, $context->value, $objectId];

            return $value;
        }, 10, 4);

        $value = $this->reader->value('hooked_field', ObjectRef::post($postId));

        \remove_all_filters(Hooks::VALUE);

        self::assertSame('value', $value);
        self::assertSame(['hooked_field', 'post', $postId], $seen);
    }

    public function testThePerFieldVariantFiresWithTheFieldObject(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('hooked_per', ObjectContext::Post, [
            new TextField('hooked_per_field', StorageTarget::Meta),
        ]));

        $this->writer->set('hooked_per_field', ObjectRef::post($postId), 'value');

        $seen = null;
        $name = Hooks::perFieldValue('hooked_per_field');
        \add_filter($name, static function (mixed $value, object $field, int $objectId) use (&$seen): mixed {
            $seen = [$field->id, $objectId];

            return $value;
        }, 10, 3);

        $this->reader->value('hooked_per_field', ObjectRef::post($postId));

        \remove_all_filters($name);

        self::assertSame(['hooked_per_field', $postId], $seen);
    }

    public function testBeforeAndAfterSaveFireAroundTheWrite(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('hooked_saves', ObjectContext::Post, [
            new TextField('hooked_save_field', StorageTarget::Meta),
        ]));

        $order = [];
        \add_action(Hooks::BEFORE_SAVE, static function (string $fieldId, mixed $value) use (&$order): void {
            $order[] = ['before', $fieldId, $value];
        }, 10, 3);
        \add_action(Hooks::AFTER_SAVE, static function (string $fieldId, mixed $value) use (&$order): void {
            $order[] = ['after', $fieldId, $value];
        }, 10, 3);

        $this->writer->set('hooked_save_field', ObjectRef::post($postId), ' raw <b>value</b> ');

        \remove_all_actions(Hooks::BEFORE_SAVE);
        \remove_all_actions(Hooks::AFTER_SAVE);

        self::assertSame('raw value', $this->reader->value('hooked_save_field', ObjectRef::post($postId)));
        self::assertSame(['before', 'hooked_save_field', ' raw <b>value</b> '], $order[0]);
        self::assertSame(['after', 'hooked_save_field', ' raw <b>value</b> '], $order[1]);
    }

    public function testBeforeAndAfterDeleteFireAroundTheDelete(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('hooked_deletes', ObjectContext::Post, [
            new TextField('hooked_delete_field', StorageTarget::Table),
        ]));

        $order = [];
        \add_action(Hooks::BEFORE_DELETE, static function (string $fieldId) use (&$order): void {
            $order[] = ['before', $fieldId];
        }, 10, 1);
        \add_action(Hooks::AFTER_DELETE, static function (string $fieldId) use (&$order): void {
            $order[] = ['after', $fieldId];
        }, 10, 1);

        $this->writer->set('hooked_delete_field', ObjectRef::post($postId), 'value');
        $this->writer->delete('hooked_delete_field', ObjectRef::post($postId));

        \remove_all_actions(Hooks::BEFORE_DELETE);
        \remove_all_actions(Hooks::AFTER_DELETE);

        self::assertSame([['before', 'hooked_delete_field'], ['after', 'hooked_delete_field']], $order);
        self::assertNull($this->reader->value('hooked_delete_field', ObjectRef::post($postId)));
    }

    public function testAFilterReturningANonScalarIsRefused(): void
    {
        $postId = $this->postId();
        $this->registry->register(new FieldGroup('hooked_guard', ObjectContext::Post, [
            new TextField('hooked_guard_field', StorageTarget::Meta),
        ]));

        \add_filter(Hooks::VALUE, static fn (): array => ['bad']);

        try {
            $this->reader->value('hooked_guard_field', ObjectRef::post($postId));
            self::fail('A non-scalar filter return must be refused.');
        } catch (\Iniznet\Mahout\Fields\Exception\InvalidFilterResult) {
            self::assertTrue(true);
        } finally {
            \remove_all_filters(Hooks::VALUE);
        }
    }

    private function gateway(): \Iniznet\Mahout\Db\Contracts\TableGateway
    {
        return $this->gateway;
    }
}
