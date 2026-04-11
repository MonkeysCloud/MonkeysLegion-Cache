<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2
 *
 * @package   MonkeysLegion\Cache\Serializer
 * @author    MonkeysCloud <jorge@monkeyscloud.com>
 * @license   MIT
 *
 * @requires  PHP 8.4
 */

namespace MonkeysLegion\Cache\Serializer;

/**
 * JSON-based serialization — zero code execution risk.
 *
 * Best for scalar/array data. Does not support objects
 * with private state or circular references.
 */
final class JsonSerializer implements CacheSerializerInterface
{
    public function __construct(
        private readonly int $encodeFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        private readonly int $decodeFlags = JSON_BIGINT_AS_STRING,
    ) {}

    public function serialize(mixed $value): string
    {
        $json = json_encode($value, $this->encodeFlags | JSON_THROW_ON_ERROR);

        return $json;
    }

    public function unserialize(string $data): mixed
    {
        return json_decode($data, associative: true, flags: $this->decodeFlags | JSON_THROW_ON_ERROR);
    }
}
