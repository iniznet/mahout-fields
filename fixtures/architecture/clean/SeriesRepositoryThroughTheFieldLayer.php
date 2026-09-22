<?php

declare(strict_types=1);

namespace Iniznet\Consumer\Features\Series;

use Iniznet\Mahout\Fields\Contracts\FieldReader;

/**
 * The clean twin of the violation fixture: the same read, through the field
 * layer, with the reader arriving through the constructor. No meta call
 * appears, so no meta-access rule may fire.
 */
final class SeriesRepository
{
    public function __construct(private readonly FieldReader $fields)
    {
    }

    public function isbn(int $postId): string
    {
        return (string) $this->fields->value('isbn', \Iniznet\Mahout\Fields\ObjectRef::post($postId));
    }
}
