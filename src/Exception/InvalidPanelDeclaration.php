<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A field panel declaration is malformed. A panel pairs a group with the post
 * type whose edit screen renders it, and a pairing with no post type is not a
 * panel — it would register a metabox on every screen or on none.
 */
final class InvalidPanelDeclaration extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $groupId,
    ) {
        parent::__construct($message);
    }

    public static function emptyPostType(string $groupId): self
    {
        return new self(sprintf('The panel for group "%s" names no post type.', $groupId), $groupId);
    }

    public function groupId(): string
    {
        return $this->groupId;
    }
}
