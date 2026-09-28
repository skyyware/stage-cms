<?php
declare(strict_types=1);

namespace StageCms\Http;

use Stage\Http\Request;
use StageCms\Failure;
use StageCms\Input;

final readonly class Context
{
    /** @param array<string, mixed> $uploads */
    public function __construct(public Request $request, public array $uploads = [], public string $address = 'local') {}

    /** @return array<string, mixed> */
    public function form(): array
    {
        parse_str($this->request->body, $data);
        return Input::object($data);
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        parse_str($this->request->query, $data);
        return Input::object($data);
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public function json(array $fields): array
    {
        if (strtolower(trim(explode(';', $this->request->headers['content-type'] ?? '')[0])) !== 'application/json') {
            throw new Failure(415, 'unsupported_type', 'Send an application/json object.');
        }
        if (!str_starts_with(ltrim($this->request->body), '{')) {
            throw new Failure(400, 'invalid_json', 'Send a JSON object.');
        }
        try {
            $input = Input::object(json_decode($this->request->body, true, 32, JSON_THROW_ON_ERROR));
            if (array_diff(array_keys($input), $fields) !== []) {
                throw new Failure(422, 'unknown_field', 'This operation received an unknown field. Read /api/schema for its accepted fields.');
            }
            return $input;
        } catch (\JsonException) {
            throw new Failure(400, 'invalid_json', 'The JSON could not be read.');
        }
    }

    public function cookie(string $name): ?string
    {
        foreach (explode(';', $this->request->headers['cookie'] ?? '') as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === $name) {
                return $value;
            }
        }
        return null;
    }

    /** @return array{name: string, bytes: string} */
    public function image(): array
    {
        $file = Input::object($this->uploads['image'] ?? []);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Failure(422, 'missing_image', 'Choose a JPEG, PNG, or WebP image up to 5 MB.');
        }
        $path = Input::text($file, 'tmp_name');
        if (!is_uploaded_file($path)) {
            throw new Failure(422, 'invalid_upload', 'This upload could not be verified.');
        }
        $bytes = file_get_contents($path, false, null, 0, 5242881);
        if ($bytes === false) {
            throw new Failure(422, 'invalid_upload', 'This upload could not be read.');
        }
        return ['name' => Input::text($file, 'name'), 'bytes' => $bytes];
    }
}
