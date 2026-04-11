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
 * Supported cache serialization formats.
 */
enum SerializerType: string
{
    case Php      = 'php';
    case Json     = 'json';
    case Igbinary = 'igbinary';

    /**
     * Whether the required extension is loaded.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::Php      => true,
            self::Json     => true,
            self::Igbinary => extension_loaded('igbinary'),
        };
    }
}
