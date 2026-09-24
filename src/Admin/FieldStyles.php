<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Contracts\OptionScreens;
use Iniznet\Mahout\Fields\Contracts\Panels;
use Iniznet\Mahout\Fields\Exception\UnresolvableFieldStyles;

/**
 * The default stylesheet's enqueue decision, one listener for every screen
 * that renders field UI. Eligibility is derived, never listed twice: a panel's
 * post type edit screen qualifies through the declared panels, an option
 * screen's page through its declaration's slug and parent, the same way
 * OptionScreenManager derives the page hook it registered. An unrelated
 * admin screen enqueues nothing.
 *
 * The listener is attached only when a host has declared field UI and has not
 * taken styling over: with a policy whose styled() is false, the handle does
 * not exist and the controls render bare.
 */
final readonly class FieldStyles
{
    public const string HANDLE = 'mahout-fields';

    /**
     * The stylesheet's cache buster, the package's minor version. Bumped when
     * the stylesheet changes, so a deployed update is picked up without a
     * per-request stat call.
     */
    public const string VERSION = '1.1.0';

    /**
     * @param ?string        $url     the stylesheet's URL. Null -- the default -- resolves
     *                                from the package's own path under wp-content at the
     *                                enqueue site and refuses loudly when the package lives
     *                                somewhere no core API can name; a non-null value is a
     *                                host that owns its own URL mapping and says so
     * @param ?Panels        $panels  the host's declared panels, or null when none is bound
     * @param ?OptionScreens $screens the host's declared option screens, or null likewise
     */
    public function __construct(
        private ?string $url = null,
        private ?Panels $panels = null,
        private ?OptionScreens $screens = null,
    ) {
    }

    /** The package's own resolution, refusing loudly when it cannot hold. */
    private static function packageUrl(): string
    {
        $file = \realpath(__DIR__.'/../../resources/fields.css');

        if (false === $file) {
            throw UnresolvableFieldStyles::outsideContent('resources/fields.css is missing from the package');
        }

        $content = \wp_normalize_path((string) \realpath(WP_CONTENT_DIR).'/');
        $file = \wp_normalize_path($file);

        if (!\str_starts_with($file, $content)) {
            throw UnresolvableFieldStyles::outsideContent($file);
        }

        return \content_url(\substr($file, strlen($content)));
    }

    /**
     * The enqueue decision itself, attached to core's admin_enqueue_scripts.
     *
     * @param string $hook core's current admin screen hook suffix
     */
    public function enqueue(string $hook): void
    {
        if (!$this->renders($hook)) {
            return;
        }

        if (!\wp_style_is(self::HANDLE, 'registered')) {
            \wp_register_style(self::HANDLE, $this->url ?? self::packageUrl(), [], self::VERSION);
        }

        \wp_enqueue_style(self::HANDLE);
    }

    /**
     * Whether this screen renders a panel: an edit screen for a post type a
     * panel declares, or a declared option screen's own page.
     */
    private function renders(string $hook): bool
    {
        if ('post.php' === $hook || 'post-new.php' === $hook) {
            $screen = \get_current_screen();
            $postType = null === $screen ? '' : (string) $screen->post_type;

            return null !== $this->panels
                && [] !== $this->panels->forPostType($postType);
        }

        if (null === $this->screens) {
            return false;
        }

        foreach ($this->screens as $screen) {
            if (\get_plugin_page_hookname($screen->pageSlug, $screen->menuParent) === $hook) {
                return true;
            }
        }

        return false;
    }
}
