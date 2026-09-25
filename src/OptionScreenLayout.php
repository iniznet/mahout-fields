<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * How an option screen's tabs navigate: core's horizontal nav tabs, or a
 * sidebar column beside the content. The screen declares its preference; the
 * markup and the shipped stylesheet follow it, and a host that took styling
 * over owns the visual result entirely.
 */
enum OptionScreenLayout: string
{
    /** Core's nav-tab bar across the top -- the shape the page took first. */
    case Tabs = 'tabs';

    /** A navigation column beside the content -- the default, for pages that read as a document. */
    case Sidebar = 'sidebar';
}
