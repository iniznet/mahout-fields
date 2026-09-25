<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidPanelDeclaration;

/**
 * One section of an option screen's tab: a titled block that is either one
 * group's fields -- inside the save lifecycle the metaboxes use -- or a
 * markup file the declaring feature ships. Exactly one of the two: the named
 * constructors are the only way in, so a section that is both or neither
 * cannot be built, and no guard on the screen has to guess a section's kind.
 *
 * An untitled section renders no heading; a titled one renders it as the
 * block's heading. A content section's markup path is checked at
 * declaration: the file is part of the codebase, not a runtime condition, so
 * a missing file is a broken declaration and fails where the declaration is
 * written rather than rendering an empty block on the page.
 */
final readonly class OptionSection
{
    private function __construct(
        public string $title,
        public ?FieldGroup $group,
        public ?string $markupPath,
    ) {
    }

    /**
     * A section that renders one group's fields through the editor, inside
     * the save lifecycle. The group's context is the screen's to verify: a
     * section carries no screen, so it cannot know what address its values
     * would be read by.
     */
    public static function fields(string $title, FieldGroup $group): self
    {
        return new self($title, $group, null);
    }

    /**
     * A section that renders a markup file the declaring feature ships. The
     * path is absolute, built from the declaring feature's own __DIR__ -- the
     * same way a component names its markup -- and must exist.
     *
     * @throws InvalidPanelDeclaration when the path is empty or names no file
     */
    public static function content(string $title, string $markupPath): self
    {
        if ('' === $markupPath) {
            throw InvalidPanelDeclaration::emptySectionMarkup('');
        }

        if (!\is_file($markupPath)) {
            throw InvalidPanelDeclaration::missingSectionMarkup($markupPath);
        }

        return new self($title, null, $markupPath);
    }
}
