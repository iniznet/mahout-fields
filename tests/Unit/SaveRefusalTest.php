<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Admin\SaveRefusal;
use Iniznet\Mahout\Fields\Admin\SaveRefusalReason;
use Iniznet\Mahout\Fields\Exception\AuthorizationDenied;
use Iniznet\Mahout\Fields\Exception\ConcurrentEditLost;
use Iniznet\Mahout\Fields\Exception\NonceFailed;
use Iniznet\Mahout\Fields\Exception\PostLockedForWrite;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Kernel\Level;

/**
 * The refusal classification: every package refusal maps to its reason and
 * its level, the record is made exactly once per refusal, and the reference
 * it carries is the record's.
 *
 * @internal
 */
final class SaveRefusalTest extends TestCase
{
    public function testAGuardRefusalClassifiesAtWarning(): void
    {
        $cases = [
            [PostLockedForWrite::forObject(5, 9), SaveRefusalReason::PostLocked, Level::Warning],
            [AuthorizationDenied::forObject(5), SaveRefusalReason::AuthorizationDenied, Level::Warning],
            [NonceFailed::forObject(5), SaveRefusalReason::NonceFailed, Level::Warning],
            [ConcurrentEditLost::forGroup('g', 5), SaveRefusalReason::ConcurrentEditLost, Level::Warning],
        ];

        foreach ($cases as [$refusal, $reason, $level]) {
            $diagnostics = $this->diagnostics();
            $value = SaveRefusal::record($refusal, 'fixture_group', 5, $diagnostics);

            self::assertSame($reason, $value->reason, $refusal::class);
            self::assertSame($level, $diagnostics->records()[0]->level ?? null, $refusal::class);
            self::assertSame($value->reference, $diagnostics->records()[0]->reference ?? null, 'the refusal carries the record\'s reference');
            self::assertCount(1, $diagnostics->records(), 'one condition, one record');
        }
    }

    public function testAnInvalidValueRefusesAsValidation(): void
    {
        $diagnostics = $this->diagnostics();

        $value = SaveRefusal::record(\Iniznet\Mahout\Fields\Exception\InvalidFieldValue::notNumeric('fixture_field', 'integer', 'x'), 'fixture_group', 5, $diagnostics);

        self::assertSame(SaveRefusalReason::Validation, $value->reason);
        self::assertSame(Level::Warning, $diagnostics->records()[0]->level);
    }

    public function testAnUnexpectedConditionRefusesAsWriteFailedAtError(): void
    {
        $diagnostics = $this->diagnostics();

        $value = SaveRefusal::record(new \RuntimeException('unexpected'), 'fixture_group', 5, $diagnostics);

        self::assertSame(SaveRefusalReason::WriteFailed, $value->reason);
        self::assertSame(Level::Error, $diagnostics->records()[0]->level);
    }
}
