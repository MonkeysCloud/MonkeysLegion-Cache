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
 * @requires  ext-sodium
 */

namespace MonkeysLegion\Cache\Serializer;

/**
 * Encrypted serializer decorator.
 *
 * Wraps any CacheSerializerInterface with libsodium `crypto_secretbox`
 * (XSalsa20-Poly1305) encryption for transparent at-rest encryption.
 *
 * Use case: GDPR/PCI compliance, shared Redis instances, multi-tenant.
 */
final class EncryptedSerializer implements CacheSerializerInterface
{
    private readonly string $key;

    /**
     * @param CacheSerializerInterface $inner  The underlying serializer.
     * @param string                    $secret Encryption key (min 32 bytes / SODIUM_CRYPTO_SECRETBOX_KEYBYTES).
     */
    public function __construct(
        private readonly CacheSerializerInterface $inner,
        #[\SensitiveParameter] string $secret,
    ) {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException(
                'ext-sodium is required for EncryptedSerializer.',
            );
        }

        if (strlen($secret) < SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            // Derive a proper key via BLAKE2b if the secret is too short
            $this->key = sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        } else {
            $this->key = substr($secret, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }
    }

    public function serialize(mixed $value): string
    {
        $plaintext = $this->inner->serialize($value);
        $nonce     = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        // Prepend nonce for stateless decryption
        return $nonce . $encrypted;
    }

    public function unserialize(string $data): mixed
    {
        $nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if (strlen($data) < $nonceSize + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Encrypted cache data is too short — possible corruption.');
        }

        $nonce      = substr($data, 0, $nonceSize);
        $ciphertext = substr($data, $nonceSize);
        $plaintext  = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new \RuntimeException(
                'Cache decryption failed — data was tampered with or the encryption key changed.',
            );
        }

        return $this->inner->unserialize($plaintext);
    }

    /**
     * Wipe key material from memory on destruction.
     */
    public function __destruct()
    {
        if (extension_loaded('sodium')) {
            sodium_memzero($this->key);
        }
    }
}
