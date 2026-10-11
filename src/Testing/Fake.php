<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Testing;

/**
 * Records what `Inbox::notify()` (and the `ruvelo_inbox_notify` action)
 * would send, without touching the database or email. For your plugin's
 * tests:
 *
 *     $fake = Fake::start();
 *     my_plugin_does_something();
 *     $this->assertCount(1, $fake->sentTo($user_id, 'invoice_paid'));
 *     $fake->stop();
 */
final class Fake
{
    /** @var list<array{users: list<int>, message: array<string, mixed>}> */
    private array $sent = [];

    /** @var \Closure(mixed, mixed, mixed): list<int> */
    private \Closure $listener;

    private function __construct()
    {
        $this->listener = function (mixed $short, mixed $users, mixed $message): array {
            $this->sent[] = [
                'users' => is_array($users) ? array_values(array_map('intval', $users)) : [],
                'message' => is_array($message) ? $message : [],
            ];

            return [];
        };
        add_filter('ruvelo_inbox_pre_notify', $this->listener, 10, 3);
    }

    public static function start(): self
    {
        return new self;
    }

    public function stop(): void
    {
        remove_filter('ruvelo_inbox_pre_notify', $this->listener, 10);
    }

    /**
     * @return list<array{users: list<int>, message: array<string, mixed>}>
     */
    public function sent(?string $type = null): array
    {
        return array_values(array_filter($this->sent, static fn (array $s): bool => $type === null || ($s['message']['type'] ?? '') === $type));
    }

    /**
     * Messages sent to one person.
     *
     * @return list<array<string, mixed>>
     */
    public function sentTo(int $userId, ?string $type = null): array
    {
        $out = [];
        foreach ($this->sent($type) as $s) {
            if (in_array($userId, $s['users'], true)) {
                $out[] = $s['message'];
            }
        }

        return $out;
    }

    public function nothingSent(): bool
    {
        return $this->sent === [];
    }
}
