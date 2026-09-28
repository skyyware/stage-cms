<?php
declare(strict_types=1);

namespace StageCms\Media;

use finfo;
use Stage\Security\Caller;
use StageCms\Failure;
use StageCms\Infrastructure\Database;
use Throwable;

final readonly class Library
{
    public const int MAX_BYTES = 5242880;

    public function __construct(private Database $db, private string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the media directory.');
        }
    }

    /** @return list<Asset> */
    public function list(Caller $caller): array
    {
        $caller->require('content:read');
        return array_map(Asset::fromRow(...), $this->db->all('SELECT * FROM media ORDER BY created_at DESC, id'));
    }

    public function upload(Caller $caller, string $name, string $bytes, string $alt = ''): Asset
    {
        $caller->require('media:write');
        if (strlen($bytes) > self::MAX_BYTES || $bytes === '') {
            throw new Failure(413, 'image_too_large', 'Choose an image no larger than 5 MB.');
        }
        if (mb_strlen($alt) > 300) {
            throw new Failure(422, 'invalid_alt', 'Keep the image description under 300 characters.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new Failure(422, 'invalid_image', 'Choose a JPEG, PNG, or WebP image.');
        }
        set_error_handler(static function (int $severity, string $message): never {
            throw new Failure(422, 'invalid_image', 'This image could not be decoded.');
        });
        try {
            $size = getimagesizefromstring($bytes);
            if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > 16000000) {
                throw new Failure(422, 'invalid_image', 'Choose an image with at most 16 million pixels.');
            }
            $image = imagecreatefromstring($bytes);
            if ($image === false) {
                throw new Failure(422, 'invalid_image', 'This image could not be decoded.');
            }
            imagesavealpha($image, true);
            ob_start();
            try {
                $encoded = match ($mime) {
                    'image/jpeg' => imagejpeg($image, null, 90),
                    'image/png' => imagepng($image),
                    'image/webp' => imagewebp($image, null, 90),
                };
                $clean = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (!$encoded || $clean === false || strlen($clean) > self::MAX_BYTES) {
                throw new Failure(422, 'invalid_image', 'The decoded image could not be stored within 5 MB.');
            }
        } finally {
            restore_error_handler();
        }
        $id = bin2hex(random_bytes(16));
        $name = mb_substr(basename(str_replace('\\', '/', $name)), 0, 160);
        $path = $this->path($id);
        if (file_put_contents($path, $clean, LOCK_EX) !== strlen($clean)) {
            throw new \RuntimeException('Cannot store the image.');
        }
        chmod($path, 0600);
        try {
            $this->db->execute('INSERT INTO media VALUES (:id, :name, :mime, :bytes, :width, :height, :alt, :sha256, :created)', [
                'id' => $id, 'name' => $name, 'mime' => $mime, 'bytes' => strlen($clean),
                'width' => $size[0], 'height' => $size[1], 'alt' => trim($alt), 'sha256' => hash('sha256', $clean), 'created' => gmdate('c'),
            ]);
        } catch (Throwable $error) {
            unlink($path);
            throw $error;
        }
        return $this->get($id);
    }

    public function get(string $id): Asset
    {
        $this->path($id);
        $row = $this->db->one('SELECT * FROM media WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw new Failure(404, 'not_found', 'That image does not exist.');
        }
        return Asset::fromRow($row);
    }

    public function isPublished(string $id): bool
    {
        return $this->db->one('SELECT p.id FROM pages p JOIN revision_media m ON m.page_id = p.id AND m.version = p.published_version WHERE p.archived = 0 AND m.media_id = :id LIMIT 1',
            ['id' => $id]) !== null;
    }

    public function bytes(string $id, ?Caller $caller = null): string
    {
        $this->get($id);
        if (!$this->isPublished($id)) {
            if ($caller === null) {
                throw new Failure(404, 'not_found', 'That image is not published.');
            }
            $caller->require('content:read');
        }
        $bytes = file_get_contents($this->path($id));
        if ($bytes === false) {
            throw new \RuntimeException('Cannot read the stored image.');
        }
        return $bytes;
    }

    public function describe(Caller $caller, string $id, string $alt): void
    {
        $caller->require('media:write');
        $this->get($id);
        if (mb_strlen($alt) > 300) {
            throw new Failure(422, 'invalid_alt', 'Keep the image description under 300 characters.');
        }
        $this->db->execute('UPDATE media SET alt = :alt WHERE id = :id', ['id' => $id, 'alt' => trim($alt)]);
    }

    public function delete(Caller $caller, string $id): void
    {
        $caller->require('media:write');
        $this->db->transaction(function () use ($id): void {
            $this->get($id);
            if ($this->db->one('SELECT media_id FROM revision_media WHERE media_id = :id LIMIT 1', ['id' => $id]) !== null) {
                throw new Failure(409, 'image_in_use', 'This image belongs to a page or its history and cannot be deleted.');
            }
            $this->db->execute('DELETE FROM media WHERE id = :id', ['id' => $id]);
            if (!unlink($this->path($id))) {
                throw new \RuntimeException('Cannot remove the unused image.');
            }
        });
    }

    public function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new Failure(404, 'not_found', 'That image does not exist.');
        }
        return $this->directory . '/' . $id . '.image';
    }
}
