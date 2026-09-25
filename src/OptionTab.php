<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidPanelDeclaration;

/**
 * One tab of an option screen: a labelled, ordered set of sections. A tab is
 * a page's own navigation fact, and the label is the tab's key in the URL --
 * what the manager links to and what the request names back -- so an
 * unlabelled tab is a page that cannot be reached.
 *
 * A tab carries no screen and resolves no collaborator; the screen verifies
 * the sections' group contexts, because only the screen knows the address
 * its save writes to.
 */
final readonly class OptionTab
{
    /**
     * @param list<OptionSection> $sections
     *
     * @throws InvalidPanelDeclaration when the label is empty or the tab
     *                                 declares no section
     */
    public function __construct(
        public string $label,
        public array $sections,
    ) {
        if ('' === $label) {
            throw InvalidPanelDeclaration::emptyTabLabel('');
        }

        if ([] === $sections) {
            throw InvalidPanelDeclaration::emptyTabSections($label);
        }
    }
}
