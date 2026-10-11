<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Support;

/**
 * Redirects and stops. Under the test suite (RUVELO_INBOX_TESTS) it throws
 * instead, so the form handlers can be tested.
 */
final class Redirect extends \RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct($url);
    }

    public static function to(string $url): never
    {
        if (defined('RUVELO_INBOX_TESTS')) {
            throw new self($url); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Only thrown inside the test suite, never shown.
        }
        wp_safe_redirect($url);
        exit;
    }
}
