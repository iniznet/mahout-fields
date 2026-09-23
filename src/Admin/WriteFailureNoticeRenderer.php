<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

/**
 * The write-failure notice's presentation: the refusal `WriteFailureNotice`
 * queued for the editing user and this post, surfaced on the next admin screen
 * load.
 *
 * The save pipeline never `wp_die()`, so a refused classic-path save is one
 * diagnostics record plus this notice and nothing else. The notice carries the
 * group, the reason and the reference; nothing is retried and nothing is
 * substituted.
 *
 * The queue lives on `WriteFailureNotice`, whose only operations are queue and
 * take; this class reads a taken refusal back and renders it, so the store
 * carries no markup and the markup knows no transient key.
 */
final readonly class WriteFailureNoticeRenderer
{
    public function __construct(private WriteFailureNotice $notices)
    {
    }

    /**
     * The admin_notices entry for the edited post. The post resolves through
     * core's current post, which wp-admin sets before notices fire; off an
     * edit screen there is nothing to take, and a taken refusal is taken once
     * -- a notice never repeats itself on the next load.
     */
    public function render(): void
    {
        $post = \get_post();

        if (!$post instanceof \WP_Post) {
            return;
        }

        $refusal = $this->notices->take(\get_current_user_id(), (int) $post->ID);

        if (!$refusal instanceof QueuedRefusal) {
            return;
        }

        \wp_admin_notice(
            sprintf(
                /* translators: 1: field group id, 2: refusal reason, 3: diagnostics reference. */
                __('The changes to field group "%1$s" were not saved (%2$s). Diagnostics reference: %3$s. Nothing was written.', 'mahout-fields'),
                $refusal->groupId,
                $refusal->reason,
                $refusal->reference,
            ),
            ['type' => 'error', 'dismissible' => false, 'paragraph' => true],
        );
    }
}
