<?php

declare(strict_types=1);

namespace DeadLetterLog\Tests\Queue;

use DeadLetterLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class QueueTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        assert($schema !== false);
        $pdo->exec($schema);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $headers */
    private function req(string $method, string $path, array $headers = [], mixed $body = null, ?string $query = null): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        if ($query !== null) {
            parse_str($query, $q);
            $request = $request->withQueryParams($q);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        assert(is_array($decoded));
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function enqueue(string $queue, array $body, string $now = '2026-06-01 00:00:00'): array
    {
        $res = $this->req('POST', "/queues/{$queue}/messages", ['X-Now' => $now], $body);
        assert($res->getStatusCode() === 201);
        return $this->json($res);
    }

    private function claim(string $queue, string $now): ResponseInterface
    {
        return $this->req('POST', "/queues/{$queue}/claim", ['X-Now' => $now]);
    }

    // ── enqueue / validation ──────────────────────────────────────────────────

    public function testEnqueueStartsPending(): void
    {
        $msg = $this->enqueue('emails', ['payload' => '{"to":"a@b.c"}']);
        $this->assertSame('pending', $msg['status']);
        $this->assertSame(3, $msg['max_retries']);
        $this->assertSame(0, $msg['retry_count']);
    }

    public function testPayloadRequired(): void
    {
        $this->assertSame(422, $this->req('POST', '/queues/emails/messages', [], ['max_retries' => 3])->getStatusCode());
    }

    public function testMaxRetriesRange(): void
    {
        $this->assertSame(422, $this->req('POST', '/queues/emails/messages', [], ['payload' => 'x', 'max_retries' => 0])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/queues/emails/messages', [], ['payload' => 'x', 'max_retries' => 11])->getStatusCode());
        $this->assertSame(422, $this->req('POST', '/queues/emails/messages', [], ['payload' => 'x', 'max_retries' => '3'])->getStatusCode());
    }

    public function testInvalidQueueNameIs404(): void
    {
        $this->assertSame(404, $this->req('POST', '/queues/has%20space/messages', [], ['payload' => 'x'])->getStatusCode());
    }

    // ── claim ──────────────────────────────────────────────────────────────────

    public function testClaimMovesToProcessing(): void
    {
        $this->enqueue('emails', ['payload' => 'x']);
        $res = $this->claim('emails', '2026-06-01 00:00:01');
        $data = $this->json($res);
        $this->assertTrue($data['claimed']);
        $this->assertSame('processing', $data['message']['status']);
    }

    public function testClaimEmptyQueue(): void
    {
        $data = $this->json($this->claim('emails', '2026-06-01 00:00:01'));
        $this->assertFalse($data['claimed']);
        $this->assertNull($data['message']);
    }

    public function testClaimIsFifo(): void
    {
        $this->enqueue('q', ['payload' => 'first']);
        $this->enqueue('q', ['payload' => 'second']);
        $first = $this->json($this->claim('q', '2026-06-01 00:00:01'));
        $this->assertSame('first', $first['message']['payload']);
    }

    public function testClaimIsolatedPerQueue(): void
    {
        $this->enqueue('a', ['payload' => 'in-a']);
        // claiming from queue 'b' sees nothing
        $this->assertFalse($this->json($this->claim('b', '2026-06-01 00:00:01'))['claimed']);
    }

    // ── failure / backoff / DLQ ──────────────────────────────────────────────────

    public function testFailSchedulesRetryWithBackoff(): void
    {
        $id = (int) $this->enqueue('q', ['payload' => 'x', 'max_retries' => 5])['id'];
        $this->claim('q', '2026-06-01 00:00:00');
        $res = $this->req('POST', "/queues/q/messages/{$id}/fail", ['X-Now' => '2026-06-01 00:00:00'], ['error' => 'boom']);
        $data = $this->json($res);
        $this->assertSame('pending', $data['status']);
        $this->assertSame(1, $data['retry_count']);
        $this->assertSame('boom', $data['last_error']);
        // first failure → 2^1 = 2s backoff
        $this->assertSame('2026-06-01 00:00:02', $data['retry_after']);
    }

    public function testRetryAfterBlocksImmediateClaim(): void
    {
        $id = (int) $this->enqueue('q', ['payload' => 'x', 'max_retries' => 5])['id'];
        $this->claim('q', '2026-06-01 00:00:00');
        $this->req('POST', "/queues/q/messages/{$id}/fail", ['X-Now' => '2026-06-01 00:00:00'], ['error' => 'boom']);
        // retry_after is 00:00:02 — claiming at 00:00:01 finds nothing
        $this->assertFalse($this->json($this->claim('q', '2026-06-01 00:00:01'))['claimed']);
        // claiming at 00:00:03 finds it again
        $this->assertTrue($this->json($this->claim('q', '2026-06-01 00:00:03'))['claimed']);
    }

    public function testExhaustedRetriesMoveToDead(): void
    {
        $id = (int) $this->enqueue('q', ['payload' => 'x', 'max_retries' => 2])['id'];
        // attempt 1
        $this->claim('q', '2026-06-01 00:00:00');
        $this->req('POST', "/queues/q/messages/{$id}/fail", ['X-Now' => '2026-06-01 00:00:00'], ['error' => 'e1']);
        // attempt 2 (after backoff) → exhausted → dead
        $this->claim('q', '2026-06-01 00:01:00');
        $res = $this->req('POST', "/queues/q/messages/{$id}/fail", ['X-Now' => '2026-06-01 00:01:00'], ['error' => 'e2']);
        $data = $this->json($res);
        $this->assertSame('dead', $data['status']);
        $this->assertSame(2, $data['retry_count']);
        $this->assertSame('e2', $data['last_error']);
    }

    public function testFailOnNonProcessingIs409(): void
    {
        $id = (int) $this->enqueue('q', ['payload' => 'x'])['id'];
        // not claimed → still pending → fail is a state conflict
        $this->assertSame(409, $this->req('POST', "/queues/q/messages/{$id}/fail", [], ['error' => 'x'])->getStatusCode());
    }

    // ── succeed ────────────────────────────────────────────────────────────────────

    public function testSucceed(): void
    {
        $id = (int) $this->enqueue('q', ['payload' => 'x'])['id'];
        $this->claim('q', '2026-06-01 00:00:01');
        $res = $this->req('POST', "/queues/q/messages/{$id}/succeed", ['X-Now' => '2026-06-01 00:00:02']);
        $this->assertSame('succeeded', $this->json($res)['status']);
    }

    // ── replay ────────────────────────────────────────────────────────────────────

    public function testReplayDeadMessage(): void
    {
        $id = (int) $this->enqueue('q', ['payload' => 'x', 'max_retries' => 1])['id'];
        $this->claim('q', '2026-06-01 00:00:00');
        $this->req('POST', "/queues/q/messages/{$id}/fail", ['X-Now' => '2026-06-01 00:00:00'], ['error' => 'boom']); // → dead

        $res = $this->req('POST', "/queues/q/messages/{$id}/replay", ['X-Now' => '2026-06-01 00:05:00']);
        $data = $this->json($res);
        $this->assertSame('pending', $data['status']);
        $this->assertSame(0, $data['retry_count']); // fresh budget
        $this->assertNull($data['retry_after']);
        $this->assertNull($data['last_error']);
        // claimable again
        $this->assertTrue($this->json($this->claim('q', '2026-06-01 00:05:01'))['claimed']);
    }

    public function testReplayNonDeadIs409(): void
    {
        $id = (int) $this->enqueue('q', ['payload' => 'x'])['id'];
        $this->assertSame(409, $this->req('POST', "/queues/q/messages/{$id}/replay")->getStatusCode());
    }

    public function testReplayUnknownIs404(): void
    {
        $this->assertSame(404, $this->req('POST', '/queues/q/messages/999/replay')->getStatusCode());
    }

    // ── list / get ──────────────────────────────────────────────────────────────────

    public function testListAndStatusFilter(): void
    {
        $this->enqueue('q', ['payload' => 'a']);
        $b = (int) $this->enqueue('q', ['payload' => 'b'])['id'];
        $this->claim('q', '2026-06-01 00:00:01'); // claims 'a'
        $this->assertSame(2, $this->json($this->req('GET', '/queues/q/messages'))['count']);
        $pending = $this->json($this->req('GET', '/queues/q/messages', [], null, 'status=pending'));
        $this->assertSame(1, $pending['count']);
        $this->assertSame($b, $pending['messages'][0]['id']);
    }

    public function testGetUnknownIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/queues/q/messages/999')->getStatusCode());
    }
}
