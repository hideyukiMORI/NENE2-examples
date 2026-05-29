<?php

declare(strict_types=1);

namespace EncryptLog\Vault;

/**
 * AES-256-GCM per-field encryption with a fresh nonce per value, plus an
 * HMAC-SHA256 "blind index" that lets equality searches run without
 * decrypting. The encryption key and index key are independent.
 */
final readonly class FieldCrypto
{
    private const string CIPHER = 'aes-256-gcm';
    private const int NONCE_LEN = 12;
    private const int TAG_LEN = 16;

    /**
     * @param string $encKey   32-byte key for AES-256
     * @param string $indexKey separate key for the blind index HMAC
     */
    public function __construct(
        private string $encKey,
        private string $indexKey,
    ) {
    }

    /** @return string base64(nonce ‖ ciphertext ‖ tag) */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Decryption failed.');
        }
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag = substr($raw, -self::TAG_LEN);
        $ct = substr($raw, self::NONCE_LEN, -self::TAG_LEN);

        $pt = openssl_decrypt($ct, self::CIPHER, $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($pt === false) {
            // Tamper / wrong key → authentication failure. Surfaces as 500, not 400.
            throw new \RuntimeException('Decryption failed.');
        }
        return $pt;
    }

    /** Deterministic: same plaintext + key → same index (enables equality search). */
    public function blindIndex(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->indexKey);
    }
}
