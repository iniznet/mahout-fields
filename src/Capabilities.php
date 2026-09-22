<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * The package's capability constants. A capability check names one of these,
 * never a literal at the check site.
 */
enum Capabilities: string
{
    /** The object-ID meta capability a field write is authorised by. */
    case EditPost = 'edit_post';
}
