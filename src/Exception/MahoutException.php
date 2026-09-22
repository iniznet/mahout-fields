<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * The package marker: every exception mahout-fields throws implements it, so a
 * consumer can catch the package's failures without catching anyone else's.
 */
interface MahoutException extends \Throwable
{
}
