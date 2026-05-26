<?php

declare(strict_types=1);

namespace OAuthLog\OAuth;

/**
 * Simulates an external OAuth2 provider without real HTTP calls.
 * Accepts any non-empty code except those beginning with "invalid".
 */
class MockOAuthProvider
{
    private const string PROVIDER_NAME = 'mock';

    public function buildAuthUrl(string $state): string
    {
        return 'https://mock.oauth.example.com/authorize?response_type=code&client_id=mock-client&state=' . urlencode($state);
    }

    /**
     * @return array{provider: string, subject: string, name: string, email: string}|null
     */
    public function exchangeCode(string $code): ?array
    {
        if ($code === '' || str_starts_with($code, 'invalid')) {
            return null;
        }

        // Derive a stable fake user identity from the code
        $subject = 'sub_' . substr(md5($code), 0, 12);
        return [
            'provider' => self::PROVIDER_NAME,
            'subject'  => $subject,
            'name'     => 'User-' . substr($code, 0, 16),
            'email'    => substr($code, 0, 16) . '@mock.example.com',
        ];
    }
}
