<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\Admin\FieldRestRoute;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\MirrorCodec;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * The field REST route: one write path for Table storage, an explicit
 * permission callback, the closed status map (404 unknown, 423 locked, 409
 * concurrent, 400 value or shape) and the register_rest_field read bindings
 * that put Table storage into the editor's REST representation.
 *
 * @internal
 */
final class FieldRestRouteTest extends TestCase
{
    private const string ROUTE = '/mahout-fields/v1/field-values';

    private const string GROUP = 'fixture_group';

    private ?FieldRestRoute $route = null;

    private \Closure $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh server per test: rest_get_server() caches registered
        // routes, and a stale route's closure would answer for a registry it
        // never saw. The registrar of the previous run is removed so its
        // closure cannot answer a later server for a registry it never saw.
        $GLOBALS['wp_rest_server'] = null;

        if (isset($this->registrar)) {
            \remove_action('rest_api_init', $this->registrar);
        }

        $this->route = null;
        $this->registrar = fn () => $this->route()->register();
        \add_action('rest_api_init', $this->registrar);
    }

    public function testTheRouteRegistersWithAnExplicitPermissionCallback(): void
    {
        $this->registerRoute();

        $routes = rest_get_server()->get_routes();

        self::assertArrayHasKey(self::ROUTE, $routes);
        foreach ($routes[self::ROUTE] as $handler) {
            self::assertArrayHasKey('permission_callback', $handler, 'a route without an explicit permission_callback fails the build');
            self::assertIsCallable($handler['permission_callback']);
        }
    }

    public function testAStoredValueReadsBackThroughTheRegisteredRestField(): void
    {
        $postId = $this->postId();
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register($this->tableGroup());

        $first = $this->writer->writeGroup(self::GROUP, \Iniznet\Mahout\Fields\ObjectRef::post($postId), ['fixture_text' => 'in the editor'], MirrorCodec::hash([]));

        $this->registerRoute();
        $this->route()->registerReads('post', 'fixture_text');

        $response = rest_get_server()->dispatch(new \WP_REST_Request('GET', '/wp/v2/posts/'.$postId));

        self::assertSame(200, $response->get_status());
        self::assertSame('in the editor', $response->get_data()['mahout_fields_fixture_text'] ?? null);
    }

    public function testAFieldWriteStoresAndReturnsTheValueAndTheNewHash(): void
    {
        $postId = $this->postId();
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register($this->tableGroup());
        $this->seedEmptyGroup($postId);
        $this->registerRoute();

        $hash = $this->reader->hash(self::GROUP, \Iniznet\Mahout\Fields\ObjectRef::post($postId));

        $response = $this->dispatch('POST', [
            'object_id' => $postId,
            'field_id' => 'fixture_text',
            'value' => 'routed',
            'expected_hash' => $hash,
        ]);

        self::assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        self::assertSame('routed', $response->get_data()['value']);
        self::assertNotSame('', $response->get_data()['hash']);
        self::assertSame('routed', $this->reader->value('fixture_text', \Iniznet\Mahout\Fields\ObjectRef::post($postId)));
    }

    public function testADeniedCapabilityIsRefusedWithOurCode(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'subscriber']));
        $this->registry->register($this->tableGroup());
        $this->registerRoute();
        $postId = $this->postId();

        $response = $this->dispatch('POST', ['object_id' => $postId, 'field_id' => 'fixture_text', 'value' => 'x']);

        self::assertSame(403, $response->get_status());
        self::assertSame('mahout_fields_forbidden', $response->get_data()['code']);
    }

    public function testAnUnknownFieldIsRefusedWith404(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registerRoute();
        $postId = $this->postId();

        $response = $this->dispatch('POST', ['object_id' => $postId, 'field_id' => 'absent_field', 'value' => 'x', 'expected_hash' => '']);

        self::assertSame(404, $response->get_status());
        self::assertSame('mahout_fields_not_found', $response->get_data()['code']);
    }

    public function testAMetaBoundFieldIsRefusedFromTheRoute(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register(new FieldGroup(self::GROUP, ObjectContext::Post, [
            new TextField('fixture_meta_field', StorageTarget::Meta),
        ]));
        $postId = $this->postId();
        $this->seedEmptyGroup($postId);
        $this->registerRoute();

        $response = $this->dispatch('POST', [
            'object_id' => $postId,
            'field_id' => 'fixture_meta_field',
            'value' => 'x',
            'expected_hash' => $this->reader->hash(self::GROUP, \Iniznet\Mahout\Fields\ObjectRef::post($postId)),
        ]);

        self::assertSame(400, $response->get_status(), 'a Meta field\'s write path is the panel and register_post_meta, never this route');
        self::assertSame('mahout_fields_invalid_value', $response->get_data()['code']);
    }

    public function testALockedPostIsRefusedWith423(): void
    {
        $postId = $this->postId();
        $postId = $this->postId();
        $holder = (int) self::factory()->user->create(['role' => 'editor']);
        update_post_meta($postId, '_edit_lock', (string) (time() + 60).':'.$holder);
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register($this->tableGroup());
        $this->registerRoute();

        $response = $this->dispatch('POST', ['object_id' => $postId, 'field_id' => 'fixture_text', 'value' => 'x', 'expected_hash' => '']);

        self::assertSame(423, $response->get_status());
        self::assertSame('mahout_fields_locked', $response->get_data()['code']);
    }

    public function testAStaleHashIsRefusedWith409AndWritesNothing(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register($this->tableGroup());
        $this->registerRoute();
        $postId = $this->postId();
        $this->writer->writeGroup(self::GROUP, \Iniznet\Mahout\Fields\ObjectRef::post($postId), ['fixture_text' => 'kept'], MirrorCodec::hash([]));

        $response = $this->dispatch('POST', [
            'object_id' => $postId,
            'field_id' => 'fixture_text',
            'value' => 'lost',
            'expected_hash' => MirrorCodec::hash([]),
        ]);

        self::assertSame(409, $response->get_status());
        self::assertSame('mahout_fields_conflict', $response->get_data()['code']);
        self::assertSame('kept', $this->reader->value('fixture_text', \Iniznet\Mahout\Fields\ObjectRef::post($postId)));
    }

    public function testAnInvalidValueIsRefusedWith400(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register($this->tableGroup());
        $this->registerRoute();
        $postId = $this->postId();
        $this->seedEmptyGroup($postId);

        $response = $this->dispatch('POST', [
            'object_id' => $postId,
            'field_id' => 'fixture_integer',
            'value' => 'three',
            'expected_hash' => $this->reader->hash(self::GROUP, \Iniznet\Mahout\Fields\ObjectRef::post($postId)),
        ]);

        self::assertSame(400, $response->get_status());
        self::assertSame('mahout_fields_invalid_value', $response->get_data()['code']);
    }

    // ------------------------------------------------------------------

    /** The route joins core's rest_api_init action, like any registration. */
    private function registerRoute(): void
    {
        \add_action('rest_api_init', fn () => $this->route()->register());
        \do_action('rest_api_init');
    }

    private function route(): FieldRestRoute
    {
        return $this->route ??= new FieldRestRoute($this->registry, $this->writer, $this->reader, $this->diagnostics());
    }

    /** @param array<string, mixed> $body */
    private function dispatch(string $method, array $body): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, self::ROUTE);

        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_get_server()->dispatch($request);
    }

    private function tableGroup(): FieldGroup
    {
        return new FieldGroup(self::GROUP, ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table),
            new IntegerField('fixture_integer', StorageTarget::Table),
        ]);
    }

    /** The empty-hash seed a first guarded single-field write needs. */
    private function seedEmptyGroup(int $postId): void
    {
        $this->writer->writeGroup(self::GROUP, \Iniznet\Mahout\Fields\ObjectRef::post($postId), [], MirrorCodec::hash([]));
    }
}
