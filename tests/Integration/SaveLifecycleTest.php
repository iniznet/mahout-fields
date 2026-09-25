<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\Admin\FieldSaveHandler;
use Iniznet\Mahout\Fields\Admin\Nonces;
use Iniznet\Mahout\Fields\Admin\SaveRefusalReason;
use Iniznet\Mahout\Fields\Admin\WriteFailureNotice;
use Iniznet\Mahout\Fields\Exception\MalformedNoticePayload;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\FieldPanel;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\Internal\PostLock;
use Iniznet\Mahout\Fields\MirrorCodec;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\Fixtures\ArrayRequestInput;
use Iniznet\Mahout\Fields\Tests\Fixtures\DeclaredPanels;
use Iniznet\Mahout\Fields\Tests\Fixtures\RecordingWriter;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;
use Iniznet\Mahout\Kernel\Level;

/**
 * The save lifecycle's guard pipeline, proved in order by multi-fault
 * scenarios: guard N's refusal is the only record, and guard N+1 never runs
 * after guard N refuses. Nothing writes before guard 9; a refusal surfaces
 * as a value, never as wp_die(); a refused classic save queues exactly one
 * per-user notice.
 *
 * @internal
 */
final class SaveLifecycleTest extends TestCase
{
    private const string GROUP = 'fixture_group';

    private const string SECOND_GROUP = 'fixture_second_group';

    private const string THIRD_GROUP = 'fixture_third_group';

    public function testAnAutosavePassesThroughSilently(): void
    {
        $postId = $this->postId();
        [$handler, $writer] = $this->spyHandler();

        $autosaveId = (int) self::factory()->post->create([
            'post_type' => 'revision',
            'post_status' => 'inherit',
            'post_parent' => $postId,
            'post_name' => $postId.'-autosave',
        ]);

        $handler->handle($autosaveId, get_post($autosaveId), true);

        self::assertSame([], $writer->calls, 'an autosave is not the object; nothing writes');
    }

    public function testARevisionPassesThroughSilently(): void
    {
        $postId = $this->postId();
        [$handler, $writer] = $this->spyHandler();

        $revisionId = (int) wp_save_post_revision($postId);

        self::assertNotSame(0, $revisionId, 'the fixture needs a revision to guard against');

        $handler->handle($revisionId, get_post($revisionId), true);

        self::assertSame([], $writer->calls);
    }

    public function testASavePostFromAnotherFormPassesThroughSilently(): void
    {
        [$handler, $writer] = $this->spyHandler();
        $postId = $this->postId();

        // No panel nonce field in the submitted body: quick edit, bulk edit,
        // XML-RPC, WP-CLI and programmatic saves all look exactly like this.
        $handler->handle($postId, get_post($postId), true);

        self::assertSame([], $writer->calls);
    }

    public function testAHeldPostLockRefusesBeforeEveryLaterGuard(): void
    {
        $postId = $this->postId();
        $holder = self::factory()->user->create(['role' => 'editor']);
        update_post_meta($postId, '_edit_lock', (string) (time() + 60).':'.$holder);
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));

        // A stale hash, an unknown field id and a bad nonce are submitted
        // behind the lock; the lock is guard 4 and none of them is reached.
        $handler = new FieldSaveHandler(
            new ArrayRequestInput(
                body: [Nonces::nonceField() => 'not-a-nonce'],
                groups: [self::GROUP => ['fixture_text' => 'x', 'ghost' => 'y']],
                hashes: [self::GROUP => 'stale-hash'],
            ),
            $writer = new RecordingWriter(),
            $this->registry,
            $diagnostics = $this->diagnostics(),
            $this->panels(),
            new PostLock(),
            new WriteFailureNotice(),
        );

        $outcome = $handler->save(ObjectRef::post($postId), self::GROUP, ['fixture_text' => 'x', 'ghost' => 'y'], 'not-a-nonce', 'stale-hash');

        self::assertFalse($outcome->isStored());
        self::assertSame(SaveRefusalReason::PostLocked, $outcome->refusal->reason);
        self::assertSame([], $writer->calls, 'a locked post is never written, by any later guard');
        self::assertCount(1, $diagnostics->records(), 'one condition, one record');
        self::assertSame(Level::Warning, $diagnostics->records()[0]->level);
        self::assertStringContainsString('MH-', $outcome->refusal->reference);
    }

    public function testADeniedCapabilityRefusesAndRecordsOnce(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'subscriber']));
        [$handler, $writer, $diagnostics] = $this->spyHandler();
        $postId = $this->postId();

        $outcome = $handler->save(ObjectRef::post($postId), self::GROUP, ['fixture_text' => 'x'], $this->nonce($postId), '');

        self::assertFalse($outcome->isStored());
        self::assertSame(SaveRefusalReason::AuthorizationDenied, $outcome->refusal->reason);
        self::assertSame([], $writer->calls);
        self::assertCount(1, $diagnostics->records());
        self::assertSame(Level::Warning, $diagnostics->records()[0]->level);
    }

    public function testAFailedNonceRefusesWithOneWarningAndNeverWpDies(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        [$handler, $writer, $diagnostics] = $this->spyHandler();
        $postId = $this->postId();

        $outcome = $handler->save(ObjectRef::post($postId), self::GROUP, ['fixture_text' => 'x'], 'expired-or-forged', '');

        self::assertFalse($outcome->isStored());
        self::assertSame(SaveRefusalReason::NonceFailed, $outcome->refusal->reason);
        self::assertSame([], $writer->calls);
        self::assertCount(1, $diagnostics->records());
        self::assertSame(Level::Warning, $diagnostics->records()[0]->level);
    }

    public function testASubmittedIdOutsideTheDeclarationRefusesAsAShape(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        [$handler, $writer, $diagnostics] = $this->spyHandler();
        $postId = $this->postId();

        $outcome = $handler->save(ObjectRef::post($postId), self::GROUP, ['fixture_text' => 'x', 'ghost' => 'v'], $this->nonce($postId), MirrorCodec::hash([]));

        self::assertSame(SaveRefusalReason::FieldShape, $outcome->refusal->reason);
        self::assertSame([], $writer->calls, 'a mass-assignment attempt never reaches the writer');
        self::assertCount(1, $diagnostics->records());
    }

    public function testAGuardedSaveStoresTheGroupAndReturnsTheNewHash(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        [$handler, $writer, $diagnostics] = $this->spyHandler();
        $postId = $this->postId();

        $outcome = $handler->save(ObjectRef::post($postId), self::GROUP, ['fixture_text' => 'guarded', 'fixture_integer' => 3], $this->nonce($postId), MirrorCodec::hash([]));

        self::assertTrue($outcome->isStored());
        self::assertSame(['fixture_text' => 'guarded', 'fixture_integer' => 3], $writer->written[0] ?? null);
        self::assertSame('hash-after-write', $outcome->hash);
        self::assertSame([], $diagnostics->records(), 'a clean save records nothing');
    }

    public function testTheClassicEntryWritesThroughTheRealWriter(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register($this->group());
        $postId = $this->postId();

        $handler = new FieldSaveHandler(
            new ArrayRequestInput(
                body: [Nonces::nonceField() => $this->nonce($postId)],
                groups: [self::GROUP => ['fixture_text' => 'through-the-panel']],
                hashes: [self::GROUP => MirrorCodec::hash([])],
            ),
            $this->writer,
            $this->registry,
            $this->diagnostics(),
            $this->panels(),
            new PostLock(),
            new WriteFailureNotice(),
        );
        $handler->handle($postId, get_post($postId), true);

        self::assertSame('through-the-panel', $this->reader->value('fixture_text', ObjectRef::post($postId)));
    }

    public function testARefusedClassicSaveQueuesThePerUserNoticeOnce(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $postId = $this->postId();
        $handler = new FieldSaveHandler(
            new ArrayRequestInput(
                body: [Nonces::nonceField() => 'expired-or-forged'],
                groups: [self::GROUP => ['fixture_text' => 'x']],
                hashes: [],
            ),
            $this->writer,
            $this->registry,
            $this->diagnostics(),
            $this->panels(),
            new PostLock(),
            new WriteFailureNotice(),
        );

        $handler->handle($postId, get_post($postId), true);

        $taken = (new WriteFailureNotice())->take(get_current_user_id(), $postId);

        self::assertNotNull($taken, 'a refused save leaves one notice for the user who submitted the form');
        self::assertSame(self::GROUP, $taken->groupId);
        self::assertSame('nonce_failed', $taken->reason);
        self::assertNotNull($taken->reference);
        self::assertNull((new WriteFailureNotice())->take((int) get_current_user_id(), $postId), 'the notice is one-shot');

        // The key is the submitter's, not the post's: another user opening
        // the same post finds nothing to take.
        $other = (int) self::factory()->user->create(['role' => 'editor']);
        self::assertNull((new WriteFailureNotice())->take($other, $postId), 'the notice is the submitter\'s');
    }

    public function testAGroupNoPanelDeclaresIsRefusedAndTheDeclaredGroupsStillSave(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register($this->group());
        $this->registry->register($this->secondGroup());
        $postId = $this->postId();

        // Only the first group is declared for this post type; the second
        // arrives in the same submission.
        $handler = new FieldSaveHandler(
            new ArrayRequestInput(
                body: [Nonces::nonceField() => $this->nonce($postId)],
                groups: [
                    self::GROUP => ['fixture_text' => 'kept'],
                    self::SECOND_GROUP => ['fixture_second_text' => 'refused'],
                ],
                hashes: [self::GROUP => MirrorCodec::hash([])],
            ),
            $this->writer,
            $this->registry,
            $this->diagnostics(),
            new DeclaredPanels([new FieldPanel('post', $this->group())]),
            new PostLock(),
            new WriteFailureNotice(),
        );
        $handler->handle($postId, get_post($postId), true);

        self::assertSame('kept', $this->reader->value('fixture_text', ObjectRef::post($postId)), 'the declared groups of the submission still save');
        self::assertNull($this->reader->value('fixture_second_text', ObjectRef::post($postId)), 'a group no panel declares is never written');

        $taken = (new WriteFailureNotice())->take((int) get_current_user_id(), $postId);
        self::assertNotNull($taken);
        self::assertSame(self::SECOND_GROUP, $taken->groupId);
        self::assertSame('field_shape', $taken->reason);
    }

    public function testTheFirstRefusalStopsTheSubmission(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register($this->group());
        $this->registry->register($this->secondGroup());
        $this->registry->register($this->thirdGroup());
        $postId = $this->postId();

        // The middle group submits a field id its declaration does not name:
        // the shape refusal stops the submission, and the third group --
        // whose save would have succeeded -- is never reached.
        $handler = new FieldSaveHandler(
            new ArrayRequestInput(
                body: [Nonces::nonceField() => $this->nonce($postId)],
                groups: [
                    self::GROUP => ['fixture_text' => 'first'],
                    self::SECOND_GROUP => ['ghost_field' => 'refused'],
                    self::THIRD_GROUP => ['fixture_third_text' => 'never reached'],
                ],
                hashes: [self::GROUP => MirrorCodec::hash([])],
            ),
            $this->writer,
            $this->registry,
            $this->diagnostics(),
            new DeclaredPanels([
                new FieldPanel('post', $this->group()),
                new FieldPanel('post', $this->secondGroup()),
                new FieldPanel('post', $this->thirdGroup()),
            ]),
            new PostLock(),
            new WriteFailureNotice(),
        );
        $handler->handle($postId, get_post($postId), true);

        self::assertSame('first', $this->reader->value('fixture_text', ObjectRef::post($postId)));
        self::assertNull($this->reader->value('fixture_third_text', ObjectRef::post($postId)), 'no group writes after a refusal');

        $taken = (new WriteFailureNotice())->take((int) get_current_user_id(), $postId);
        self::assertNotNull($taken);
        self::assertSame(self::SECOND_GROUP, $taken->groupId, 'the first refusal is the one the notice carries');
    }

    public function testACorruptNoticePayloadIsRefusedNeverSurfaced(): void
    {
        // The transient was not written by queue(): the store refuses to
        // surface a shape it did not write, rather than render a notice with
        // substituted empty fields.
        \set_transient('mahout_fields_write_failed_1__5', ['group' => 'only'], \HOUR_IN_SECONDS);

        $this->expectException(MalformedNoticePayload::class);
        (new WriteFailureNotice())->take(1, 5);
    }

    public function testARefusedValueWritesNothingThroughTheRealWriter(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register($this->group());
        $postId = $this->postId();
        $handler = new FieldSaveHandler(
            new ArrayRequestInput(body: [Nonces::nonceField() => $this->nonce($postId)]),
            $this->writer,
            $this->registry,
            $this->diagnostics(),
            $this->panels(),
            new PostLock(),
            new WriteFailureNotice(),
        );

        $outcome = $handler->save(ObjectRef::post($postId), self::GROUP, ['fixture_text' => 'x', 'fixture_integer' => 'not-a-number'], $this->nonce($postId), MirrorCodec::hash([]));

        self::assertFalse($outcome->isStored());
        self::assertSame(SaveRefusalReason::Validation, $outcome->refusal->reason);
        self::assertNull($this->reader->value('fixture_text', ObjectRef::post($postId)), 'guard 8 beats guard 9: an invalid value never stores its siblings');
    }

    public function testAStaleHashThroughTheSharedPipelineRefusesAsConcurrent(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->registry->register($this->group());
        $postId = $this->postId();
        $handler = new FieldSaveHandler(
            new ArrayRequestInput(body: [Nonces::nonceField() => $this->nonce($postId)]),
            $this->writer,
            $this->registry,
            $this->diagnostics(),
            $this->panels(),
            new PostLock(),
            new WriteFailureNotice(),
        );

        $first = $handler->save(ObjectRef::post($postId), self::GROUP, ['fixture_text' => 'one'], $this->nonce($postId), MirrorCodec::hash([]));
        $second = $handler->save(ObjectRef::post($postId), self::GROUP, ['fixture_text' => 'two'], $this->nonce($postId), $first->hash.'stale');

        self::assertTrue($first->isStored());
        self::assertFalse($second->isStored());
        self::assertSame(SaveRefusalReason::ConcurrentEditLost, $second->refusal->reason);
        self::assertSame('one', $this->reader->value('fixture_text', ObjectRef::post($postId)), 'a lost update writes nothing and substitutes nothing');
    }

    public function testARefusedSaveRecordsExactlyOnceAtTheRefusingGuard(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $postId = $this->postId();
        // Lock AND a bad nonce: one record, for the lock, at warning.
        $holder = (int) self::factory()->user->create(['role' => 'editor']);
        update_post_meta($postId, '_edit_lock', (string) (time() + 60).':'.$holder);
        $handler = new FieldSaveHandler(
            new ArrayRequestInput(body: [Nonces::nonceField() => 'bad']),
            $this->writer,
            $this->registry,
            $diagnostics = $this->diagnostics(),
            $this->panels(),
            new PostLock(),
            new WriteFailureNotice(),
        );

        $outcome = $handler->save(ObjectRef::post($postId), self::GROUP, ['fixture_text' => 'x'], 'bad', '');

        self::assertSame(SaveRefusalReason::PostLocked, $outcome->refusal->reason);
        self::assertCount(1, $diagnostics->records());
        self::assertSame(Level::Warning, $diagnostics->records()[0]->level);
    }

    // ------------------------------------------------------------------

    private function group(): FieldGroup
    {
        return new FieldGroup(self::GROUP, ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table),
            new IntegerField('fixture_integer', StorageTarget::Table),
        ]);
    }

    private function secondGroup(): FieldGroup
    {
        return new FieldGroup(self::SECOND_GROUP, ObjectContext::Post, [
            new TextField('fixture_second_text', StorageTarget::Table),
        ]);
    }

    private function thirdGroup(): FieldGroup
    {
        return new FieldGroup(self::THIRD_GROUP, ObjectContext::Post, [
            new TextField('fixture_third_text', StorageTarget::Table),
        ]);
    }

    private function panels(): DeclaredPanels
    {
        return new DeclaredPanels([new FieldPanel('post', $this->group())]);
    }

    private function nonce(int $postId): string
    {
        return wp_create_nonce(Nonces::action($postId));
    }

    /** @return array{0: FieldSaveHandler, 1: RecordingWriter, 2: \Iniznet\Mahout\Kernel\Diagnostics} */
    private function spyHandler(): array
    {
        $this->registry->register($this->group());

        $writer = new RecordingWriter();
        $diagnostics = $this->diagnostics();

        return [new FieldSaveHandler(
            new ArrayRequestInput(body: []),
            $writer,
            $this->registry,
            $diagnostics,
            $this->panels(),
            new PostLock(),
            new WriteFailureNotice(),
        ), $writer, $diagnostics];
    }
}
