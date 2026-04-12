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
 * Pluggable cache serialization contract.
 */
interface CacheSerializerInterface
{
    /**
     * Serialize a value for cache storage.
     */
    public function serialize(mixed $value): string;

    /**
     * Unserialize a value from cache storage.
     *
     * @throws \RuntimeException If the data is corrupt or tampered.
     */
    public function unserialize(string $data): mixed;
}
