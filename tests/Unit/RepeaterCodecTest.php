<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Exception\InvalidRepeaterPayload;
use Iniznet\Mahout\Fields\Exception\RepeaterTooLarge;
use Iniznet\Mahout\Fields\RepeaterCodec;
use Iniznet\Mahout\Fields\Tests\Fixtures\CreditData;
use Iniznet\Mahout\Fields\Tests\Fixtures\CreditDataRenamed;
use Iniznet\Mahout\Fields\Tests\TestCase;

/**
 * Deliverable 6, plus the codec's own invariants.
 *
 * The codec encodes the STORAGE SHAPE and never the DTO: the fixture pair
 * proves that a DTO rename -- two properties renamed, mapping unchanged --
 * produces byte-identical stored JSON.
 *
 * @internal
 */
final class RepeaterCodecTest extends TestCase
{
    public function testTheEncodedEnvelopeIsVersioned(): void
    {
        $encoded = RepeaterCodec::encode(['author']);

        self::assertSame('{"v":1,"items":["author"]}', $encoded);
    }

    public function testARecordRoundTripsThroughTheStorageShape(): void
    {
        $items = [
            ['role' => 'author', 'name' => 'Ursula K. Le Guin'],
            ['role' => 'editor', 'name' => 'Terry Carr'],
        ];

        $encoded = RepeaterCodec::encode($items);

        self::assertSame($items, RepeaterCodec::decode($encoded));
    }

    public function testAReorderedRecordKeepsItsKeys(): void
    {
        $encoded = RepeaterCodec::encode([['name' => 'B', 'role' => 'A']]);

        self::assertSame([['name' => 'B', 'role' => 'A']], RepeaterCodec::decode($encoded));
    }

    public function testADtoRenameChangesNoStoredBytes(): void
    {
        $before = new CreditData('author', 'Ursula K. Le Guin');
        $after = new CreditDataRenamed('author', 'Ursula K. Le Guin');

        $storedBefore = RepeaterCodec::encode([$before->toStorage()]);
        $storedAfter = RepeaterCodec::encode([$after->toStorage()]);

        self::assertSame($storedBefore, $storedAfter);
    }

    public function testAPayloadOverTheByteCapIsRefusedAtEncodeTime(): void
    {
        try {
            RepeaterCodec::encode([str_repeat('a', RepeaterCodec::MAX_BYTES)]);
            self::fail('An oversized payload must be refused.');
        } catch (RepeaterTooLarge $tooLarge) {
            self::assertGreaterThan(RepeaterCodec::MAX_BYTES, $tooLarge->size());
            self::assertSame(RepeaterCodec::MAX_BYTES, $tooLarge->cap());
        }
    }

    public function testASizeJustUnderTheCapIsAccepted(): void
    {
        $encoded = RepeaterCodec::encode([str_repeat('a', RepeaterCodec::MAX_BYTES - 20)]);

        self::assertLessThanOrEqual(RepeaterCodec::MAX_BYTES, strlen($encoded));
    }

    public function testAMalformedPayloadIsRefusedWithTheEnvelopeShape(): void
    {
        try {
            RepeaterCodec::decode('{not json');
            self::fail('A malformed payload must be refused.');
        } catch (InvalidRepeaterPayload $payload) {
            self::assertSame('malformed', $payload->reason());
        }
    }

    public function testAnUnversionedPayloadIsRefused(): void
    {
        $this->expectException(InvalidRepeaterPayload::class);
        RepeaterCodec::decode('{"items":["author"]}');
    }

    public function testAVersionThisPackageCannotReadIsRefused(): void
    {
        try {
            RepeaterCodec::decode('{"v":2,"items":["author"]}');
            self::fail('An unsupported version must be refused.');
        } catch (InvalidRepeaterPayload $payload) {
            self::assertSame('version', $payload->reason());
        }
    }

    public function testACompoundItemIsRefused(): void
    {
        $this->expectException(InvalidRepeaterPayload::class);
        RepeaterCodec::decode('{"v":1,"items":[["nested"]]}');
    }

    public function testARecordWithANonScalarValueIsRefused(): void
    {
        $this->expectException(InvalidRepeaterPayload::class);
        RepeaterCodec::assertPayload('{"v":1,"items":[{"role":{"deep":true}}]}');
    }

    public function testAssertPayloadAcceptsTheEnvelopeWithoutReencoding(): void
    {
        RepeaterCodec::assertPayload('{"v":1,"items":[{"role":"author"}]}');

        self::assertTrue(true);
    }
}
