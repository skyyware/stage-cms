<?php
declare(strict_types=1);

namespace StageCms\Infrastructure;

use Stage\Security\Caller;
use StageCms\Failure;
use StageCms\Input;

final readonly class Settings
{
    public function __construct(private Database $db) {}

    /** @return array{title: string, description: string} */
    public function get(): array
    {
        $row = $this->db->one('SELECT title, description FROM settings WHERE id = 1') ?? [];
        return ['title' => Input::text($row, 'title'), 'description' => Input::text($row, 'description')];
    }

    public function save(Caller $caller, string $title, string $description): void
    {
        $caller->require('admin');
        if (trim($title) === '' || mb_strlen($title) > 100 || mb_strlen($description) > 300) {
            throw new Failure(422, 'invalid_settings', 'Use a site name under 100 characters and a description under 300 characters.');
        }
        $this->db->execute('UPDATE settings SET title = :title, description = :description WHERE id = 1',
            ['title' => trim($title), 'description' => $description]);
    }
}
