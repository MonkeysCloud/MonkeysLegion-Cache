<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2 — Helper functions.
 *
 * In v2, prefer injecting CacheManager via DI over global helpers.
 * These are provided for quick scripts and convenience only.
 *
 * @requires PHP 8.4
 */

use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Cache\CacheStoreInterface;

if (!function_exists('cache_manager')) {
    /**
     * Get or set the global CacheManager instance.
     */
    function cache_manager(?CacheManager $manager = null): CacheManager
    {
        static $instance = null;

        if ($manager !== null) {
            $instance = $manager;
        }

        if ($instance === null) {
            throw new \RuntimeException(
                'CacheManager not initialized. Call cache_manager($manager) first or use DI.',
            );
        }

        return $instance;
    }
}

if (!function_exists('cache_store')) {
    /**
     * Get a cache store by name (or the default).
     */
    function cache_store(?string $name = null): CacheStoreInterface
    {
        return cache_manager()->store($name);
    }
}
