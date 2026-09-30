<?php
declare(strict_types=1);

namespace StageCms\Content;

use StageCms\Input;

final readonly class Page extends PageSummary
{
    public function __construct(
        string $id,
        public Draft $draft,
        int $version,
        ?int $publishedVersion,
        bool $archived,
        string $updatedAt,
        string $actor,
        string $action,
    ) {
        parent::__construct($id, $draft->title, $draft->slug, $draft->excerpt, $draft->cover,
            $version, $publishedVersion, $archived, $updatedAt, $actor, $action, $draft->type, $draft->locale);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(Input::text($row, 'id'), Draft::fromRow($row), Input::integer($row['version']),
            $row['published_version'] === null ? null : Input::integer($row['published_version']),
            $row['archived'] === 1, Input::text($row, 'created_at'), Input::text($row, 'actor'), Input::text($row, 'action'));
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return array_merge(parent::data(), ['body' => $this->draft->body, 'fields' => $this->draft->fields]);
    }
}
