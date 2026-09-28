<?php
declare(strict_types=1);

namespace StageCms\Infrastructure;

final readonly class Config
{
    public function __construct(public string $root, public string $data, public string $url = 'http://127.0.0.1:8088')
    {
        $parsed = parse_url($url);
        if ($parsed === false || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true) || !isset($parsed['host'])
            || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])
            || ($parsed['path'] ?? '') !== '') {
            throw new \InvalidArgumentException('CMS_URL must be an HTTP origin without a path or credentials.');
        }
        if (!str_starts_with($data, '/') || str_contains($data, "\0")) {
            throw new \InvalidArgumentException('CMS_DATA_DIR must be an absolute path.');
        }
        if (!is_dir($data) && !mkdir($data, 0700, true) && !is_dir($data)) {
            throw new \RuntimeException('Cannot create CMS_DATA_DIR.');
        }
        $real = realpath($data);
        $public = realpath($root . '/public');
        if ($real === false || ($public !== false && ($real === $public || str_starts_with($real, $public . '/')))) {
            throw new \InvalidArgumentException('CMS_DATA_DIR must be outside public/.');
        }
    }

    public static function environment(string $root): self
    {
        return new self($root, getenv('CMS_DATA_DIR') ?: $root . '/.runtime/data', rtrim(getenv('CMS_URL') ?: 'http://127.0.0.1:8088', '/'));
    }

    public function secureCookie(): bool
    {
        return str_starts_with($this->url, 'https://');
    }
}
