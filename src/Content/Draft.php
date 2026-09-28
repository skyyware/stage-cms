<?php
declare(strict_types=1);

namespace StageCms\Content;

use StageCms\Failure;
use StageCms\Input;

final readonly class Draft
{
    public function __construct(
        public string $title,
        public string $slug,
        public string $excerpt,
        public string $body,
        public ?string $cover = null,
    ) {
        if (trim($title) === '' || mb_strlen($title) > 200) {
            throw new Failure(422, 'invalid_title', 'Give this page a title of 1 to 200 characters.');
        }
        if (strlen($slug) > 120 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)
            || in_array($slug, ['admin', 'api', 'media', 'assets', 'health', 'login', 'logout'], true)) {
            throw new Failure(422, 'invalid_slug', 'Use a unique address with lowercase letters, numbers, and hyphens.');
        }
        if (mb_strlen($excerpt) > 500 || strlen($body) > 200000 || !mb_check_encoding($body, 'UTF-8')) {
            throw new Failure(422, 'invalid_content', 'Keep the summary under 500 characters and the text under 200 KB.');
        }
        if ($cover !== null && !preg_match('/^[a-f0-9]{32}$/D', $cover)) {
            throw new Failure(422, 'invalid_cover', 'Choose an image from the media library.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        return new self(trim(Input::text($input, 'title')), Input::text($input, 'slug'),
            Input::text($input, 'excerpt', ''), Input::text($input, 'body', ''), Input::optional($input, 'cover'));
    }

    /** @return array{title: string, slug: string, excerpt: string, body: string, cover: ?string} */
    public function data(): array
    {
        return ['title' => $this->title, 'slug' => $this->slug, 'excerpt' => $this->excerpt, 'body' => $this->body, 'cover' => $this->cover];
    }
}
