<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Exception\InvalidMirrorPayload;
use Iniznet\Mahout\Fields\MirrorCodec;
use Iniznet\Mahout\Fields\Tests\TestCase;

/**
 * The revision mirror codec: round-trip through the versioned payload, the
 * hash's identity, and every loud refusal of a payload that is not this
 * shape.
 *
 * @internal
 */
final class MirrorCodecTest extends TestCase
{
    public function testTheEncodedEnvelopeIsVersionedTwiceOver(): void
    {
        $encoded = MirrorCodec::encode([
            ['field' => 'fixture_text', 'value' => 'The Dispossessed'],
        ]);

        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $decoded['v']);
        self::assertSame(1, $decoded['schema']);
        self::assertArrayHasKey('hash', $decoded);
        self::assertArrayHasKey('rows', $decoded);
    }

    public function testARowSetRoundTripsWithItsHash(): void
    {
        $rows = [
            ['field' => 'fixture_text', 'value' => 'The Left Hand of Darkness'],
            ['field' => 'fixture_integer', 'value' => 42],
            ['field' => 'fixture_items', 'items' => ['paperback', 'hardcover']],
        ];

        $payload = MirrorCodec::decode('fixture_group', MirrorCodec::encode($rows));

        self::assertSame(MirrorCodec::hash($rows), $payload['hash']);
        self::assertSame($rows, $payload['rows']);
    }

    public function testTheEmptyRowSetHasItsOwnHash(): void
    {
        self::assertSame(hash('sha256', '[]'), MirrorCodec::hash([]));
        self::assertSame(MirrorCodec::hash([]), MirrorCodec::decode('fixture_group', MirrorCodec::encode([]))['hash']);
    }

    public function testTheHashIsStableAcrossEncodeDecode(): void
    {
        $rows = [['field' => 'fixture_text', 'value' => 'genly']];
        $encoded = MirrorCodec::encode($rows);

        self::assertSame(MirrorCodec::hash($rows), MirrorCodec::decode('fixture_group', $encoded)['hash']);
        self::assertSame(MirrorCodec::hash($rows), MirrorCodec::hash([['field' => 'fixture_text', 'value' => 'genly']]));
    }

    public function testAMalformedPayloadIsRefusedNotSubstituted(): void
    {
        $this->expectException(InvalidMirrorPayload::class);
        MirrorCodec::decode('fixture_group', '{"v":1,');
    }

    public function testANonEnvelopePayloadIsRefused(): void
    {
        $this->expectException(InvalidMirrorPayload::class);
        MirrorCodec::decode('fixture_group', '"a bare string"');
    }

    public function testAnUnsupportedVersionIsRefused(): void
    {
        $this->expectException(InvalidMirrorPayload::class);
        MirrorCodec::decode('fixture_group', '{"v":2,"schema":1,"hash":"ab","rows":[]}');
    }

    public function testARowSetThatIsNotAListIsRefused(): void
    {
        $this->expectException(InvalidMirrorPayload::class);
        MirrorCodec::decode('fixture_group', '{"v":1,"schema":1,"hash":"ab","rows":{"0":"x"}}');
    }

    public function testAMalformedRowIsRefusedNotSkipped(): void
    {
        $this->expectException(InvalidMirrorPayload::class);
        MirrorCodec::decode('fixture_group', '{"v":1,"schema":1,"hash":"ab","rows":[{"field":5}]}');
    }

    public function testARowCarryingNeitherValueNorItemsIsRefused(): void
    {
        $this->expectException(InvalidMirrorPayload::class);
        MirrorCodec::decode('fixture_group', '{"v":1,"schema":1,"hash":"ab","rows":[{"field":"fixture_text"}]}');
    }

    public function testAnItemsRowCarryingANonScalarItemIsRefused(): void
    {
        $this->expectException(InvalidMirrorPayload::class);
        MirrorCodec::decode('fixture_group', '{"v":1,"schema":1,"hash":"ab","rows":[{"field":"fixture_items","items":[{"nested":true}]}]}');
    }

    public function testEveryRefusalNamesTheGroup(): void
    {
        try {
            MirrorCodec::decode('fixture_group', 'not json at all');
            self::fail('a malformed payload must be refused');
        } catch (InvalidMirrorPayload $refusal) {
            self::assertSame('fixture_group', $refusal->groupId());
            self::assertNotSame('', $refusal->reason());
        }
    }
}
