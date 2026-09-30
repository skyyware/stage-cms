<?php
declare(strict_types=1);

namespace StageCms\Content;

use StageCms\Failure;

final readonly class PageTypes
{
    /** @var array<string, PageType> */
    public array $all;

    public function __construct(PageType ...$types)
    {
        $all = ['page' => new PageType('page', 'Page')];
        foreach ($types as $type) {
            if (isset($all[$type->id]) && $type->id !== 'page') {
                throw new \InvalidArgumentException('Duplicate page type.');
            }
            $all[$type->id] = $type;
        }
        $this->all = $all;
    }

    public function get(string $id): PageType
    {
        return $this->all[$id] ?? throw new Failure(422, 'unknown_page_type', 'Choose an installed page type.');
    }

    public function validate(Draft $draft): void
    {
        $this->get($draft->type)->validate($draft);
    }

    /** @return list<array{id: string, label: string, markdown: bool, fields: list<array{key: string, label: string, group: string, multiline: bool, limit: int}>}> */
    public function data(): array
    {
        return array_values(array_map(fn (PageType $type): array => ['id' => $type->id, 'label' => $type->label,
            'markdown' => $type->markdown, 'fields' => array_map(fn (Field $field): array => [
                'key' => $field->key, 'label' => $field->label, 'group' => $field->group,
                'multiline' => $field->multiline, 'limit' => $field->limit,
            ], $type->fields)], $this->all));
    }
}
