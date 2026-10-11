<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * One notification, as stored. Immutable; change it through Inbox.
 */
final class Notification
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $icon,
        public readonly int $actorId,
        public readonly string $actorName,
        public readonly array $data,
        public readonly ?string $readAt,
        public readonly string $createdAt,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $data = isset($row['data']) && is_string($row['data']) && $row['data'] !== '' ? json_decode($row['data'], true) : [];

        return new self(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            type: (string) $row['type'],
            title: (string) $row['title'],
            body: (string) $row['body'],
            url: (string) $row['url'],
            icon: (string) $row['icon'],
            actorId: (int) $row['actor_id'],
            actorName: (string) $row['actor_name'],
            data: is_array($data) ? $data : [],
            readAt: isset($row['read_at']) && is_string($row['read_at']) && $row['read_at'] !== '' ? $row['read_at'] : null,
            createdAt: (string) $row['created_at'],
        );
    }

    public function isUnread(): bool
    {
        return $this->readAt === null;
    }

    public function timestamp(): int
    {
        $time = strtotime($this->createdAt.' UTC');

        return $time !== false ? $time : 0;
    }

    public function typeLabel(): string
    {
        return Types::label($this->type);
    }

    /**
     * Up to two letters for the avatar: the actor's initials, or the type's icon.
     */
    public function avatar(): string
    {
        if ($this->icon !== '') {
            return $this->icon;
        }
        if ($this->actorName !== '') {
            return self::initials($this->actorName);
        }
        $icon = Types::get($this->type)['icon'];

        return $icon !== '' ? $icon : self::initials($this->typeLabel());
    }

    public function hasActorAvatar(): bool
    {
        return $this->icon === '' && $this->actorName !== '';
    }

    public static function initials(string $name): string
    {
        $words = preg_split('/[\s\-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? $words : [];
        $letters = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $letters !== '' ? $letters : '•';
    }

    public function excerpt(int $length = 140): string
    {
        $body = trim((string) preg_replace('/\s+/u', ' ', $this->body));
        if (mb_strlen($body) <= $length) {
            return $body;
        }

        return rtrim(mb_substr($body, 0, $length - 1)).'…';
    }

    /**
     * "12 minutes ago", in the site's language.
     */
    public function timeAgo(): string
    {
        $time = $this->timestamp();
        if (time() - $time < MINUTE_IN_SECONDS) {
            return __('Just now', 'ruvelo-inbox');
        }

        /* translators: %s: a span of time, like "12 minutes" */
        return sprintf(__('%s ago', 'ruvelo-inbox'), human_time_diff($time));
    }

    /**
     * "Today", "Yesterday" or a date, in the site's timezone.
     */
    public function day(): string
    {
        $time = $this->timestamp();
        $day = wp_date('Y-m-d', $time);
        if ($day === wp_date('Y-m-d')) {
            return __('Today', 'ruvelo-inbox');
        }
        if ($day === wp_date('Y-m-d', time() - DAY_IN_SECONDS)) {
            return __('Yesterday', 'ruvelo-inbox');
        }
        $format = wp_date('Y', $time) === wp_date('Y') ? _x('l j F', 'inbox day heading', 'ruvelo-inbox') : _x('j F Y', 'inbox day heading, another year', 'ruvelo-inbox');

        return (string) wp_date($format, $time);
    }

    /**
     * The shape the REST API, abilities and WP-CLI return.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon,
            'actor' => ['id' => $this->actorId, 'name' => $this->actorName],
            'avatar' => $this->avatar(),
            'read' => ! $this->isUnread(),
            'read_at' => $this->readAt !== null ? gmdate('c', (int) strtotime($this->readAt.' UTC')) : null,
            'created_at' => gmdate('c', $this->timestamp()),
            'time_ago' => $this->timeAgo(),
            'day' => $this->day(),
            'data' => $this->data,
        ];
    }
}
