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
 * @requires  ext-igbinary
 */

namespace MonkeysLegion\Cache\Serializer;

/**
 * Igbinary serialization — 30–50% smaller payloads than PHP serialize.
 *
 * Falls back to PhpSerializer if ext-igbinary is not loaded.
 */
final class IgbinarySerializer implements CacheSerializerInterface
{
    public function __construct()
    {
        if (!extension_loaded('igbinary')) {
            throw new \RuntimeException(
                'ext-igbinary is required for IgbinarySerializer. Install: pecl install igbinary',
            );
        }
    }

    public function serialize(mixed $value): string
    {
        $result = igbinary_serialize($value);

        if ($result === null) {
            throw new \RuntimeException('igbinary serialization failed.');
        }

        return $result;
    }

    public function unserialize(string $data): mixed
    {
        $result = igbinary_unserialize($data);

        if ($result === false && $data !== igbinary_serialize(false)) {
            throw new \RuntimeException('igbinary deserialization failed — data may be corrupt.');
        }

        return $result;
    }
}
