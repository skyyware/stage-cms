<?php
declare(strict_types=1);

namespace StageCms\Content;

use StageCms\Failure;
use StageCms\Input;

final readonly class Draft
{
    /** @param array<string, string> $fields */
    public function __construct(
        public string $title,
        public string $slug,
        public string $excerpt,
        public string $body,
        public ?string $cover = null,
        public string $type = 'page',
        public string $locale = 'en',
        public array $fields = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $type) || !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $locale)) {
            throw new Failure(422, 'invalid_page_details', 'Choose a valid page type and language code.');
        }
        foreach ($fields as $key => $value) {
            if (!preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $key) || !mb_check_encoding($value, 'UTF-8')) {
                throw new Failure(422, 'invalid_fields', 'Content fields must contain UTF-8 text with valid names.');
            }
        }
        if (strlen(json_encode($fields, JSON_THROW_ON_ERROR)) > 200000) {
            throw new Failure(422, 'invalid_fields', 'Keep named fields under 200 KB.');
        }
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
            Input::text($input, 'excerpt', ''), Input::text($input, 'body', ''), Input::optional($input, 'cover'),
            Input::text($input, 'type', 'page'), Input::text($input, 'locale', 'en'), self::fields($input['fields'] ?? []));
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $row['fields'] = json_decode(Input::text($row, 'fields', '{}'), true, 32, JSON_THROW_ON_ERROR);
        return self::fromInput($row);
    }

    /** @return array<string, string> */
    private static function fields(mixed $input): array
    {
        $fields = Input::object($input);
        $result = [];
        foreach ($fields as $key => $value) {
            if (!is_string($value)) {
                throw new Failure(422, 'invalid_fields', 'Content fields must contain text.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /** @return array{title: string, slug: string, excerpt: string, body: string, cover: ?string, type: string, locale: string, fields: array<string, string>} */
    public function data(): array
    {
        return ['title' => $this->title, 'slug' => $this->slug, 'excerpt' => $this->excerpt, 'body' => $this->body, 'cover' => $this->cover,
            'type' => $this->type, 'locale' => $this->locale, 'fields' => $this->fields];
    }
}
