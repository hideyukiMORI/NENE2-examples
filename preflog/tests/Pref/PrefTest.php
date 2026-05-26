<?php

declare(strict_types=1);

namespace PrefLog\Tests\Pref;

use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use PrefLog\AppFactory;

final class PrefTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Alice', '2026-01-01T00:00:00+00:00')");
        $this->pdo->exec("INSERT INTO users (name, created_at) VALUES ('Bob', '2026-01-01T00:00:00+00:00')");

        $this->router = AppFactory::createSqliteApp($this->pdo);
        $this->psr17 = new Psr17Factory();
    }

    private function get(string $path, array $headers = []): array
    {
        $request = new ServerRequest('GET', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    private function put(string $path, array $body, array $headers = []): array
    {
        $request = new ServerRequest('PUT', $path, array_merge(['Content-Type' => 'application/json'], $headers));
        $json = empty($body) ? '{}' : (json_encode($body) ?: '{}');
        $request = $request->withBody($this->psr17->createStream($json));
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    private function delete(string $path, array $headers = []): array
    {
        $request = new ServerRequest('DELETE', $path, $headers);
        $response = $this->router->handle($request);
        $data = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($data) ? $data : []];
    }

    // --- GET /users/{id}/preferences ---

    public function testGetPreferencesReturnsAllKeysWithDefaults(): void
    {
        $result = $this->get('/users/1/preferences');
        $this->assertSame(200, $result['status']);
        $prefs = $result['body']['preferences'];
        $this->assertIsArray($prefs);
        $this->assertCount(5, $prefs);

        $keys = array_column($prefs, 'key');
        $this->assertContains('theme', $keys);
        $this->assertContains('language', $keys);
        $this->assertContains('notifications_enabled', $keys);
        $this->assertContains('items_per_page', $keys);
        $this->assertContains('timezone', $keys);
    }

    public function testGetPreferencesDefaultValuesCorrect(): void
    {
        $result = $this->get('/users/1/preferences');
        $prefs = array_column($result['body']['preferences'], null, 'key');

        $this->assertSame('light', $prefs['theme']['value']);
        $this->assertSame('en', $prefs['language']['value']);
        $this->assertSame('true', $prefs['notifications_enabled']['value']);
        $this->assertSame('20', $prefs['items_per_page']['value']);
        $this->assertSame('UTC', $prefs['timezone']['value']);

        foreach ($prefs as $pref) {
            $this->assertTrue($pref['is_default']);
            $this->assertNull($pref['updated_at']);
        }
    }

    public function testGetPreferencesShowsStoredValues(): void
    {
        $this->put('/users/1/preferences/theme', ['value' => 'dark'], ['X-User-Id' => '1']);
        $result = $this->get('/users/1/preferences');
        $prefs = array_column($result['body']['preferences'], null, 'key');

        $this->assertSame('dark', $prefs['theme']['value']);
        $this->assertFalse($prefs['theme']['is_default']);
        $this->assertNotNull($prefs['theme']['updated_at']);

        // Other keys still default
        $this->assertTrue($prefs['language']['is_default']);
    }

    public function testGetPreferencesUserNotFound(): void
    {
        $result = $this->get('/users/999/preferences');
        $this->assertSame(404, $result['status']);
    }

    // --- PUT /users/{id}/preferences/{key} ---

    public function testPutPreferenceSuccess(): void
    {
        $result = $this->put('/users/1/preferences/theme', ['value' => 'dark'], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('theme', $result['body']['key']);
        $this->assertSame('dark', $result['body']['value']);
    }

    public function testPutPreferenceUpdatesExisting(): void
    {
        $this->put('/users/1/preferences/theme', ['value' => 'dark'], ['X-User-Id' => '1']);
        $result = $this->put('/users/1/preferences/theme', ['value' => 'system'], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('system', $result['body']['value']);

        // Verify only one row exists
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM user_preferences WHERE user_id = 1 AND pref_key = 'theme'");
        assert($stmt instanceof \PDOStatement);
        $count = (int) $stmt->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testPutPreferenceUnknownKeyReturns422(): void
    {
        $result = $this->put('/users/1/preferences/invalid_key', ['value' => 'foo'], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);
        $this->assertArrayHasKey('valid_keys', $result['body']);
    }

    public function testPutPreferenceInvalidValueReturns422(): void
    {
        $result = $this->put('/users/1/preferences/theme', ['value' => 'neon'], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);
    }

    public function testPutPreferenceItemsPerPageValidation(): void
    {
        $result = $this->put('/users/1/preferences/items_per_page', ['value' => '50'], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);

        $result2 = $this->put('/users/1/preferences/items_per_page', ['value' => '200'], ['X-User-Id' => '1']);
        $this->assertSame(422, $result2['status']);

        $result3 = $this->put('/users/1/preferences/items_per_page', ['value' => '1'], ['X-User-Id' => '1']);
        $this->assertSame(422, $result3['status']);
    }

    public function testPutPreferenceNotificationsEnabledValidation(): void
    {
        $result = $this->put('/users/1/preferences/notifications_enabled', ['value' => 'false'], ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);

        $result2 = $this->put('/users/1/preferences/notifications_enabled', ['value' => 'yes'], ['X-User-Id' => '1']);
        $this->assertSame(422, $result2['status']);
    }

    public function testPutPreferenceOtherUserReturns403(): void
    {
        $result = $this->put('/users/1/preferences/theme', ['value' => 'dark'], ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testPutPreferenceUserNotFound(): void
    {
        $result = $this->put('/users/999/preferences/theme', ['value' => 'dark'], ['X-User-Id' => '999']);
        $this->assertSame(404, $result['status']);
    }

    public function testPutPreferenceMissingValueReturns422(): void
    {
        $result = $this->put('/users/1/preferences/theme', [], ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);
    }

    // --- DELETE /users/{id}/preferences/{key} ---

    public function testDeletePreferenceReturnsDefault(): void
    {
        $this->put('/users/1/preferences/theme', ['value' => 'dark'], ['X-User-Id' => '1']);
        $result = $this->delete('/users/1/preferences/theme', ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('light', $result['body']['value']);
        $this->assertTrue($result['body']['is_default']);
    }

    public function testDeletePreferenceWhenAlreadyDefaultStillSucceeds(): void
    {
        $result = $this->delete('/users/1/preferences/theme', ['X-User-Id' => '1']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('light', $result['body']['value']);
    }

    public function testDeletePreferenceOtherUserReturns403(): void
    {
        $result = $this->delete('/users/1/preferences/theme', ['X-User-Id' => '2']);
        $this->assertSame(403, $result['status']);
    }

    public function testDeletePreferenceUnknownKeyReturns422(): void
    {
        $result = $this->delete('/users/1/preferences/bad_key', ['X-User-Id' => '1']);
        $this->assertSame(422, $result['status']);
    }

    public function testDeletePreferenceUserNotFound(): void
    {
        $result = $this->delete('/users/999/preferences/theme', ['X-User-Id' => '999']);
        $this->assertSame(404, $result['status']);
    }

    public function testPreferenceIsolatedBetweenUsers(): void
    {
        $this->put('/users/1/preferences/theme', ['value' => 'dark'], ['X-User-Id' => '1']);

        $result1 = $this->get('/users/1/preferences');
        $result2 = $this->get('/users/2/preferences');

        $prefs1 = array_column($result1['body']['preferences'], null, 'key');
        $prefs2 = array_column($result2['body']['preferences'], null, 'key');

        $this->assertSame('dark', $prefs1['theme']['value']);
        $this->assertSame('light', $prefs2['theme']['value']); // Bob still default
    }

    public function testAllPreferenceKeysCanBeSet(): void
    {
        $values = [
            'theme' => 'dark',
            'language' => 'ja',
            'notifications_enabled' => 'false',
            'items_per_page' => '50',
            'timezone' => 'Asia/Tokyo',
        ];
        foreach ($values as $key => $value) {
            $result = $this->put("/users/1/preferences/$key", ['value' => $value], ['X-User-Id' => '1']);
            $this->assertSame(200, $result['status'], "Failed to set $key=$value");
        }

        $listResult = $this->get('/users/1/preferences');
        $prefs = array_column($listResult['body']['preferences'], null, 'key');
        foreach ($values as $key => $value) {
            $this->assertSame($value, $prefs[$key]['value']);
            $this->assertFalse($prefs[$key]['is_default']);
        }
    }
}
