<?php

declare(strict_types=1);

namespace ProjTrack\Tests\Project;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use ProjTrack\AppFactory;
use Psr\Http\Message\ResponseInterface;

class ProjTrackTest extends TestCase
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

    /** @param array<string, mixed> $query */
    private function req(string $method, string $path, mixed $body = null, array $query = []): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
        if ($query !== []) {
            $request = $request->withQueryParams($query);
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

    private function makeProject(string $name = 'Proj'): int
    {
        return (int) $this->json($this->req('POST', '/projects', ['name' => $name]))['id'];
    }

    private function makeTask(int $projectId, string $title, ?string $status = null, ?int $priority = null): int
    {
        $body = ['title' => $title];
        if ($status !== null) {
            $body['status'] = $status;
        }
        if ($priority !== null) {
            $body['priority'] = $priority;
        }
        return (int) $this->json($this->req('POST', "/projects/{$projectId}/tasks", $body))['id'];
    }

    // ── projects ──────────────────────────────────────────────────────────

    public function testCreateAndGetProject(): void
    {
        $id = $this->makeProject('Apollo');
        $res = $this->req('GET', '/projects/' . $id);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('Apollo', $this->json($res)['name']);
    }

    public function testCreateProjectValidation(): void
    {
        $this->assertSame(422, $this->req('POST', '/projects', ['name' => ''])->getStatusCode());
    }

    public function testProjectsPaginated(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeProject('P' . $i);
        }
        $data = $this->json($this->req('GET', '/projects', null, ['limit' => '2', 'offset' => '0']));
        $this->assertSame(5, $data['total']);
        $this->assertCount(2, $data['items']);
        $this->assertSame(2, $data['limit']);
    }

    public function testDeleteProjectCascadesTasks(): void
    {
        $pid = $this->makeProject();
        $this->makeTask($pid, 'T1');
        $this->assertSame(204, $this->req('DELETE', '/projects/' . $pid)->getStatusCode());
        $this->assertSame(404, $this->req('GET', '/projects/' . $pid)->getStatusCode());
        // tasks gone with the project
        $this->assertSame(404, $this->req('GET', "/projects/{$pid}/tasks")->getStatusCode());
    }

    // ── nested validation ───────────────────────────────────────────────────

    public function testTasksUnderUnknownProjectIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/projects/999/tasks')->getStatusCode());
        $this->assertSame(404, $this->req('POST', '/projects/999/tasks', ['title' => 'x'])->getStatusCode());
    }

    public function testCrossProjectTaskAccessIs404(): void
    {
        $p1 = $this->makeProject('one');
        $p2 = $this->makeProject('two');
        $t = $this->makeTask($p1, 'belongs to p1');
        // fetching p1's task via p2 must 404
        $this->assertSame(404, $this->req('GET', "/projects/{$p2}/tasks/{$t}")->getStatusCode());
        $this->assertSame(200, $this->req('GET', "/projects/{$p1}/tasks/{$t}")->getStatusCode());
    }

    // ── task create / status ─────────────────────────────────────────────────

    public function testCreateTaskDefaults(): void
    {
        $pid = $this->makeProject();
        $res = $this->req('POST', "/projects/{$pid}/tasks", ['title' => 'Write spec']);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('open', $data['status']);
        $this->assertSame(0, $data['priority']);
    }

    public function testCreateTaskInvalidStatus(): void
    {
        $pid = $this->makeProject();
        $this->assertSame(422, $this->req('POST', "/projects/{$pid}/tasks", ['title' => 'x', 'status' => 'bogus'])->getStatusCode());
    }

    public function testCreateTaskRejectsNonIntPriority(): void
    {
        $pid = $this->makeProject();
        // JSON 1.0 / "1" must be rejected (is_int only)
        $this->assertSame(422, $this->req('POST', "/projects/{$pid}/tasks", ['title' => 'x', 'priority' => '1'])->getStatusCode());
        $this->assertSame(422, $this->req('POST', "/projects/{$pid}/tasks", ['title' => 'x', 'priority' => 1.0])->getStatusCode());
    }

    // ── PATCH selective ──────────────────────────────────────────────────────

    public function testPatchUpdatesOnlyProvidedFields(): void
    {
        $pid = $this->makeProject();
        $tid = $this->makeTask($pid, 'Original', 'open', 5);

        // only status provided — title and priority preserved
        $res = $this->req('PATCH', "/projects/{$pid}/tasks/{$tid}", ['status' => 'done']);
        $this->assertSame(200, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('done', $data['status']);
        $this->assertSame('Original', $data['title']);
        $this->assertSame(5, $data['priority']);
    }

    public function testPatchInvalidStatus(): void
    {
        $pid = $this->makeProject();
        $tid = $this->makeTask($pid, 'T');
        $this->assertSame(422, $this->req('PATCH', "/projects/{$pid}/tasks/{$tid}", ['status' => 'nope'])->getStatusCode());
    }

    // ── status filter + ordering ─────────────────────────────────────────────

    public function testListTasksFilterByStatusAndOrder(): void
    {
        $pid = $this->makeProject();
        $this->makeTask($pid, 'low', 'open', 1);
        $this->makeTask($pid, 'high', 'open', 9);
        $this->makeTask($pid, 'done one', 'done', 5);

        // filter open → 2 items, ordered by priority DESC (high first)
        $data = $this->json($this->req('GET', "/projects/{$pid}/tasks", null, ['status' => 'open']));
        $this->assertSame(2, $data['total']);
        $this->assertSame(['high', 'low'], array_map(static fn (array $t): string => $t['title'], $data['items']));
    }

    public function testListTasksInvalidStatusFilter(): void
    {
        $pid = $this->makeProject();
        $this->assertSame(422, $this->req('GET', "/projects/{$pid}/tasks", null, ['status' => 'bad'])->getStatusCode());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteTask(): void
    {
        $pid = $this->makeProject();
        $tid = $this->makeTask($pid, 'T');
        $this->assertSame(204, $this->req('DELETE', "/projects/{$pid}/tasks/{$tid}")->getStatusCode());
        $this->assertSame(404, $this->req('GET', "/projects/{$pid}/tasks/{$tid}")->getStatusCode());
    }

    public function testDeleteUnknownTaskIs404(): void
    {
        $pid = $this->makeProject();
        $this->assertSame(404, $this->req('DELETE', "/projects/{$pid}/tasks/999")->getStatusCode());
    }
}
