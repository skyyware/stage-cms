<?php
declare(strict_types=1);

namespace StageCms\Media;

use StageCms\Input;

final readonly class Asset
{
    public function __construct(
        public string $id,
        public string $name,
        public string $mime,
        public int $bytes,
        public int $width,
        public int $height,
        public string $alt,
        public string $sha256,
        public string $createdAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(Input::text($row, 'id'), Input::text($row, 'name'), Input::text($row, 'mime'),
            Input::integer($row['bytes']), Input::integer($row['width']), Input::integer($row['height']),
            Input::text($row, 'alt'), Input::text($row, 'sha256'), Input::text($row, 'created_at'));
    }

    /** @return array<string, int|string> */
    public function data(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'mime' => $this->mime, 'bytes' => $this->bytes,
            'width' => $this->width, 'height' => $this->height, 'alt' => $this->alt, 'sha256' => $this->sha256,
            'created_at' => $this->createdAt, 'url' => '/media/' . $this->id];
    }
}
