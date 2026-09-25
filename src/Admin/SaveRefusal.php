<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Exception\AuthorizationDenied;
use Iniznet\Mahout\Fields\Exception\ConcurrentEditLost;
use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Exception\InvalidFilterResult;
use Iniznet\Mahout\Fields\Exception\InvalidRepeaterPayload;
use Iniznet\Mahout\Fields\Exception\NonceFailed;
use Iniznet\Mahout\Fields\Exception\PostLockedForWrite;
use Iniznet\Mahout\Fields\Exception\RepeaterTooLarge;
use Iniznet\Mahout\Kernel\Diagnostics;
use Iniznet\Mahout\Kernel\Level;

/**
 * One save refusal: the reason, where it happened, and the support reference
 * the diagnostics record carries. A refusal is a value, not an exception
 * thrown across a boundary, so the classic save_post path and the REST route
 * share one pipeline and the record is never duplicated.
 */
final readonly class SaveRefusal
{
    private function __construct(
        public SaveRefusalReason $reason,
        public string $groupId,
        public int $objectId,
        public string $reference,
    ) {
    }

    /** Record the refusal once -- one condition, one record -- and return it. */
    public static function record(\Throwable $refusal, string $groupId, int $objectId, Diagnostics $diagnostics): self
    {
        [$reason, $level] = self::classify($refusal);

        $reference = $diagnostics->log(
            $level,
            'field write refused',
            ['reason' => $reason->value, 'group' => $groupId, 'object' => $objectId, 'condition' => $refusal::class],
        );

        return new self($reason, $groupId, $objectId, $reference);
    }

    public function reason(): SaveRefusalReason
    {
        return $this->reason;
    }

    /** @return array{0: SaveRefusalReason, 1: Level} */
    private static function classify(\Throwable $refusal): array
    {
        if ($refusal instanceof PostLockedForWrite) {
            return [SaveRefusalReason::PostLocked, Level::Warning];
        }

        if ($refusal instanceof NonceFailed) {
            return [SaveRefusalReason::NonceFailed, Level::Warning];
        }

        if ($refusal instanceof AuthorizationDenied) {
            return [SaveRefusalReason::AuthorizationDenied, Level::Warning];
        }

        if ($refusal instanceof ConcurrentEditLost) {
            return [SaveRefusalReason::ConcurrentEditLost, Level::Warning];
        }

        if ($refusal instanceof FieldNotFound || $refusal instanceof InvalidFieldContext) {
            return [SaveRefusalReason::FieldShape, Level::Warning];
        }

        if (
            $refusal instanceof InvalidFieldValue
            || $refusal instanceof InvalidFilterResult
            || $refusal instanceof InvalidFieldWrite
            || $refusal instanceof InvalidRepeaterPayload
            || $refusal instanceof RepeaterTooLarge
        ) {
            return [SaveRefusalReason::Validation, Level::Warning];
        }

        return [SaveRefusalReason::WriteFailed, Level::Error];
    }
}
