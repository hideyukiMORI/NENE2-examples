<?php

declare(strict_types=1);

namespace AbLog\Tests\Ab;

use AbLog\AppFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AbTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/ablog_test_' . uniqid() . '.sqlite';
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

    private function createExperiment(string $name, string $description = ''): int
    {
        $res = $this->req('POST', '/experiments', ['name' => $name, 'description' => $description]);
        return (int) $this->json($res)['id'];
    }

    private function addVariant(int $expId, string $name, int $weight = 100): int
    {
        $res = $this->req('POST', "/experiments/{$expId}/variants", ['name' => $name, 'weight' => $weight]);
        return (int) $this->json($res)['id'];
    }

    private function activate(int $expId): void
    {
        $this->req('PUT', "/experiments/{$expId}/status", ['status' => 'active']);
    }

    // =========================================================================
    // Experiment CRUD

    public function testCreateExperimentReturns201(): void
    {
        $res  = $this->req('POST', '/experiments', ['name' => 'button-color', 'description' => 'Test button color']);
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('button-color', $data['name']);
        $this->assertSame('draft', $data['status']);
    }

    public function testListExperiments(): void
    {
        $this->createExperiment('exp-a');
        $this->createExperiment('exp-b');
        $data = $this->json($this->req('GET', '/experiments'));
        $this->assertSame(2, $data['count']);
    }

    public function testGetExperimentIncludesVariants(): void
    {
        $id = $this->createExperiment('homepage-layout');
        $this->addVariant($id, 'control');
        $this->addVariant($id, 'treatment');

        $data = $this->json($this->req('GET', "/experiments/{$id}"));
        $this->assertSame(200, $this->req('GET', "/experiments/{$id}")->getStatusCode());
        $this->assertCount(2, $data['variants']);
    }

    public function testGetNonexistentExperimentReturns404(): void
    {
        $this->assertSame(404, $this->req('GET', '/experiments/9999')->getStatusCode());
    }

    // =========================================================================
    // Status transitions

    public function testStatusDraftToActive(): void
    {
        $id  = $this->createExperiment('cta-text');
        $res = $this->req('PUT', "/experiments/{$id}/status", ['status' => 'active']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('active', $this->json($res)['status']);
    }

    public function testStatusActiveToStopped(): void
    {
        $id = $this->createExperiment('pricing');
        $this->activate($id);
        $res = $this->req('PUT', "/experiments/{$id}/status", ['status' => 'stopped']);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('stopped', $this->json($res)['status']);
    }

    public function testInvalidStatusTransitionReturns422(): void
    {
        $id  = $this->createExperiment('nav-color');
        $res = $this->req('PUT', "/experiments/{$id}/status", ['status' => 'stopped']);
        $this->assertSame(422, $res->getStatusCode());
    }

    // =========================================================================
    // Variant management

    public function testAddVariantReturns201(): void
    {
        $id  = $this->createExperiment('checkout-flow');
        $res = $this->req('POST', "/experiments/{$id}/variants", ['name' => 'control', 'weight' => 50]);
        $this->assertSame(201, $res->getStatusCode());
        $data = $this->json($res);
        $this->assertSame('control', $data['name']);
        $this->assertSame(50, (int) $data['weight']);
    }

    public function testAddVariantRequiresName(): void
    {
        $id  = $this->createExperiment('x');
        $res = $this->req('POST', "/experiments/{$id}/variants", ['weight' => 50]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // =========================================================================
    // Assignment

    public function testAssignUserToVariant(): void
    {
        $id = $this->createExperiment('hero-image');
        $this->addVariant($id, 'control', 50);
        $this->addVariant($id, 'treatment', 50);
        $this->activate($id);

        $res  = $this->req('POST', "/experiments/{$id}/assign", ['user_id' => 'user-001']);
        $data = $this->json($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertContains($data['variant_name'], ['control', 'treatment']);
    }

    public function testAssignmentIsIdempotent(): void
    {
        $id = $this->createExperiment('font-size');
        $this->addVariant($id, 'small', 50);
        $this->addVariant($id, 'large', 50);
        $this->activate($id);

        $res1 = $this->req('POST', "/experiments/{$id}/assign", ['user_id' => 'user-abc']);
        $res2 = $this->req('POST', "/experiments/{$id}/assign", ['user_id' => 'user-abc']);
        $this->assertSame(
            $this->json($res1)['variant_name'],
            $this->json($res2)['variant_name'],
        );
    }

    public function testAssignToInactiveExperimentReturns409(): void
    {
        $id  = $this->createExperiment('sidebar-position');
        $res = $this->req('POST', "/experiments/{$id}/assign", ['user_id' => 'user-x']);
        $this->assertSame(409, $res->getStatusCode());
    }

    // =========================================================================
    // Events and results

    public function testRecordEventReturns201(): void
    {
        $id = $this->createExperiment('email-subject');
        $this->addVariant($id, 'control', 100);
        $this->activate($id);
        $this->req('POST', "/experiments/{$id}/assign", ['user_id' => 'user-001']);

        $res = $this->req('POST', "/experiments/{$id}/events", [
            'user_id' => 'user-001', 'event_type' => 'conversion',
        ]);
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testEventForUnassignedUserReturns404(): void
    {
        $id = $this->createExperiment('landing-page');
        $this->addVariant($id, 'a', 100);
        $this->activate($id);

        $res = $this->req('POST', "/experiments/{$id}/events", [
            'user_id' => 'nobody', 'event_type' => 'click',
        ]);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testResultsReturnCvr(): void
    {
        $id = $this->createExperiment('signup-form');
        $this->addVariant($id, 'control', 100);
        $this->activate($id);

        $this->req('POST', "/experiments/{$id}/assign", ['user_id' => 'u1']);
        $this->req('POST', "/experiments/{$id}/assign", ['user_id' => 'u2']);
        $this->req('POST', "/experiments/{$id}/events", ['user_id' => 'u1', 'event_type' => 'conversion']);

        $data     = $this->json($this->req('GET', "/experiments/{$id}/results"));
        $variants = $data['variants'];
        $this->assertSame(1, (int) $variants[0]['events']);
        $this->assertSame(2, (int) $variants[0]['assignments']);
        $this->assertEqualsWithDelta(0.5, (float) $variants[0]['cvr'], 0.001);
    }

    public function testCreateRequiresName(): void
    {
        $res = $this->req('POST', '/experiments', ['description' => 'no name']);
        $this->assertSame(422, $res->getStatusCode());
    }
}
