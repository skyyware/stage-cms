<?php
declare(strict_types=1);

namespace StageCms\Content;

use StageCms\Input;

readonly class PageSummary
{
    public function __construct(
        public string $id,
        public string $title,
        public string $slug,
        public string $excerpt,
        public ?string $cover,
        public int $version,
        public ?int $publishedVersion,
        public bool $archived,
        public string $updatedAt,
        public string $actor,
        public string $action,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(Input::text($row, 'id'), Input::text($row, 'title'), Input::text($row, 'slug'),
            Input::text($row, 'excerpt'), Input::optional($row, 'cover'), Input::integer($row['version']),
            $row['published_version'] === null ? null : Input::integer($row['published_version']),
            $row['archived'] === 1, Input::text($row, 'created_at'), Input::text($row, 'actor'), Input::text($row, 'action'));
    }

    public function status(): string
    {
        return $this->archived ? 'archived' : ($this->publishedVersion === null ? 'draft' : ($this->publishedVersion === $this->version ? 'published' : 'changed'));
    }

    /** @return array<string, int|string|bool|null> */
    public function data(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'slug' => $this->slug, 'excerpt' => $this->excerpt, 'cover' => $this->cover,
            'version' => $this->version, 'published_version' => $this->publishedVersion, 'archived' => $this->archived,
            'status' => $this->status(), 'updated_at' => $this->updatedAt, 'actor' => $this->actor, 'action' => $this->action];
    }
}
