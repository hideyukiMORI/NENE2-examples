# encryptlog — FT187: Encrypted Field Storage

> **NENE2 Field Trial 187** — AES-256-GCM per-field encryption + HMAC-SHA256 blind index for searchable PII storage.

---

## What This Trial Proves

Storing sensitive fields encrypted at rest while keeping them searchable:
1. **AES-256-GCM AEAD** — per-record nonce (12 bytes) + authentication tag (16 bytes) — tamper detection included
2. **Blind index** — `HMAC-SHA256(email, indexKey)` enables `WHERE email_idx = ?` without decryption
3. **Ciphertext never in API responses** — VO / `toArray()` layer always returns plaintext
4. **Tamper detection is 500** — tag mismatch throws `\RuntimeException`, not returns 400
5. **IDOR prevention** — all reads/writes scope `WHERE id AND user_id`

---

## API

| Method | Path | Header | Description |
|---|---|---|---|
| `POST` | `/vault` | `X-User-Id` | Create encrypted record |
| `GET` | `/vault` | `X-User-Id` | List own records (decrypted) |
| `GET` | `/vault/{id}` | `X-User-Id` | Fetch single record (decrypted) |
| `PATCH` | `/vault/{id}` | `X-User-Id` | Partial update (re-encrypts) |
| `DELETE` | `/vault/{id}` | `X-User-Id` | Delete record |
| `GET` | `/vault/search?email=...` | `X-User-Id` | Search by blind index |

---

## Ciphertext Format

```
base64( nonce[12] ‖ ciphertext[variable] ‖ tag[16] )
```

Stored as a single `TEXT` column. Same plaintext → **different ciphertext** every time (fresh nonce per encrypt call).

---

## Core Pattern: FieldCrypto

```php
final readonly class FieldCrypto
{
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12); // fresh per-value nonce
        $tag   = '';
        $ct    = openssl_encrypt($plaintext, 'aes-256-gcm', $this->encKey,
                                 OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        return base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $encoded): string
    {
        // ...extract nonce, ciphertext, tag...
        $pt = openssl_decrypt($ct, 'aes-256-gcm', $this->encKey, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($pt === false) {
            throw new \RuntimeException('Decryption failed.'); // → 500, not 400
        }
        return $pt;
    }

    // Deterministic: same plaintext + same key → same index (searchable)
    public function blindIndex(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->indexKey);
    }
}
```

---

## Core Pattern: Blind Index Search

```php
// Write: store HMAC alongside ciphertext
'email_enc' => $this->crypto->encrypt($email),
'email_idx' => $this->crypto->blindIndex($email), // deterministic

// Search: recompute HMAC from query param — no decryption
$idx = $this->crypto->blindIndex($email);
WHERE user_id = ? AND email_idx = ?

// Update: must reindex when email changes
'email_enc' => $this->crypto->encrypt($newEmail),
'email_idx' => $this->crypto->blindIndex($newEmail), // ← keep in sync
```

---

## Test Results

```
51 tests / 110 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Nonce | `random_bytes(12)` per encryption — never reuse |
| Tag length | 16 bytes — maximum GCM authentication strength |
| Blind index | `hash_hmac('sha256', $plain, $indexKey)` — separate key from enc key |
| Tamper detection | `openssl_decrypt` returns `false` → throw `\RuntimeException` → 500 |
| Ciphertext in API | Never — `toArray()` always returns plaintext |
| IDOR | `WHERE id = ? AND user_id = ?` — both conditions mandatory |
| Key rotation | `encKey` rotatable (re-encrypt rows); `indexKey` requires full rehash |

Full guide: [`docs/howto/encrypted-field-storage.md`](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/encrypted-field-storage.md) in the NENE2 repository.
