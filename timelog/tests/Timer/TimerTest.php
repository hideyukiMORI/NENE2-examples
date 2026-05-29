<?php

declare(strict_types=1);

namespace TimeLog\Tests\Timer;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TimeLog\AppFactory;

class TimerTest extends TestCase
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

    /** @param array<string, mixed> $body */
    private function startTimer(array $body): ResponseInterface
    {
        return $this->req('POST', '/timers/start', [], $body);
    }

    // ── start / running state ─────────────────────────────────────────────────

    public function testStartTimerIsRunning(): void
    {
        $res = $this->startTimer(['label' => 'coding', 'start_time' => '2026-06-01T09:00:00+00:00']);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertTrue($data['running']);
        $this->assertNull($data['end_time']);
        $this->assertNull($data['duration_seconds']);
    }

    public function testStartRequiresLabel(): void
    {
        $this->assertSame(422, $this->startTimer(['start_time' => '2026-06-01T09:00:00+00:00'])->getStatusCode());
    }

    public function testOnlyOneTimerCanRun(): void
    {
        $this->startTimer(['label' => 'a']);
        // second start while one runs → 409
        $this->assertSame(409, $this->startTimer(['label' => 'b'])->getStatusCode());
    }

    public function testRunningEndpointEmptyState(): void
    {
        $data = $this->json($this->req('GET', '/timers/running'));
        $this->assertFalse($data['running']);
        $this->assertNull($data['entry']);
    }

    public function testRunningEndpointActive(): void
    {
        $this->startTimer(['label' => 'x']);
        $data = $this->json($this->req('GET', '/timers/running'));
        $this->assertTrue($data['running']);
        $this->assertSame('x', $data['entry']['label']);
    }

    // ── stop / duration ───────────────────────────────────────────────────────

    public function testStopComputesDuration(): void
    {
        $this->startTimer(['label' => 'work', 'start_time' => '2026-06-01T09:00:00+00:00']);
        $res = $this->req('POST', '/timers/stop', [], ['end_time' => '2026-06-01T09:00:30+00:00']);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertFalse($data['running']);
        $this->assertSame(30, $data['duration_seconds']);
    }

    public function testStopAcrossOffsetsNormalises(): void
    {
        // start 09:00:00+09:00 == 00:00:00Z ; end 01:00:00+00:00 → 3600s
        $this->startTimer(['label' => 'tz', 'start_time' => '2026-06-01T09:00:00+09:00']);
        $res = $this->req('POST', '/timers/stop', [], ['end_time' => '2026-06-01T01:00:00+00:00']);
        $this->assertSame(3600, $this->json($res)['duration_seconds']);
    }

    public function testStopWithNoRunningTimerIs409(): void
    {
        $this->assertSame(409, $this->req('POST', '/timers/stop', [], [])->getStatusCode());
    }

    public function testStartAgainAfterStop(): void
    {
        $this->startTimer(['label' => 'a', 'start_time' => '2026-06-01T09:00:00+00:00']);
        $this->req('POST', '/timers/stop', [], ['end_time' => '2026-06-01T09:01:00+00:00']);
        // now a new timer can start
        $this->assertSame(201, $this->startTimer(['label' => 'b'])->getStatusCode());
    }

    // ── summary (julianday aggregation) ──────────────────────────────────────────

    public function testDailySummaryAggregatesSeconds(): void
    {
        // two completed entries on the same day: 60s + 120s = 180s
        $this->startTimer(['label' => 'a', 'start_time' => '2026-06-01T09:00:00+00:00']);
        $this->req('POST', '/timers/stop', [], ['end_time' => '2026-06-01T09:01:00+00:00']); // 60s
        $this->startTimer(['label' => 'b', 'start_time' => '2026-06-01T10:00:00+00:00']);
        $this->req('POST', '/timers/stop', [], ['end_time' => '2026-06-01T10:02:00+00:00']); // 120s

        $data = $this->json($this->req('GET', '/timers/summary'));
        $this->assertSame(1, $data['count']);
        $this->assertSame('2026-06-01', $data['summary'][0]['day']);
        $this->assertSame(180, $data['summary'][0]['total_seconds']);
        $this->assertSame(2, $data['summary'][0]['entry_count']);
    }

    public function testSummaryExcludesRunningTimer(): void
    {
        $this->startTimer(['label' => 'a', 'start_time' => '2026-06-01T09:00:00+00:00']);
        $this->req('POST', '/timers/stop', [], ['end_time' => '2026-06-01T09:01:00+00:00']);
        $this->startTimer(['label' => 'still-running', 'start_time' => '2026-06-01T10:00:00+00:00']);

        $data = $this->json($this->req('GET', '/timers/summary'));
        // only the completed entry counts
        $this->assertSame(1, $data['summary'][0]['entry_count']);
        $this->assertSame(60, $data['summary'][0]['total_seconds']);
    }

    // ── list / filter / get / delete ─────────────────────────────────────────────

    public function testListAndLabelFilter(): void
    {
        $this->startTimer(['label' => 'meeting', 'start_time' => '2026-06-01T09:00:00+00:00']);
        $this->req('POST', '/timers/stop', [], ['end_time' => '2026-06-01T09:30:00+00:00']);
        $this->startTimer(['label' => 'coding', 'start_time' => '2026-06-01T10:00:00+00:00']);

        $this->assertSame(2, $this->json($this->req('GET', '/timers'))['count']);
        $this->assertSame(1, $this->json($this->req('GET', '/timers', [], null, 'label=meet'))['count']);
    }

    public function testDateFilter(): void
    {
        $this->startTimer(['label' => 'a', 'start_time' => '2026-06-01T09:00:00+00:00']);
        $this->req('POST', '/timers/stop', [], ['end_time' => '2026-06-01T09:30:00+00:00']);
        $this->startTimer(['label' => 'b', 'start_time' => '2026-06-02T09:00:00+00:00']);

        $this->assertSame(1, $this->json($this->req('GET', '/timers', [], null, 'date=2026-06-02'))['count']);
    }

    public function testBadDateFilterRejected(): void
    {
        $this->assertSame(422, $this->req('GET', '/timers', [], null, 'date=2026/06/01')->getStatusCode());
    }

    public function testGetAndDelete(): void
    {
        $id = (int) $this->json($this->startTimer(['label' => 'x']))['id'];
        $this->assertSame(200, $this->req('GET', '/timers/' . $id)->getStatusCode());
        $this->assertSame(204, $this->req('DELETE', '/timers/' . $id)->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/timers/' . $id)->getStatusCode());
    }

    public function testStaticRoutesNotCapturedById(): void
    {
        // '/timers/running' must hit the running handler, not show({id}) with id='running'
        $this->assertSame(200, $this->req('GET', '/timers/running')->getStatusCode());
    }
}
