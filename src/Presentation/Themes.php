<?php
declare(strict_types=1);

namespace StageCms\Presentation;

use Stage\Http\Response;
use StageCms\Cms;
use StageCms\Content\Page;
use StageCms\Failure;

final readonly class Themes implements Theme
{
    /** @var array<string, ThemeOption> */
    public array $all;

    public function __construct(private Cms $cms, ThemeOption $first, ThemeOption ...$others)
    {
        $all = [];
        foreach ([$first, ...$others] as $option) {
            if (isset($all[$option->id])) {
                throw new \InvalidArgumentException('Duplicate theme.');
            }
            $all[$option->id] = $option;
        }
        $this->all = $all;
    }

    public function selected(): string
    {
        $id = $this->cms->settings->get()['theme'];
        return $id === '' ? array_key_first($this->all) : $id;
    }

    public function get(string $id): Theme
    {
        return ($this->all[$id] ?? throw new Failure(422, 'unknown_theme', 'This theme is not installed. Choose an installed theme in Settings.'))->theme;
    }

    public function index(int $number = 1): Response
    {
        return $this->get($this->selected())->index($number);
    }

    public function page(Page $page, bool $preview = false): Response
    {
        return $this->get($this->selected())->page($page, $preview);
    }
}
