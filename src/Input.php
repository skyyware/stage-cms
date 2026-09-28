<?php
declare(strict_types=1);

namespace StageCms;

final class Input
{
    /** @param array<string, mixed> $data */
    public static function text(array $data, string $key, ?string $default = null): string
    {
        $value = $data[$key] ?? $default;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new Failure(422, 'invalid_input', 'Enter a valid ' . str_replace('_', ' ', $key) . '.');
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optional(array $data, string $key): ?string
    {
        return ($data[$key] ?? '') === '' || ($data[$key] ?? null) === null ? null : self::text($data, $key);
    }

    public static function integer(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,18}$/D', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw new Failure(422, 'invalid_version', 'Provide the current revision number.');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            throw new Failure(400, 'invalid_input', 'Provide a JSON object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new Failure(400, 'invalid_input', 'Object keys must be names.');
            }
            $result[$key] = $item;
        }
        return $result;
    }
}
