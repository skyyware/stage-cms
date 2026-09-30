<?php
declare(strict_types=1);

namespace StageCms\Content;

use StageCms\Failure;

final readonly class PageType
{
    /** @param list<Field> $fields */
    public function __construct(public string $id, public string $label, public array $fields = [], public bool $markdown = true)
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $id) || trim($label) === '') {
            throw new \InvalidArgumentException('Invalid page type definition.');
        }
        $keys = array_map(fn (Field $field): string => $field->key, $fields);
        if (count($keys) !== count(array_unique($keys))) {
            throw new \InvalidArgumentException('Page field keys must be unique.');
        }
    }

    public function validate(Draft $draft): void
    {
        $limits = [];
        foreach ($this->fields as $field) {
            $limits[$field->key] = $field->limit;
        }
        foreach ($draft->fields as $key => $value) {
            if (!isset($limits[$key]) || mb_strlen($value) > $limits[$key]) {
                throw new Failure(422, 'invalid_field', 'Unknown or oversized content field: ' . $key);
            }
        }
        if (!$this->markdown && $draft->body !== '') {
            throw new Failure(422, 'invalid_body', 'This page type uses named fields instead of Markdown.');
        }
    }

    public function validatePublication(Draft $draft): void
    {
        $errors = [];
        foreach ($this->fields as $field) {
            if ($field->required && trim($draft->fields[$field->key] ?? '') === '') {
                $errors['fields.' . $field->key] = $field->label . ' is required before publishing.';
            }
        }
        if ($errors !== []) {
            throw new Failure(422, 'incomplete_publication', 'Complete the required fields before publishing.', $errors);
        }
    }
}
