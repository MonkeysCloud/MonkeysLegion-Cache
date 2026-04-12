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
 * Safe PHP native serialization.
 *
 * Uses `allowed_classes` whitelist on unserialize to prevent
 * object injection / code execution attacks.
 */
final class PhpSerializer implements CacheSerializerInterface
{
    /**
     * @param list<class-string>|true $allowedClasses Classes allowed during unserialize.
     *                                                 `true` = allow all (less secure).
     *                                                 Default `[]` blocks all object instantiation.
     */
    public function __construct(
        private readonly array|true $allowedClasses = [],
    ) {}

    public function serialize(mixed $value): string
    {
        return \serialize($value);
    }

    public function unserialize(string $data): mixed
    {
        $result = @\unserialize($data, [
            'allowed_classes' => $this->allowedClasses,
        ]);

        if ($result === false && $data !== \serialize(false)) {
            throw new \RuntimeException('Cache deserialization failed — data may be corrupt.');
        }

        return $result;
    }
}
