<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2
 *
 * @package   MonkeysLegion\Cache\Exception
 * @author    MonkeysCloud <jorge@monkeyscloud.com>
 * @license   MIT
 *
 * @requires  PHP 8.4
 */

namespace MonkeysLegion\Cache\Exception;

use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * PSR-16 compliant InvalidArgumentException.
 *
 * Thrown when a cache key is invalid per PSR-16 spec.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements PsrInvalidArgumentException
{
}
