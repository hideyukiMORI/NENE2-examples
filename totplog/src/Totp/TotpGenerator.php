<?php

declare(strict_types=1);

namespace TotpLog\Totp;

class TotpGenerator
{
    private const int DIGITS = 6;
    private const int PERIOD = 30;
    private const string BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * Compute the TOTP code for a given secret and time step.
     * Exposed as public to enable test code generation.
     */
    public function computeCode(string $base32Secret, int $timeStep): string
    {
        $secret = $this->base32Decode($base32Secret);

        // Pack time step as 8-byte big-endian
        $msg = pack('N*', 0) . pack('N*', $timeStep);
        $hash = hash_hmac('sha1', $msg, $secret, true);

        // Dynamic truncation (RFC 4226 §5.4)
        $offset = ord($hash[19]) & 0x0F;
        $code = ((ord($hash[$offset]) & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($code % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function currentTimeStep(): int
    {
        return (int) floor(time() / self::PERIOD);
    }

    /**
     * Verify a TOTP code within ±window time steps.
     * Returns the matching time step or null on failure.
     */
    public function verify(string $base32Secret, string $code, int $window = 1): ?int
    {
        $t = $this->currentTimeStep();
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $t + $offset;
            $expected = $this->computeCode($base32Secret, $step);
            if (hash_equals($expected, $code)) {
                return $step;
            }
        }
        return null;
    }

    public function buildOtpAuthUri(string $secret, string $account, string $issuer = 'NENE2'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    private function base32Encode(string $bytes): string
    {
        $alphabet = self::BASE32_CHARS;
        $output = '';
        $bitBuffer = 0;
        $bitCount = 0;

        foreach (str_split($bytes) as $byte) {
            $bitBuffer = ($bitBuffer << 8) | ord($byte);
            $bitCount += 8;
            while ($bitCount >= 5) {
                $bitCount -= 5;
                $output .= $alphabet[($bitBuffer >> $bitCount) & 0x1F];
            }
        }
        if ($bitCount > 0) {
            $output .= $alphabet[($bitBuffer << (5 - $bitCount)) & 0x1F];
        }

        return $output;
    }

    private function base32Decode(string $encoded): string
    {
        $alphabet = self::BASE32_CHARS;
        $encoded = strtoupper(str_replace([' ', '-'], '', $encoded));
        $output = '';
        $bitBuffer = 0;
        $bitCount = 0;

        foreach (str_split($encoded) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $bitBuffer = ($bitBuffer << 5) | $pos;
            $bitCount += 5;
            if ($bitCount >= 8) {
                $bitCount -= 8;
                $output .= chr(($bitBuffer >> $bitCount) & 0xFF);
            }
        }

        return $output;
    }
}
