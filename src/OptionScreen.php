<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidPanelDeclaration;

/**
 * One declared option screen: the settings page a tabbed set of sections
 * renders. The page is the declaration's own fact: the slug the page is
 * reached by, the titles it is labelled with, the capability it is reached
 * by and the admin menu it sits under -- plus, since tabs, the page's own
 * structure: an intro line, and tabs of sections, each section either one
 * group's fields or a markup file the declaring feature ships.
 *
 * A screen is a value: it holds no collaborator and resolves none. The host
 * collects these into its `Contracts\OptionScreens` implementation, and
 * Admin\OptionScreenManager derives the settings page, its save entry and
 * its notice from that collection.
 *
 * A screen declares its content one way: either its own group -- the shape
 * the page took before tabs, and still the one-line declaration for a plain
 * field page -- or tabs, never both. The declared group is normalised into
 * the tab list at construction, so a consumer reads `$screen->tabs` and
 * always sees the canonical shape. A screen whose tabs carry no field group
 * at all renders no form: a documentation or guide page is a screen like
 * any other, and a screen with no group and no tabs is refused, so no page
 * is reachable empty.
 *
 * Only an option-context group may name a screen or sit in a tab's field
 * section. The option context has no object -- its values are singletons
 * read by key -- so a post, user or term group on a settings page would
 * address an object that does not exist there; the declaration is refused,
 * never coerced into a renderable page. The same holds for an empty slug,
 * title, menu title, capability or parent menu: each is the declaration of
 * a page that cannot be built or cannot be reached.
 */
final readonly class OptionScreen
{
    /**
     * The canonical tab list, the declared group normalised into it.
     *
     * @var list<OptionTab>
     */
    public array $tabs;

    /**
     * @param list<OptionTab> $tabs
     *
     * @throws InvalidPanelDeclaration when the slug, a title, the capability or
     *                                 the parent menu is empty, the screen
     *                                 declares its content two ways or none,
     *                                 a tab is unlabelled or empty, or a
     *                                 declared group is not for the option
     *                                 context
     */
    public function __construct(
        public string $pageSlug,
        public string $pageTitle,
        public string $menuTitle,
        public ?FieldGroup $group,
        public string $capability,
        public string $menuParent = 'options-general.php',
        public string $description = '',
        array $tabs = [],
        public OptionScreenLayout $layout = OptionScreenLayout::Tabs,
        public bool $topLevel = false,
        public string $menuIcon = '',
    ) {
        $identifier = null === $group ? $pageSlug : $group->id;

        if ('' === $pageSlug) {
            throw InvalidPanelDeclaration::emptyPageSlug($identifier);
        }

        if ('' === $pageTitle) {
            throw InvalidPanelDeclaration::emptyPageTitle($identifier);
        }

        if ('' === $menuTitle) {
            throw InvalidPanelDeclaration::emptyMenuTitle($identifier);
        }

        if ('' === $capability) {
            throw InvalidPanelDeclaration::emptyCapability($identifier);
        }

        if (!$this->topLevel && '' === $menuParent) {
            throw InvalidPanelDeclaration::emptyMenuParent($identifier);
        }

        if ($this->topLevel && '' === $menuIcon) {
            throw InvalidPanelDeclaration::emptyMenuIcon($identifier);
        }

        if ($this->topLevel && '' !== $menuParent) {
            throw InvalidPanelDeclaration::topLevelWithParent($identifier, $menuParent);
        }

        if (null !== $group && [] !== $tabs) {
            throw InvalidPanelDeclaration::groupInsideTabs($group->id);
        }

        if (null === $group && [] === $tabs) {
            throw InvalidPanelDeclaration::screenWithoutContent($pageSlug);
        }

        if (null !== $group) {
            $this->assertOptionContext($group, $this->pageTitle);
            $tabs = [new OptionTab($this->pageTitle, [OptionSection::fields($group->label ?? '', $group)])];
        }

        foreach ($tabs as $tab) {
            foreach ($tab->sections as $section) {
                if (null !== $section->group) {
                    $this->assertOptionContext($section->group, $tab->label);
                }
            }
        }

        $this->tabs = $tabs;
    }

    /**
     * Whether the screen renders fields at all: a screen whose tabs carry
     * only content sections renders no form, and the manager registers no
     * save entry for it.
     */
    public function hasFields(): bool
    {
        return [] !== $this->fieldGroups();
    }

    /**
     * Every field group the screen's tabs declare, in declaration order. The
     * save entry and the form's shape both walk this one list, so a screen
     * with several field sections is saved group by group in the order its
     * sections render.
     *
     * @return list<FieldGroup>
     */
    public function fieldGroups(): array
    {
        $groups = [];

        foreach ($this->tabs as $tab) {
            foreach ($tab->sections as $section) {
                if (null !== $section->group) {
                    $groups[] = $section->group;
                }
            }
        }

        return $groups;
    }

    private function assertOptionContext(FieldGroup $group, string $tabLabel): void
    {
        if (ObjectContext::Option !== $group->context) {
            throw InvalidPanelDeclaration::tabGroupNotOptionContext($group->id, $group->context->value, $tabLabel);
        }
    }
}
