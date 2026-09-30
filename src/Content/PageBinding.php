<?php
declare(strict_types=1);

namespace StageCms\Content;

use StageCms\Failure;

final readonly class PageBinding
{
    public function __construct(public string $name, public string $locale, public string $pageId, public string $path, public string $type)
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $name) || !preg_match('/^[a-z][a-z0-9-]{0,59}$/D', $type)
            || !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $locale) || !preg_match('/^[a-f0-9]{32}$/D', $pageId)
            || !preg_match('~^/(?:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*)?$~D', $path)
            || in_array(explode('/', trim($path, '/'))[0], ['admin', 'api', 'media', 'assets', 'health', 'login', 'logout'], true)) {
            throw new Failure(422, 'invalid_binding', 'Use a named page, its language, and an available public path.');
        }
    }
}
