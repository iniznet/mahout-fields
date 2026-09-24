<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * The package's stylesheet cannot be given a URL because the package is not
 * installed under wp-content. A stylesheet whose URL cannot be derived is a
 * composition error, not a degraded screen: the default styling would
 * silently vanish, and law 3 forbids silent fallback.
 */
final class UnresolvableFieldStyles extends \RuntimeException implements MahoutException
{
    public static function outsideContent(string $file): self
    {
        return new self(sprintf(
            'The field stylesheet at "%s" is outside wp-content; its URL cannot be resolved. Install the package inside wp-content, or bind %s with styled() === false and ship your own styling.',
            $file,
            'Contracts\FieldUiPolicy',
        ));
    }
}
