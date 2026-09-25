<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\Exception\ConcurrentEditLost;
use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\MirrorCodec;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\PersonalData;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * Deliverable 2: the group write is the store step of the save lifecycle.
 * The guard reads the mirror AFTER the transaction opens and BEFORE the
 * first write; a stale hash raises ConcurrentEditLost and nothing is
 * written, substituted or retried; a matching hash writes and the mirror
 * records the new hash -- the negative proof is a passing write.
 *
 * @internal
 */
final class GroupWriteTest extends TestCase
{
    public function testAFirstGroupWriteCarriesTheEmptyHashAndSeedsTheMirror(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());

        $newHash = $this->writer->writeGroup('fixture_group', ObjectRef::post($postId), [
            'fixture_text' => 'first',
            'fixture_integer' => 1,
        ], MirrorCodec::hash([]));

        self::assertSame('first', $this->reader->value('fixture_text', ObjectRef::post($postId)));
        self::assertSame($newHash, $this->mirror->currentHash(ObjectRef::post($postId), 'fixture_group'));
        self::assertNotSame(MirrorCodec::hash([]), $newHash, 'a group with a stored row must not hash to the empty set');
    }

    public function testAValueThatIsNeitherScalarArrayNorNullIsRefused(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());

        // An object would otherwise fall through the shape guards into the
        // store step, where the scalar coercion would silently delete the
        // stored value: the write is refused before anything runs.
        $this->expectException(InvalidFieldWrite::class);
        $this->writer->writeGroup('fixture_group', ObjectRef::post($postId), [
            'fixture_text' => new \stdClass(),
        ], MirrorCodec::hash([]));
    }

    public function testASecondWriteWithTheCurrentHashPasses(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());
        $object = ObjectRef::post($postId);

        $first = $this->writer->writeGroup('fixture_group', $object, ['fixture_text' => 'first'], MirrorCodec::hash([]));
        $second = $this->writer->writeGroup('fixture_group', $object, ['fixture_text' => 'second'], $first);

        self::assertSame('second', $this->reader->value('fixture_text', $object));
        self::assertSame($second, $this->mirror->currentHash($object, 'fixture_group'));
    }

    public function testAStaleHashRefusesAndWritesNothing(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());
        $object = ObjectRef::post($postId);

        $first = $this->writer->writeGroup('fixture_group', $object, ['fixture_text' => 'first', 'fixture_integer' => 1], MirrorCodec::hash([]));

        try {
            $this->writer->writeGroup('fixture_group', $object, ['fixture_text' => 'second', 'fixture_integer' => 2], $first.'stale');
            self::fail('a stale hash must be refused');
        } catch (ConcurrentEditLost $refusal) {
            self::assertSame('fixture_group', $refusal->groupId());
            self::assertSame($postId, $refusal->objectId());
        }

        // Nothing was written, substituted or retried: the tables hold the
        // first write's values, and the mirror still carries the first hash.
        self::assertSame('first', $this->reader->value('fixture_text', $object));
        self::assertSame(1, $this->reader->value('fixture_integer', $object));
        self::assertSame($first, $this->mirror->currentHash($object, 'fixture_group'));
    }

    public function testARefusedWriteLeavesNoMirrorBehindForANewGroup(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());
        $object = ObjectRef::post($postId);

        try {
            $this->writer->writeGroup('fixture_group', $object, ['fixture_text' => 'x'], 'never the current hash');
            self::fail('the empty hash of a group that carries no mirror is hash([]), not a coined string');
        } catch (ConcurrentEditLost) {
        }

        self::assertNull($this->mirror->payloadOf($object, 'fixture_group'), 'a refused first write must seed nothing');
        self::assertNull($this->rawValueRow('fixture_text', $postId));
    }

    public function testAGroupWithNoTableBoundFieldWritesWithoutAMirror(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->metaGroup());
        $object = ObjectRef::post($postId);

        $hash = $this->writer->writeGroup('fixture_meta', $object, ['fixture_text' => 'through meta'], MirrorCodec::hash([]));

        self::assertSame(MirrorCodec::hash([]), $hash, 'a group with no table row hashes to the empty set');
        self::assertSame('through meta', $this->reader->value('fixture_text', $object));
        self::assertNull($this->mirror->payloadOf($object, 'fixture_meta'));
    }

    public function testAUserContextGroupBindsNoMirror(): void
    {
        $userId = $this->userId();
        $this->registry->register($this->userGroup());
        $object = ObjectRef::user($userId);

        $hash = $this->writer->writeGroup('fixture_user', $object, ['fixture_text' => 'user level'], MirrorCodec::hash([]));

        self::assertSame(MirrorCodec::hash([]), $hash);
        self::assertSame('user level', $this->reader->value('fixture_text', $object));
        self::assertNull($this->mirror->payloadOf($object, 'fixture_user'), 'revisions are post-only; a user group binds no mirror');
    }

    public function testASubmittedFieldIdOutsideTheDeclarationIsRefused(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());

        $this->expectException(FieldNotFound::class);
        $this->writer->writeGroup('fixture_group', ObjectRef::post($postId), [
            'fixture_smuggled' => 'mass assignment',
        ], MirrorCodec::hash([]));
    }

    private function tableGroup(): FieldGroup
    {
        return new FieldGroup('fixture_group', ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table),
            new IntegerField('fixture_integer', StorageTarget::Table),
        ]);
    }

    private function metaGroup(): FieldGroup
    {
        return new FieldGroup('fixture_meta', ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Meta),
        ]);
    }

    private function userGroup(): FieldGroup
    {
        return new FieldGroup('fixture_user', ObjectContext::User, [
            new TextField('fixture_text', StorageTarget::Table, PersonalData::notPersonal('the test group carries no user data')),
        ]);
    }
}
