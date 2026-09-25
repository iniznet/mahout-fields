<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Capabilities;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldWriter;
use Iniznet\Mahout\Fields\Contracts\Panels;
use Iniznet\Mahout\Fields\Contracts\RequestInput;
use Iniznet\Mahout\Fields\Exception\AuthorizationDenied;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\NonceFailed;
use Iniznet\Mahout\Fields\Exception\PostLockedForWrite;
use Iniznet\Mahout\Fields\Internal\PostLock;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Kernel\Diagnostics;

/**
 * The save lifecycle's guard pipeline, in the declared order:
 *
 * 1 autosave -- silent. 2 revision -- silent. 3 foreign form -- silent.
 * 4 post lock -- PostLockedForWrite, warning. 5 capability --
 * AuthorizationDenied. 6 nonce -- NonceFailed, one warning, never wp_die().
 * 7 field shape -- FieldNotFound. 8 sanitise, inside writeGroup(), before the
 * transaction opens. 9 store: writeGroup()'s transaction, the lost-update
 * read inside it, the mirror last. 10 record: a refusal's record is made once
 * here, and mahout/fields/after_save fires per field from the writer.
 *
 * Guards 1 to 3 return silently; 4 to 9 refuse loudly -- one record, zero
 * partial writes, no wp_die(), no retry, no substitute. Nothing writes before
 * guard 9.
 *
 * The classic entry runs the pipeline per submitted group; the REST route
 * calls save() after core's cookie nonce check, which owns that path's nonce.
 */
final readonly class FieldSaveHandler
{
    public function __construct(
        private RequestInput $request,
        private FieldWriter $writer,
        private FieldRegistry $registry,
        private Diagnostics $diagnostics,
        private Panels $panels,
        private PostLock $lock,
        private WriteFailureNotice $notices,
    ) {
    }

    /**
     * The save_post entry, at priority 10 with accepted_args 3. Guards 1 to 3
     * return silently: an autosave or a revision is not the object, and a
     * save_post that did not come from the panel -- quick edit, bulk edit,
     * XML-RPC, WP-CLI, a programmatic save -- passes through untouched.
     */
    public function handle(int $postId, \WP_Post $post, bool $update): void
    {
        if (\wp_is_post_autosave($postId)) {
            return;
        }

        if (\wp_is_post_revision($postId)) {
            return;
        }

        if (!$this->request->has(Nonces::nonceField())) {
            return;
        }

        // The panels derive the save entry, and they scope it: a group no
        // panel of this post type declares is a mass-assignment attempt or a
        // stale form -- recorded and noticed, never written -- while the
        // declared groups of the same submission still save.
        $allowed = [];

        foreach ($this->panels->forPostType($post->post_type) as $panel) {
            $allowed[$panel->group->id] = true;
        }

        $nonce = $this->request->string(Nonces::nonceField()) ?? '';
        $hashes = $this->request->hashes();
        $noticed = false;

        foreach ($this->request->groups() as $groupId => $values) {
            $groupId = (string) $groupId;

            if (!isset($allowed[$groupId])) {
                if (!$noticed) {
                    $this->notices->queue(\get_current_user_id(), $postId, SaveRefusal::record(
                        InvalidFieldContext::undeclaredForPostType($groupId, $post->post_type),
                        $groupId,
                        $postId,
                        $this->diagnostics,
                    ));
                    $noticed = true;
                }

                continue;
            }

            $outcome = $this->save(ObjectRef::post($postId), $groupId, $values, $nonce, $hashes[$groupId] ?? '');

            if ($outcome->refusal instanceof SaveRefusal) {
                // The lifecycle refuses loudly and stops: the refused group
                // wrote nothing, and no later group of the same submission
                // writes either.
                $this->notices->queue(\get_current_user_id(), $postId, $outcome->refusal);

                return;
            }
        }
    }

    /**
     * The shared pipeline: guards 4 to 8 in order, then the store step. A
     * refusal records once and returns as a value; a save that was refused
     * wrote nothing.
     *
     * @param array<string, string|list<string>|null> $values field id to submitted value
     */
    public function save(ObjectRef $object, string $groupId, array $values, string $nonce, string $expectedHash): SaveOutcome
    {
        try {
            $this->guards($object, $groupId, $values, $nonce, $expectedHash);

            return SaveOutcome::stored($this->writer->writeGroup($groupId, $object, $values, $expectedHash));
        } catch (\Iniznet\Mahout\Fields\Exception\MahoutException $refusal) {
            return SaveOutcome::refused(SaveRefusal::record($refusal, $groupId, $object->id, $this->diagnostics));
        }
    }

    /**
     * Guards 4 to 7, in order, each decided before the next runs. Guards 8
     * and 9 live inside writeGroup(): sanitisation happens before the
     * transaction opens, the lost-update read after it does.
     *
     * @param array<string, string|list<string>|null> $values
     */
    private function guards(ObjectRef $object, string $groupId, array $values, string $nonce, string $expectedHash): void
    {
        $holder = $this->lock->holder($object->id);

        if (null !== $holder) {
            throw PostLockedForWrite::forObject($object->id, $holder);
        }

        if (!\current_user_can(Capabilities::EditPost->value, $object->id)) {
            throw AuthorizationDenied::forObject($object->id);
        }

        if (1 !== \wp_verify_nonce($nonce, Nonces::action($object->id))) {
            throw NonceFailed::forObject($object->id);
        }

        $this->assertShape($groupId, $values);
    }

    /**
     * Guard 7: every submitted field id must resolve in the group's
     * declaration. A submitted id the group does not declare is a
     * mass-assignment attempt or a stale form; both are refusals, decided
     * before any value is sanitised or stored.
     *
     * @param array<string, string|list<string>|null> $values
     */
    private function assertShape(string $groupId, array $values): void
    {
        $group = $this->registry->group($groupId);

        foreach (\array_keys($values) as $fieldId) {
            if (!\array_any($group->fields, static fn ($field): bool => $field->id === $fieldId)) {
                throw \Iniznet\Mahout\Fields\Exception\FieldNotFound::inGroup((string) $fieldId, $groupId);
            }
        }
    }
}
