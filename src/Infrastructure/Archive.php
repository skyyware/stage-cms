<?php
declare(strict_types=1);

namespace StageCms\Infrastructure;

use Stage\Security\Caller;
use StageCms\Cms;
use StageCms\Content\Draft;
use StageCms\Content\PageBinding;
use StageCms\Failure;
use StageCms\Input;
use StageCms\Media\Asset;
use StageCms\Media\Library;
use ZipArchive;

final readonly class Archive
{
    public const int MAX_BYTES = 134217728;
    private const array COLUMNS = [
        'media' => ['id', 'name', 'mime', 'bytes', 'width', 'height', 'alt', 'sha256', 'created_at'],
        'pages' => ['id', 'slug', 'version', 'published_version', 'published_slug', 'archived', 'created_at', 'translation_group', 'locale'],
        'revisions' => ['page_id', 'version', 'title', 'slug', 'excerpt', 'body', 'cover', 'type', 'locale', 'fields', 'action', 'actor', 'created_at'],
        'revision_media' => ['page_id', 'version', 'media_id'],
        'page_bindings' => ['name', 'locale', 'page_id', 'path', 'type'],
        'page_redirects' => ['slug', 'page_id'],
    ];

    public function __construct(private Cms $cms) {}

    public function export(Caller $caller): string
    {
        $caller->require('admin');
        $path = tempnam($this->cms->config->data, 'export-');
        if ($path === false) {
            throw new \RuntimeException('Cannot prepare the export.');
        }
        try {
            $this->cms->db->transaction(function () use ($path): void {
                $zip = new ZipArchive();
                if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('Cannot create the archive.');
                }
                try {
                    $tables = [];
                    foreach (self::COLUMNS as $table => $columns) {
                        $tables[$table] = $this->cms->db->all('SELECT ' . implode(', ', $columns) . ' FROM ' . $table);
                    }
                    $json = json_encode(['format' => 'stage-cms/3', 'settings' => $this->cms->settings->get(), 'tables' => $tables],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    $total = strlen($json);
                    if ($total > self::MAX_BYTES) {
                        throw new Failure(413, 'export_too_large', 'This publication exceeds the 128 MB portable-export limit.');
                    }
                    if (!$zip->addFromString('content.json', $json)) {
                        throw new \RuntimeException('Cannot add content to the export.');
                    }
                    foreach ($tables['media'] as $row) {
                        $asset = Asset::fromRow($row);
                        $total += $asset->bytes;
                        if ($total > self::MAX_BYTES) {
                            throw new Failure(413, 'export_too_large', 'This publication exceeds the 128 MB portable-export limit. Back up the data directory while the application is stopped.');
                        }
                        if (!$zip->addFile($this->cms->media->path($asset->id), 'media/' . $asset->id . '.image')) {
                            throw new \RuntimeException('Cannot add an image to the export.');
                        }
                    }
                } finally {
                    if (!$zip->close()) {
                        throw new \RuntimeException('Cannot finish the export.');
                    }
                }
            });
            $bytes = file_get_contents($path);
            if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
                throw new Failure(413, 'export_too_large', 'The export exceeds 128 MB.');
            }
            return $bytes;
        } finally {
            unlink($path);
        }
    }

    public function restore(Caller $caller, string $path): void
    {
        $caller->require('admin');
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size > self::MAX_BYTES) {
            throw new Failure(422, 'invalid_archive', 'Choose a Stage CMS export smaller than 128 MB.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new Failure(422, 'invalid_archive', 'The archive could not be opened.');
        }
        $written = [];
        try {
            $total = 0;
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false || isset($names[$stat['name']])) {
                    throw new Failure(422, 'invalid_archive', 'Duplicate or unreadable archive entries.');
                }
                $names[$stat['name']] = true;
                $total += $stat['size'];
                if ($total > self::MAX_BYTES || !preg_match('~^(content\.json|media/[a-f0-9]{32}\.image)$~D', $stat['name'])) {
                    throw new Failure(422, 'invalid_archive', 'Unexpected archive paths or too much uncompressed data.');
                }
            }
            $json = $zip->getFromName('content.json');
            if ($json === false) {
                throw new Failure(422, 'invalid_archive', 'The archive has no content.json.');
            }
            $data = Input::object(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
            if (!in_array($data['format'] ?? null, ['stage-cms/1', 'stage-cms/2', 'stage-cms/3'], true)) {
                throw new Failure(422, 'invalid_archive', 'This export format is not supported.');
            }
            $tables = Input::object($data['tables'] ?? null);
            $settings = Input::object($data['settings'] ?? null);
            $legacy = $data['format'] === 'stage-cms/1';
            $identity = $data['format'] === 'stage-cms/3';
            if (!$identity) {
                $tables['page_bindings'] = [];
                $tables['page_redirects'] = [];
            }
            $this->cms->db->transaction(function () use ($caller, $zip, $tables, $settings, $legacy, $identity, &$written): void {
                if ($this->cms->db->one('SELECT id FROM pages LIMIT 1') !== null || $this->cms->db->one('SELECT id FROM media LIMIT 1') !== null) {
                    throw new Failure(409, 'not_empty', 'Restore requires an empty publication. Existing content will not be overwritten.');
                }
                foreach (self::COLUMNS as $table => $columns) {
                    $rows = $tables[$table] ?? null;
                    if (!is_array($rows) || !array_is_list($rows)) {
                        throw new Failure(422, 'invalid_archive', 'An export table is missing.');
                    }
                    foreach ($rows as $value) {
                        $row = Input::object($value);
                        if ($table === 'revisions' && $legacy) {
                            $row += ['type' => 'page', 'locale' => 'en', 'fields' => '{}'];
                        }
                        if ($table === 'pages' && !$identity) {
                            $row += ['translation_group' => Input::text($row, 'id'), 'locale' => 'en'];
                        }
                        if (array_diff($columns, array_keys($row)) !== [] || array_diff(array_keys($row), $columns) !== []) {
                            throw new Failure(422, 'invalid_archive', 'Unexpected export columns.');
                        }
                        $parameters = [];
                        foreach ($columns as $column) {
                            $item = $row[$column];
                            if (!is_int($item) && !is_string($item) && $item !== null) {
                                throw new Failure(422, 'invalid_archive', 'Invalid export value.');
                            }
                            $parameters[$column] = $item;
                        }
                        if ($table === 'revisions') {
                            Draft::fromRow($row);
                        }
                        if ($table === 'pages' && !preg_match('/^[a-f0-9]{32}$/D', Input::text($row, 'translation_group'))) {
                            throw new Failure(422, 'invalid_archive', 'Invalid translation group.');
                        }
                        if ($table === 'page_bindings') {
                            new PageBinding(Input::text($row, 'name'), Input::text($row, 'locale'), Input::text($row, 'page_id'), Input::text($row, 'path'), Input::text($row, 'type'));
                        }
                        if ($table === 'page_redirects') {
                            new Draft('Redirect', Input::text($row, 'slug'), '', '');
                        }
                        if ($table === 'media') {
                            $asset = Asset::fromRow($row);
                            $destination = $this->cms->media->path($asset->id);
                            $bytes = $zip->getFromName('media/' . $asset->id . '.image');
                            if ($bytes === false || strlen($bytes) > Library::MAX_BYTES || strlen($bytes) !== $asset->bytes
                                || !hash_equals($asset->sha256, hash('sha256', $bytes)) || !in_array($asset->mime, ['image/png', 'image/jpeg', 'image/webp'], true)
                                || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $asset->mime || file_exists($destination)) {
                                throw new Failure(422, 'invalid_archive', 'An archived image is invalid or already exists.');
                            }
                            if (file_put_contents($destination, $bytes, LOCK_EX) !== strlen($bytes)) {
                                throw new \RuntimeException('Cannot restore an image.');
                            }
                            chmod($destination, 0600);
                            $written[] = $destination;
                        }
                        $this->cms->db->execute('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')', $parameters);
                    }
                }
                if (!$identity) {
                    $this->cms->db->execute('UPDATE pages SET locale = (SELECT locale FROM revisions WHERE page_id = pages.id AND version = pages.version)');
                }
                if ($this->cms->db->one('SELECT p.id FROM pages p LEFT JOIN revisions r ON r.page_id = p.id AND r.version = p.version LEFT JOIN revisions live ON live.page_id = p.id AND live.version = p.published_version WHERE r.page_id IS NULL OR p.slug != r.slug OR p.locale != r.locale OR (p.published_version IS NOT NULL AND (live.page_id IS NULL OR p.published_slug IS NULL OR p.published_slug != live.slug)) OR (p.published_version IS NULL AND p.published_slug IS NOT NULL) OR (p.archived = 1 AND p.published_version IS NOT NULL) LIMIT 1') !== null) {
                    throw new Failure(422, 'invalid_archive', 'The page revisions in this archive are inconsistent.');
                }
                if ($this->cms->db->one('SELECT b.page_id FROM page_bindings b JOIN pages p ON p.id = b.page_id JOIN revisions r ON r.page_id = p.id AND r.version = p.version LEFT JOIN revisions live ON live.page_id = p.id AND live.version = p.published_version WHERE b.locale != p.locale OR b.type != r.type OR (p.published_version IS NOT NULL AND (b.type != live.type OR b.locale != live.locale)) LIMIT 1') !== null
                    || $this->cms->db->one('SELECT a.page_id FROM page_bindings a JOIN pages p ON p.id = a.page_id JOIN page_bindings b ON a.name = b.name JOIN pages q ON q.id = b.page_id WHERE p.translation_group != q.translation_group OR a.type != b.type LIMIT 1') !== null
                    || $this->cms->db->one('SELECT a.page_id FROM page_bindings a JOIN pages p ON p.id = a.page_id JOIN pages q ON q.translation_group = p.translation_group JOIN page_bindings b ON b.page_id = q.id WHERE a.name != b.name LIMIT 1') !== null
                    || $this->cms->db->one("SELECT b.page_id FROM page_bindings b JOIN pages p ON b.page_id != p.id AND (b.path = '/' || p.slug OR b.path = '/' || p.published_slug) LIMIT 1") !== null
                    || $this->cms->db->one('SELECT a.page_id FROM page_redirects a JOIN pages p ON a.page_id != p.id AND (a.slug = p.slug OR a.slug = p.published_slug) LIMIT 1') !== null
                    || $this->cms->db->one("SELECT a.page_id FROM page_redirects a JOIN page_bindings b ON a.page_id != b.page_id AND b.path = '/' || a.slug LIMIT 1") !== null) {
                    throw new Failure(422, 'invalid_archive', 'The page bindings or redirects in this archive conflict.');
                }
                $this->cms->settings->save($caller, Input::text($settings, 'title'), Input::text($settings, 'description'), Input::text($settings, 'theme', ''));
            });
        } catch (\Throwable $error) {
            foreach ($written as $file) {
                unlink($file);
            }
            throw $error;
        } finally {
            $zip->close();
        }
    }
}
