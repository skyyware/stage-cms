<?php
declare(strict_types=1);

namespace StageCms\Presentation;

use Stage\Http\Response;
use StageCms\Cms;
use StageCms\Content\Page;

final readonly class Publication implements Theme
{
    public function __construct(private Cms $cms) {}

    public function index(int $page): Response
    {
        $settings = $this->cms->settings->get();
        return Response::html((new View($settings['title']))->publication($this->cms->pages->published($page), $settings['description'], $page));
    }

    public function page(Page $page, bool $preview = false): Response
    {
        return Response::html((new View($this->cms->settings->get()['title']))->story($page, $preview,
            $page->draft->cover === null ? '' : $this->cms->media->get($page->draft->cover)->alt));
    }
}
