<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Support;

/**
 * Typed accessors for decoded FedEx JSON documents.
 *
 * FedEx containers that may repeat are returned either as an object or as a
 * list of objects depending on the API version and the number of items;
 * items() always yields a list.
 */
final class Payload
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function get(array $data, string $path): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $path): ?string
    {
        $value = self::get($data, $path);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $path): array
    {
        $value = self::get($data, $path);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    public static function items(array $data, string $path): array
    {
        $value = self::get($data, $path);

        if (! is_array($value) || $value === []) {
            return [];
        }

        if (! array_is_list($value)) {
            return [$value];
        }

        return array_values(array_filter($value, is_array(...)));
    }

    /**
     * Presence indicators are sent as empty strings and returned as empty strings or objects.
     *
     * @param array<array-key, mixed> $data
     */
    public static function has(array $data, string $path): bool
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        $parent = $segments === [] ? $data : self::get($data, implode('.', $segments));

        return is_array($parent) && array_key_exists($last, $parent);
    }

    /**
     * Formats a number as FedEx expects it: no thousands separator, trailing zeros removed.
     */
    public static function number(float|int|string $value, int $decimals = 2): string
    {
        $formatted = number_format((float) $value, $decimals, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    /**
     * Decodes a base64 document returned by FedEx.
     */
    public static function decode(string $base64): ?string
    {
        $decoded = base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Removes null values and empty arrays recursively, keeping empty strings (FedEx presence indicators).
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function clean(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::clean($value);

                if ($value === []) {
                    continue;
                }
            }

            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        return array_is_list($data) ? array_values($clean) : $clean;
    }
}
