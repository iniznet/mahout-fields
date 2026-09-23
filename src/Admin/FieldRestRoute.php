<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Capabilities;
use Iniznet\Mahout\Fields\Contracts\FieldReader;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldWriter;
use Iniznet\Mahout\Fields\Exception\ConcurrentEditLost;
use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Exception\InvalidRepeaterPayload;
use Iniznet\Mahout\Fields\Exception\MahoutException;
use Iniznet\Mahout\Fields\Exception\PostLockedForWrite;
use Iniznet\Mahout\Fields\Internal\PostLock;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Kernel\Diagnostics;

/**
 * The field REST route: the editor-side write path for one field, and the
 * register_rest_field read bindings that put Table storage into the editor's
 * REST representation, which register_post_meta cannot see.
 *
 * The permission callback is explicit and separate: capability first, then
 * the post lock inside write(), then core's own cookie-nonce verification,
 * which owns this path's nonce. A refused write is one record and one
 * WP_Error; nothing is retried and nothing is substituted.
 */
final readonly class FieldRestRoute
{
    private const string ROUTE_NAMESPACE = 'mahout-fields/v1';

    private const string ROUTE = '/field-values';

    public function __construct(
        private FieldRegistry $registry,
        private FieldWriter $writer,
        private FieldReader $reader,
        private Diagnostics $diagnostics,
        private PostLock $lock = new PostLock(),
    ) {
    }

    /** The rest_api_init entry, attached by Admin\FieldsUiProvider. */
    public function register(): void
    {
        \register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => $this->write(...),
            'permission_callback' => $this->authorizeWrite(...),
            'args' => $this->args(),
        ]);
    }

    /**
     * register_rest_field read bindings for one post type's fields, so a
     * Table field can reach the editor's REST representation. Read-only: the
     * write path is the field route, and update_callback stays null.
     */
    public function registerReads(string $postType, string ...$fieldIds): void
    {
        foreach ($fieldIds as $fieldId) {
            \register_rest_field($postType, self::attribute($fieldId), [
                // Core hands the callback the REST-shaped post array; the id
                // is the one fact the read needs.
                'get_callback' => fn (array $post): string|int|float|bool|null => $this->reader->value(
                    $fieldId,
                    ObjectRef::post(\is_numeric($post['id'] ?? null) ? (int) $post['id'] : 0),
                ),
                'update_callback' => null,
                'schema' => null,
            ]);
        }
    }

    /** The REST attribute name a field's value is exposed under. */
    public static function attribute(string $fieldId): string
    {
        return 'mahout_fields_'.$fieldId;
    }

    /**
     * Guard 5: the capability, decided before anything else reads. The lock
     * and the shape are decided inside write(); a permission callback that
     * touched the lock would read twice.
     */
    private function authorizeWrite(\WP_REST_Request $request): bool|\WP_Error
    {
        $objectIdParam = $request->get_param('object_id');

        if (!\is_numeric($objectIdParam) || (int) $objectIdParam < 1) {
            return new \WP_Error(
                'mahout_fields_invalid_object',
                __('The object_id parameter must be a positive integer.', 'mahout-fields'),
                ['status' => 400],
            );
        }

        $objectId = (int) $objectIdParam;

        if (!\current_user_can(Capabilities::EditPost->value, $objectId)) {
            return new \WP_Error(
                'mahout_fields_forbidden',
                __('You are not allowed to edit this object.', 'mahout-fields'),
                ['status' => 403],
            );
        }

        return true;
    }

    /**
     * Guards 4, 7, 8 and 9, in the shared order: the lock, the declaration,
     * sanitisation and the store -- the writer's transaction carries the
     * lost-update read and the mirror. The status mapping is the closed one:
     * 404 unknown field, 409 lock or lost update, 400 value or shape, 500
     * everything else the package refuses.
     */
    private function write(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $objectIdParam = $request->get_param('object_id');
        $fieldId = \is_string($request->get_param('field_id')) ? (string) $request->get_param('field_id') : '';
        $value = $request->get_param('value');
        $expectedHash = \is_string($request->get_param('expected_hash') ?? null) ? (string) $request->get_param('expected_hash') : '';

        if (!\is_numeric($objectIdParam) || (int) $objectIdParam < 1) {
            return new \WP_Error('mahout_fields_invalid_object', __('The object_id parameter must be a positive integer.', 'mahout-fields'), ['status' => 400]);
        }

        $objectId = (int) $objectIdParam;

        if (null !== $value && !\is_scalar($value)) {
            return new \WP_Error('mahout_fields_invalid_value', __('The value must be a scalar.', 'mahout-fields'), ['status' => 400]);
        }

        try {
            $holder = $this->lock->holder($objectId);

            if (null !== $holder) {
                throw PostLockedForWrite::forObject($objectId, $holder);
            }

            $this->registry->field($fieldId);
            $newHash = $this->writer->writeField($fieldId, ObjectRef::post($objectId), $value, $expectedHash);

            return new \WP_REST_Response([
                'field_id' => $fieldId,
                'value' => $this->reader->value($fieldId, ObjectRef::post($objectId)),
                'hash' => $newHash,
            ], 200);
        } catch (FieldNotFound $refusal) {
            return $this->refused($refusal, $fieldId, $objectId, 'mahout_fields_not_found', 404);
        } catch (PostLockedForWrite $refusal) {
            return $this->refused($refusal, $fieldId, $objectId, 'mahout_fields_locked', 423);
        } catch (ConcurrentEditLost $refusal) {
            return $this->refused($refusal, $fieldId, $objectId, 'mahout_fields_conflict', 409);
        } catch (
            InvalidFieldValue
            |InvalidRepeaterPayload
            |InvalidFieldWrite
            |InvalidFieldContext $refusal
        ) {
            return $this->refused($refusal, $fieldId, $objectId, 'mahout_fields_invalid_value', 400);
        } catch (MahoutException $refusal) {
            return $this->refused($refusal, $fieldId, $objectId, 'mahout_fields_write_failed', 500);
        }
    }

    /** One record, one refusal value, one error envelope. */
    private function refused(\Throwable $refusal, string $fieldId, int $objectId, string $code, int $status): \WP_Error
    {
        $recorded = SaveRefusal::record($refusal, '', $objectId, $this->diagnostics);

        return new \WP_Error(
            $code,
            $refusal->getMessage(),
            ['status' => $status, 'reference' => $recorded->reference, 'field' => $fieldId],
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function args(): array
    {
        return [
            'object_id' => [
                'type' => 'integer',
                'required' => true,
                'minimum' => 1,
            ],
            'field_id' => [
                'type' => 'string',
                'required' => true,
            ],
            'value' => [
                'type' => ['string', 'integer', 'number', 'boolean', 'null'],
                'required' => true,
            ],
            'expected_hash' => [
                'type' => 'string',
                'required' => false,
                'default' => '',
            ],
        ];
    }
}
