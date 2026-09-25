<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Fields\Capabilities;
use Iniznet\Mahout\Fields\Exception\InvalidMirrorPayload;
use Iniznet\Mahout\Fields\MirrorCodec;
use Iniznet\Mahout\Fields\ObjectRef;

/**
 * The revision mirror: one single meta row per post per group, holding the
 * group's Table rows as the versioned payload the mirror codec encodes.
 *
 * It exists because Table storage forfeits what meta gets for free. The key
 * is registered with revisions_enabled, so CORE copies the mirror into every
 * revision and restores it on wp_restore_post_revision -- core's mechanism,
 * not a parallel one. The mirror is never read as a value: reads go to the
 * table, and the mirror serves revisions and the lost-update reference only.
 *
 * The hash is read back from the stored payload, never recomputed from the
 * table, so an out-of-band table edit is caught rather than blessed.
 *
 * @internal
 */
final readonly class RevisionMirror
{
    private const string KEY_PREFIX = '_mahout_mirror_';

    /**
     * The meta key for one group's mirror. The leading underscore is core's
     * reserved internal shape; the key is protected meta and is never exposed.
     */
    public static function keyFor(string $groupId): string
    {
        return self::KEY_PREFIX.$groupId;
    }

    /**
     * Register the mirror key as revisioned for one post type. Core rejects
     * the flag unless the object type is post and the type supports revisions;
     * the caller names a post type that does. The key is never exposed in REST.
     */
    public static function register(string $postType, string $groupId): void
    {
        \register_meta('post', self::keyFor($groupId), [
            'object_subtype' => $postType,
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
            'revisions_enabled' => true,
            'auth_callback' => static fn (bool $allowed, string $metaKey, int $objectId): bool => \current_user_can(Capabilities::EditPost->value, $objectId),
        ]);
    }

    /**
     * The reference hash an editor form carries: the stored payload's hash,
     * or the empty row set's hash when no mirror exists yet. The empty hash
     * is the upgrade path -- rows written before this slice existed carry no
     * mirror, and the first guarded write seeds one.
     */
    public function currentHash(ObjectRef $object, string $groupId): string
    {
        $payload = $this->payload($object, $groupId);

        if (null === $payload) {
            return MirrorCodec::hash([]);
        }

        return MirrorCodec::decode($groupId, $payload)['hash'];
    }

    /**
     * The decoded payload, or null when the post carries no mirror. A
     * non-scalar under the key is broken data and is refused, never coerced.
     *
     * @return array{hash: string, rows: list<array{field: string, items: list<string|int|float|bool>}|array{field: string, leaves: list<array{address: string, value: string|int|float|bool}>}|array{field: string, value: string|int|float|bool}>}|null
     *
     * @throws InvalidMirrorPayload
     */
    public function payloadOf(ObjectRef $object, string $groupId): ?array
    {
        $payload = $this->payload($object, $groupId);

        if (null === $payload) {
            return null;
        }

        return MirrorCodec::decode($groupId, $payload);
    }

    /**
     * Store one group's row set as the mirror, inside the caller's
     * transaction, and return the hash it now carries.
     *
     * @param list<array{field: string, leaves: list<array{address: string, value: string|int|float|bool}>}|array{field: string, value: string|int|float|bool}> $rows
     *
     * @throws InvalidMirrorPayload
     */
    public function write(ObjectRef $object, string $groupId, array $rows): string
    {
        $encoded = MirrorCodec::encode($rows);

        $written = \update_post_meta($object->id, self::keyFor($groupId), $encoded);

        // Core returns false both for a refused write and for an unchanged
        // value, so a false return is verified before it is believed: the
        // stored payload must match what this write asked to store.
        if (!$written && $encoded !== $this->payload($object, $groupId)) {
            throw InvalidMirrorPayload::writeRefused($groupId);
        }

        return MirrorCodec::hash(MirrorCodec::decode($groupId, $encoded)['rows']);
    }

    private function payload(ObjectRef $object, string $groupId): ?string
    {
        $raw = \get_metadata_raw('post', $object->id, self::keyFor($groupId));

        if (null === $raw || false === $raw) {
            return null;
        }

        if (!\is_array($raw) || !\is_scalar($raw[0] ?? null)) {
            throw InvalidMirrorPayload::unreadable($groupId);
        }

        return (string) $raw[0];
    }
}
