<?php

declare(strict_types=1);

namespace Queue\Tests\Queue;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Queue\RouteRegistrar;
use Queue\SqliteJobRepository;

final class QueueApiTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/queuelog-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        unset($pdo);

        $dbConfig = new DatabaseConfig(
            url:         null,
            environment: 'test',
            adapter:     'sqlite',
            host:        '',
            port:        1,
            name:        $this->dbFile,
            user:        '',
            password:    '',
            charset:     '',
        );

        $factory  = new PdoConnectionFactory($dbConfig);
        $executor = new PdoDatabaseQueryExecutor($factory);
        $psr17    = new Psr17Factory();
        $json     = new JsonResponseFactory($psr17, $psr17);
        $problems = new ProblemDetailsResponseFactory($psr17, $psr17);
        $repo     = new SqliteJobRepository($executor);

        $registrar = new RouteRegistrar($repo, $json, $problems);

        $this->app = (new RuntimeApplicationFactory(
            $psr17,
            $psr17,
            routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
        ))->create();
    }

    protected function tearDown(): void
    {
        if ($this->dbFile !== '' && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function req(string $method, string $uri, mixed $body = null): ResponseInterface
    {
        $req = new ServerRequest($method, $uri);
        if ($body !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')
                       ->withBody((new Psr17Factory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($req);
    }

    private function decode(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- POST /jobs ---

    public function testCreateJobDefaults(): void
    {
        $res  = $this->req('POST', '/jobs', ['type' => 'send-email']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('send-email', $body['type']);
        self::assertSame('medium', $body['priority']);
        self::assertSame('pending', $body['status']);
        self::assertNull($body['worker_id']);
        self::assertSame(0, $body['retry_count']);
        self::assertSame(3, $body['max_retries']);
        self::assertNull($body['idempotency_key']);
    }

    public function testCreateJobWithPriorityAndPayload(): void
    {
        $res  = $this->req('POST', '/jobs', [
            'type'     => 'resize-image',
            'priority' => 'high',
            'payload'  => ['url' => 'https://example.com/img.jpg'],
        ]);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('high', $body['priority']);
        self::assertSame('https://example.com/img.jpg', $body['payload']['url']);
    }

    public function testCreateJobMissingTypeReturns422(): void
    {
        $res = $this->req('POST', '/jobs', ['priority' => 'low']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testCreateJobInvalidPriorityReturns422(): void
    {
        $res = $this->req('POST', '/jobs', ['type' => 'task', 'priority' => 'urgent']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testCreateJobWithCustomMaxRetries(): void
    {
        $res  = $this->req('POST', '/jobs', ['type' => 'fragile', 'max_retries' => 1]);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame(1, $body['max_retries']);
    }

    // --- GET /jobs/{id} ---

    public function testGetJob(): void
    {
        $this->req('POST', '/jobs', ['type' => 'job-a']);
        $res  = $this->req('GET', '/jobs/1');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('job-a', $body['type']);
    }

    public function testGetJobNotFoundReturns404(): void
    {
        $res = $this->req('GET', '/jobs/999');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- GET /jobs (list with optional ?status filter) ---

    public function testListAllJobs(): void
    {
        $this->req('POST', '/jobs', ['type' => 'task-a']);
        $this->req('POST', '/jobs', ['type' => 'task-b']);

        $res  = $this->req('GET', '/jobs');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(2, $body['jobs']);
    }

    public function testListFilterByStatus(): void
    {
        $this->req('POST', '/jobs', ['type' => 'alpha']);
        $this->req('POST', '/jobs', ['type' => 'beta']);
        // Claim one
        $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);

        $pendingRes  = $this->req('GET', '/jobs?status=pending');
        $pendingBody = $this->decode($pendingRes);

        $runningRes  = $this->req('GET', '/jobs?status=running');
        $runningBody = $this->decode($runningRes);

        self::assertCount(1, $pendingBody['jobs']);
        self::assertCount(1, $runningBody['jobs']);
    }

    public function testListInvalidStatusReturns422(): void
    {
        $res = $this->req('GET', '/jobs?status=unknown');
        self::assertSame(422, $res->getStatusCode());
    }

    // --- POST /jobs/claim ---

    public function testClaimPicksHighestPriority(): void
    {
        $this->req('POST', '/jobs', ['type' => 'low-job',  'priority' => 'low']);
        $this->req('POST', '/jobs', ['type' => 'high-job', 'priority' => 'high']);
        $this->req('POST', '/jobs', ['type' => 'med-job',  'priority' => 'medium']);

        $res  = $this->req('POST', '/jobs/claim', ['worker_id' => 'worker-1']);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('high-job', $body['type']);
        self::assertSame('running', $body['status']);
        self::assertSame('worker-1', $body['worker_id']);
    }

    public function testClaimSetsClaimedAt(): void
    {
        $this->req('POST', '/jobs', ['type' => 'task']);
        $res  = $this->req('POST', '/jobs/claim', ['worker_id' => 'w-x']);
        $body = $this->decode($res);

        self::assertNotNull($body['claimed_at']);
    }

    public function testClaimWhenNoPendingReturns404(): void
    {
        $res = $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);
        self::assertSame(404, $res->getStatusCode());
    }

    public function testClaimMissingWorkerIdReturns422(): void
    {
        $this->req('POST', '/jobs', ['type' => 'task']);
        $res = $this->req('POST', '/jobs/claim', (object) []);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- POST /jobs/{id}/complete ---

    public function testCompleteJob(): void
    {
        $this->req('POST', '/jobs', ['type' => 'work']);
        $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);

        $res  = $this->req('POST', '/jobs/1/complete', (object) []);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('completed', $body['status']);
    }

    public function testCompleteJobNotRunningReturns409(): void
    {
        $this->req('POST', '/jobs', ['type' => 'work']);
        // Not claimed — still pending
        $res = $this->req('POST', '/jobs/1/complete', (object) []);
        self::assertSame(409, $res->getStatusCode());
    }

    public function testCompletedJobCannotBeClaimedAgain(): void
    {
        $this->req('POST', '/jobs', ['type' => 'work']);
        $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);
        $this->req('POST', '/jobs/1/complete', (object) []);

        // No more pending jobs
        $res = $this->req('POST', '/jobs/claim', ['worker_id' => 'w2']);
        self::assertSame(404, $res->getStatusCode());
    }

    // --- POST /jobs/{id}/fail ---

    public function testFailJobNotRunningReturns409(): void
    {
        $this->req('POST', '/jobs', ['type' => 'risky']);
        $res = $this->req('POST', '/jobs/1/fail', ['error' => 'Crash']);
        self::assertSame(409, $res->getStatusCode());
    }

    // --- Retry logic ---

    public function testFailJobWithRetriesRemainingRequeues(): void
    {
        // default max_retries=3, so first failure requeues
        $this->req('POST', '/jobs', ['type' => 'flaky']);
        $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);

        $res  = $this->req('POST', '/jobs/1/fail', ['error' => 'Transient error']);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('pending', $body['status']);
        self::assertSame(1, $body['retry_count']);
        self::assertSame('Transient error', $body['error']);
        self::assertNull($body['worker_id']);
        self::assertNull($body['claimed_at']);
    }

    public function testRetryJobCanBeClaimedAgain(): void
    {
        $this->req('POST', '/jobs', ['type' => 'flaky']);
        $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);
        $this->req('POST', '/jobs/1/fail', ['error' => 'err1']);

        // Job requeued — now claim it again
        $res  = $this->req('POST', '/jobs/claim', ['worker_id' => 'w2']);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('flaky', $body['type']);
        self::assertSame('running', $body['status']);
        self::assertSame(1, $body['retry_count']);
    }

    public function testFailJobExhaustsRetriesAndTransitionsToFailed(): void
    {
        // max_retries=1 → fail twice → second fail is terminal
        $this->req('POST', '/jobs', ['type' => 'fragile', 'max_retries' => 1]);

        // Attempt 1: fail → requeue (retry_count becomes 1)
        $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);
        $res1  = $this->req('POST', '/jobs/1/fail', ['error' => 'err1']);
        $body1 = $this->decode($res1);
        self::assertSame('pending', $body1['status']);
        self::assertSame(1, $body1['retry_count']);

        // Attempt 2: fail → exhausted (retry_count 1 >= max_retries 1)
        $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);
        $res2  = $this->req('POST', '/jobs/1/fail', ['error' => 'final-err']);
        $body2 = $this->decode($res2);
        self::assertSame('failed', $body2['status']);
        self::assertSame(1, $body2['retry_count']);
        self::assertSame('final-err', $body2['error']);
    }

    public function testFailJobWithZeroRetriesIsImmediatelyFailed(): void
    {
        $this->req('POST', '/jobs', ['type' => 'no-retry', 'max_retries' => 0]);
        $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);

        $res  = $this->req('POST', '/jobs/1/fail', ['error' => 'instant-fail']);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('failed', $body['status']);
        self::assertSame(0, $body['retry_count']);
    }

    // --- Idempotency ---

    public function testIdempotencyKeyDeduplicatesCreate(): void
    {
        $first  = $this->req('POST', '/jobs', [
            'type'            => 'send-email',
            'idempotency_key' => 'key-abc-123',
        ]);
        $second = $this->req('POST', '/jobs', [
            'type'            => 'send-email',
            'idempotency_key' => 'key-abc-123',
        ]);

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame($this->decode($first)['id'], $this->decode($second)['id']);
    }

    public function testIdempotencyKeyIsStoredAndReturned(): void
    {
        $res  = $this->req('POST', '/jobs', [
            'type'            => 'send-email',
            'idempotency_key' => 'unique-key-xyz',
        ]);
        $body = $this->decode($res);

        self::assertSame('unique-key-xyz', $body['idempotency_key']);
    }

    public function testDifferentIdempotencyKeysCreateSeparateJobs(): void
    {
        $this->req('POST', '/jobs', ['type' => 'task', 'idempotency_key' => 'key-1']);
        $this->req('POST', '/jobs', ['type' => 'task', 'idempotency_key' => 'key-2']);

        $res  = $this->req('GET', '/jobs');
        $body = $this->decode($res);

        self::assertCount(2, $body['jobs']);
    }

    // --- Priority ordering tie-break ---

    public function testSamePriorityFIFO(): void
    {
        $this->req('POST', '/jobs', ['type' => 'first',  'priority' => 'medium']);
        $this->req('POST', '/jobs', ['type' => 'second', 'priority' => 'medium']);

        $res1 = $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);
        $res2 = $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);

        self::assertSame('first', $this->decode($res1)['type']);
        self::assertSame('second', $this->decode($res2)['type']);
    }

    // --- Critical priority ---

    public function testCriticalPriorityBeatsHigh(): void
    {
        $this->req('POST', '/jobs', ['type' => 'high-job',     'priority' => 'high']);
        $this->req('POST', '/jobs', ['type' => 'critical-job', 'priority' => 'critical']);

        $res  = $this->req('POST', '/jobs/claim', ['worker_id' => 'w1']);
        $body = $this->decode($res);

        self::assertSame('critical-job', $body['type']);
    }
}
