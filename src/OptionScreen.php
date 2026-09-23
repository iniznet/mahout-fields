<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidPanelDeclaration;

/**
 * One declared option screen: a field group bound to the settings page that
 * renders it. The group carries no screen, so the page is the declaration's
 * own fact: the slug the page is reached by, the titles it is labelled with,
 * the capability it is reached by and the admin menu it sits under.
 *
 * A screen is a value: it holds no collaborator and resolves none. The host
 * collects these into its `Contracts\OptionScreens` implementation, and
 * Admin\OptionScreenManager derives the settings page, its save entry and its
 * notice from that collection.
 *
 * Only an option-context group may name a screen. The option context has no
 * object — its values are singletons read by key — so a post, user or term
 * group on a settings page would address an object that does not exist there;
 * the declaration is refused, never coerced into a renderable page. The same
 * holds for an empty slug, title, menu title, capability or parent menu: each
 * is the declaration of a page that cannot be built or cannot be reached.
 */
final readonly class OptionScreen
{
    /**
     * Each string piece is refused when empty, so a screen with an empty
     * declaration piece cannot exist; the guards below are the invariant's
     * enforcement, and the refusals are the documented one way to fail.
     *
     * @throws InvalidPanelDeclaration when the slug, a title, the capability or
     *                                 the parent menu is empty, or the group is
     *                                 not declared for the option context
     */
    public function __construct(
        public string $pageSlug,
        public string $pageTitle,
        public string $menuTitle,
        public FieldGroup $group,
        public string $capability,
        public string $menuParent = 'options-general.php',
    ) {
        if ('' === $pageSlug) {
            throw InvalidPanelDeclaration::emptyPageSlug($group->id);
        }

        if ('' === $pageTitle) {
            throw InvalidPanelDeclaration::emptyPageTitle($group->id);
        }

        if ('' === $menuTitle) {
            throw InvalidPanelDeclaration::emptyMenuTitle($group->id);
        }

        if ('' === $capability) {
            throw InvalidPanelDeclaration::emptyCapability($group->id);
        }

        if ('' === $menuParent) {
            throw InvalidPanelDeclaration::emptyMenuParent($group->id);
        }

        if (ObjectContext::Option !== $group->context) {
            throw InvalidPanelDeclaration::notOptionContext($group->id, $group->context->value);
        }
    }
}
