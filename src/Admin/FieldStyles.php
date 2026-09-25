<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Contracts\OptionScreens;
use Iniznet\Mahout\Fields\Contracts\Panels;
use Iniznet\Mahout\Fields\Exception\UnresolvableFieldStyles;

/**
 * The default assets' enqueue decision, one listener for every screen that
 * renders field UI. Eligibility is derived, never listed twice: a panel's
 * post type edit screen qualifies through the declared panels, an option
 * screen's page through its declaration's slug and parent, the same way
 * OptionScreenManager derives the page hook it registered. An unrelated
 * admin screen enqueues nothing.
 *
 * The listener is attached only when a host has declared field UI and has not
 * taken styling over: with a policy whose styled() is false, neither handle
 * exists and the controls render bare.
 */
final readonly class FieldStyles
{
    public const string HANDLE = 'mahout-fields';

    /** The option screens' tab-switching script: a companion to the stylesheet, not a styling fact. */
    public const string SCRIPT_HANDLE = 'mahout-fields-tabs';

    /**
     * The assets' cache buster, the package's minor version. Bumped when an
     * asset changes, so a deployed update is picked up without a per-request
     * stat call.
     */
    public const string VERSION = '1.3.0';

    /**
     * The assets' path inside a host's vendor directory, named as the
     * package's own Composer install -- the path a symlinked dev checkout
     * serves and the one `get_theme_file_path()` can name.
     */
    private const string VENDOR_DIR = 'vendor/iniznet/mahout-fields/resources/';

    /**
     * @param ?string        $url       the stylesheet's URL. Null -- the default --
     *                                  resolves from the package's own path under
     *                                  wp-content at the enqueue site and refuses
     *                                  loudly when the package lives somewhere no
     *                                  core API can name; a non-null value is a host
     *                                  that owns its own URL mapping and says so
     * @param ?string        $scriptUrl the tab-switching script's URL, null resolving
     *                                  the same way as the stylesheet's
     * @param ?Panels        $panels    the host's declared panels, or null when none is bound
     * @param ?OptionScreens $screens   the host's declared option screens, or null likewise
     */
    public function __construct(
        private ?string $url = null,
        private ?string $scriptUrl = null,
        private ?Panels $panels = null,
        private ?OptionScreens $screens = null,
    ) {
    }

    /**
     * The package's own URL resolution, refusing loudly when it cannot hold.
     *
     * A Composer path repository installs the package as a symlink inside the
     * host's vendor directory while the real checkout lives elsewhere, and
     * PHP's __DIR__ resolves the link -- so the logical path is recovered
     * through core's own theme API, which names the file as the web server
     * serves it. A package installed directly under wp-content resolves from
     * its own path; a package that lives nowhere core can name refuses.
     *
     * @param 'fields.css'|'fields.js' $file the asset's file name
     */
    private static function packageUrl(string $file): string
    {
        $content = \wp_normalize_path(WP_CONTENT_DIR.'/');

        $throughTheme = \wp_normalize_path((string) \get_theme_file_path(self::VENDOR_DIR.$file));

        if (\is_file($throughTheme) && \str_starts_with($throughTheme, $content)) {
            return \content_url(\substr($throughTheme, strlen($content)));
        }

        $path = \wp_normalize_path((string) \realpath(__DIR__.'/../../resources/'.$file));

        if (\is_file($path) && \str_starts_with($path, $content)) {
            return \content_url(\substr($path, strlen($content)));
        }

        throw UnresolvableFieldStyles::outsideContent($path);
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
            \wp_register_style(self::HANDLE, $this->url ?? self::packageUrl('fields.css'), [], self::VERSION);
        }

        \wp_enqueue_style(self::HANDLE);

        // The script is an option screen's fact: a metabox renders no tabs,
        // so the edit screens enqueue the stylesheet and nothing else.
        if (null !== $this->screens && $this->optionScreenHook($hook)) {
            if (!\wp_script_is(self::SCRIPT_HANDLE, 'registered')) {
                $scriptUrl = $this->scriptUrl ?? self::packageUrl('fields.js');
                \wp_register_script(self::SCRIPT_HANDLE, '' !== $scriptUrl ? $scriptUrl : false, [], self::VERSION, true);
            }

            \wp_enqueue_script(self::SCRIPT_HANDLE);
        }
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

        return $this->optionScreenHook($hook);
    }

    /** Whether the hook is a declared option screen's own page. */
    private function optionScreenHook(string $hook): bool
    {
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
