<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * An admin surface declaration is malformed. A field panel pairs a group with
 * the post type whose edit screen renders it, and a pairing with no post type
 * is not a panel — it would register a metabox on every screen or on none. An
 * option screen pairs a group with the settings page that renders it, and the
 * same holds: no slug, no title, no capability, a missing parent menu or a
 * group that is not option-context is not a screen — it would register a page
 * that renders nothing or that addresses objects that do not exist there.
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

    public static function emptyPageSlug(string $groupId): self
    {
        return new self(sprintf('The option screen for group "%s" names no page slug.', $groupId), $groupId);
    }

    public static function emptyPageTitle(string $groupId): self
    {
        return new self(sprintf('The option screen for group "%s" names no page title.', $groupId), $groupId);
    }

    public static function emptyMenuTitle(string $groupId): self
    {
        return new self(sprintf('The option screen for group "%s" names no menu title.', $groupId), $groupId);
    }

    public static function emptyCapability(string $groupId): self
    {
        return new self(sprintf('The option screen for group "%s" names no capability.', $groupId), $groupId);
    }

    public static function emptyMenuParent(string $groupId): self
    {
        return new self(sprintf('The option screen for group "%s" names no parent menu.', $groupId), $groupId);
    }

    public static function notOptionContext(string $groupId, string $declared): self
    {
        return new self(
            sprintf('The option screen for group "%s" declares the %s context; an option screen serves the option context.', $groupId, $declared),
            $groupId,
        );
    }

    public function groupId(): string
    {
        return $this->groupId;
    }
}
