<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Sources;

/**
 * A built-in source of notifications.
 */
interface Source
{
    public static function label(): string;

    public static function description(): string;

    /**
     * Whether what it listens to exists on this site (a plugin, a core feature).
     */
    public static function available(): bool;

    /**
     * Registers its notification types.
     */
    public static function types(): void;

    /**
     * Adds its hooks.
     */
    public static function hooks(): void;

    /**
     * Removes its hooks (when the source is turned off, and in tests).
     */
    public static function unhook(): void;
}
