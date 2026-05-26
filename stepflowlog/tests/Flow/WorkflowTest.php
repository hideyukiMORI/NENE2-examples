<?php

declare(strict_types=1);

namespace StepFlow\Tests\Flow;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use StepFlow\AppFactory;

final class WorkflowTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/stepflowlog_test_' . uniqid() . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $this->app = AppFactory::createSqlite($this->dbFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /** @param array<string, string> $queryParams */
    private function req(string $method, string $path, mixed $body = null, array $queryParams = []): ResponseInterface
    {
        $psr17   = new Psr17Factory();
        $uri     = $psr17->createUri('http://localhost' . $path);
        $request = $psr17->createServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($queryParams !== []) {
            $request = $request->withQueryParams($queryParams);
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $res->getBody(), true);
    }

    private function createWorkflow(string $name): int
    {
        $res = $this->req('POST', '/workflows', ['name' => $name, 'description' => 'test']);
        return (int) $this->json($res)['id'];
    }

    private function addStep(int $wfId, string $name): int
    {
        $res = $this->req('POST', "/workflows/{$wfId}/steps", ['name' => $name]);
        return (int) $this->json($res)['id'];
    }

    private function startRun(int $wfId, string $title): int
    {
        $res = $this->req('POST', '/runs', ['workflow_id' => $wfId, 'title' => $title]);
        return (int) $this->json($res)['id'];
    }

    // =========================================================================
    // Workflow CRUD

    public function testCreateWorkflowReturns201(): void
    {
        $res  = $this->req('POST', '/workflows', ['name' => 'doc-approval', 'description' => 'Document approval flow']);
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('doc-approval', $data['name']);
        $this->assertSame([], $data['steps']);
    }

    public function testGetWorkflowIncludesSteps(): void
    {
        $id = $this->createWorkflow('hr-onboarding');
        $this->addStep($id, 'Manager Approval');
        $this->addStep($id, 'HR Review');
        $data = $this->json($this->req('GET', "/workflows/{$id}"));
        $this->assertCount(2, $data['steps']);
        $this->assertSame(1, (int) $data['steps'][0]['step_order']);
        $this->assertSame(2, (int) $data['steps'][1]['step_order']);
    }

    public function testGetNonexistentWorkflowReturns404(): void
    {
        $this->assertSame(404, $this->req('GET', '/workflows/9999')->getStatusCode());
    }

    public function testAddStepAutoIncrementsOrder(): void
    {
        $id = $this->createWorkflow('purchase-order');
        $this->addStep($id, 'Budget Check');
        $res  = $this->req('POST', "/workflows/{$id}/steps", ['name' => 'CFO Approval']);
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(2, (int) $data['step_order']);
    }

    public function testAddStepRequiresName(): void
    {
        $id  = $this->createWorkflow('x');
        $res = $this->req('POST', "/workflows/{$id}/steps", []);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateWorkflowRequiresName(): void
    {
        $res = $this->req('POST', '/workflows', ['description' => 'no name']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // =========================================================================
    // Run lifecycle

    public function testStartRunReturns201WithFirstStep(): void
    {
        $id = $this->createWorkflow('expense-approval');
        $this->addStep($id, 'Team Lead');
        $this->addStep($id, 'Finance');

        $res  = $this->req('POST', '/runs', ['workflow_id' => $id, 'title' => 'Q1 Expenses']);
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('in_progress', $data['status']);
        $this->assertSame('Team Lead', $data['current_step_name']);
    }

    public function testStartRunWithNoStepsReturns409(): void
    {
        $id  = $this->createWorkflow('empty-flow');
        $res = $this->req('POST', '/runs', ['workflow_id' => $id, 'title' => 'Should Fail']);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testGetRunIncludesHistory(): void
    {
        $wfId  = $this->createWorkflow('contract-review');
        $this->addStep($wfId, 'Legal');
        $runId = $this->startRun($wfId, 'Contract #001');
        $this->req('POST', "/runs/{$runId}/approve", ['actor' => 'alice', 'comment' => 'LGTM']);

        $data = $this->json($this->req('GET', "/runs/{$runId}"));
        $this->assertCount(1, $data['history']);
        $this->assertSame('approve', $data['history'][0]['action']);
        $this->assertSame('alice', $data['history'][0]['actor']);
    }

    public function testGetNonexistentRunReturns404(): void
    {
        $this->assertSame(404, $this->req('GET', '/runs/9999')->getStatusCode());
    }

    // =========================================================================
    // Approval flow

    public function testApproveSingleStepCompletesRun(): void
    {
        $wfId  = $this->createWorkflow('single-step');
        $this->addStep($wfId, 'Approve');
        $runId = $this->startRun($wfId, 'Request A');

        $res  = $this->req('POST', "/runs/{$runId}/approve", ['actor' => 'bob']);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('completed', $data['status']);
    }

    public function testApproveAdvancesToNextStep(): void
    {
        $wfId  = $this->createWorkflow('two-step');
        $this->addStep($wfId, 'Step 1');
        $this->addStep($wfId, 'Step 2');
        $runId = $this->startRun($wfId, 'Request B');

        $res  = $this->req('POST', "/runs/{$runId}/approve", ['actor' => 'carol']);
        $data = $this->json($res);
        $this->assertSame('in_progress', $data['status']);
        $this->assertSame('Step 2', $data['current_step_name']);
    }

    public function testApproveAllStepsCompletesRun(): void
    {
        $wfId  = $this->createWorkflow('three-step');
        $this->addStep($wfId, 'A');
        $this->addStep($wfId, 'B');
        $this->addStep($wfId, 'C');
        $runId = $this->startRun($wfId, 'Full Flow');

        $this->req('POST', "/runs/{$runId}/approve", ['actor' => 'u1']);
        $this->req('POST', "/runs/{$runId}/approve", ['actor' => 'u2']);
        $res  = $this->req('POST', "/runs/{$runId}/approve", ['actor' => 'u3']);
        $data = $this->json($res);
        $this->assertSame('completed', $data['status']);
        $this->assertCount(3, $data['history']);
    }

    public function testRejectSetsStatusRejected(): void
    {
        $wfId  = $this->createWorkflow('reject-flow');
        $this->addStep($wfId, 'Review');
        $runId = $this->startRun($wfId, 'Request C');

        $res  = $this->req('POST', "/runs/{$runId}/reject", ['actor' => 'dave', 'comment' => 'Insufficient info']);
        $data = $this->json($res);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('rejected', $data['status']);
        $this->assertSame('reject', $data['history'][0]['action']);
    }

    public function testApproveCompletedRunReturns409(): void
    {
        $wfId  = $this->createWorkflow('done-flow');
        $this->addStep($wfId, 'Only');
        $runId = $this->startRun($wfId, 'Done');
        $this->req('POST', "/runs/{$runId}/approve", ['actor' => 'eve']);

        $res = $this->req('POST', "/runs/{$runId}/approve", ['actor' => 'eve']);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testApproveRejectedRunReturns409(): void
    {
        $wfId  = $this->createWorkflow('rej-flow');
        $this->addStep($wfId, 'Only');
        $runId = $this->startRun($wfId, 'Rej');
        $this->req('POST', "/runs/{$runId}/reject", ['actor' => 'frank']);

        $res = $this->req('POST', "/runs/{$runId}/approve", ['actor' => 'frank']);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function testApproveRequiresActor(): void
    {
        $wfId  = $this->createWorkflow('actor-req');
        $this->addStep($wfId, 'Step');
        $runId = $this->startRun($wfId, 'NoActor');
        $res   = $this->req('POST', "/runs/{$runId}/approve", []);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testStartRunRequiresWorkflowId(): void
    {
        $res = $this->req('POST', '/runs', ['title' => 'No WF']);
        $this->assertSame(422, $res->getStatusCode());
    }
}
