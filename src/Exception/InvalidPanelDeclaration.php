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

    /** An option screen declares neither its own group nor tabs: a page that renders nothing. */
    public static function screenWithoutContent(string $identifier): self
    {
        return new self(
            sprintf('The option screen "%s" declares neither a group nor tabs; a page that renders nothing is not a screen.', $identifier),
            $identifier,
        );
    }

    /** An option screen declares its own group and tabs: the group belongs in a tab's section, one way. */
    public static function groupInsideTabs(string $groupId): self
    {
        return new self(
            sprintf('The option screen for group "%s" declares a group and tabs; declare the group inside a tab\'s section.', $groupId),
            $groupId,
        );
    }

    public static function emptyTabLabel(string $identifier): self
    {
        return new self(
            sprintf('The option screen "%s" declares a tab with no label; the label is the tab\'s key in the URL.', $identifier),
            $identifier,
        );
    }

    public static function emptyTabSections(string $tabLabel): self
    {
        return new self(
            sprintf('The option tab "%s" declares no section; an empty tab is a page that renders nothing.', $tabLabel),
            $tabLabel,
        );
    }

    public static function emptySectionMarkup(string $sectionTitle): self
    {
        return new self(
            sprintf('The option section "%s" names no markup file.', $sectionTitle),
            $sectionTitle,
        );
    }

    public static function missingSectionMarkup(string $markupPath): self
    {
        return new self(
            sprintf('The option section\'s markup file "%s" does not exist; the markup is part of the codebase.', $markupPath),
            $markupPath,
        );
    }

    /** A tab's field section declares a group that is not option-context: the save would address an object that does not exist there. */
    public static function tabGroupNotOptionContext(string $groupId, string $declared, string $tabLabel): self
    {
        return new self(
            sprintf(
                'The option tab "%s" declares the group "%s" in the %s context; an option screen serves the option context.',
                $tabLabel,
                $groupId,
                $declared,
            ),
            $groupId,
        );
    }

    /** A top-level menu registers no icon: the admin bar renders a broken image instead. */
    public static function emptyMenuIcon(string $identifier): self
    {
        return new self(
            sprintf('The option screen "%s" registers a top-level menu with no icon; a top-level menu declares its own icon.', $identifier),
            $identifier,
        );
    }

    /** A top-level screen declares no parent: the two menu placements are one declaration's facts, never mixed. */
    public static function topLevelWithParent(string $identifier, string $parent): self
    {
        return new self(
            sprintf('The option screen "%s" registers a top-level menu and names the parent "%s"; a top-level screen declares no parent.', $identifier, $parent),
            $identifier,
        );
    }

    public function groupId(): string
    {
        return $this->groupId;
    }
}
