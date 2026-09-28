<?php
declare(strict_types=1);

namespace StageCms\Presentation;

use Stage\Http\Response;
use StageCms\Content\Page;

interface Theme
{
    public function index(int $page): Response;

    public function page(Page $page, bool $preview = false): Response;
}
