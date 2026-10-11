<?php

/**
 * Renders the Notification Bell block.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Ruvelo\WP\Inbox\Blocks;

defined('ABSPATH') || exit;

echo Blocks::bell($attributes, get_block_wrapper_attributes()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the view.
