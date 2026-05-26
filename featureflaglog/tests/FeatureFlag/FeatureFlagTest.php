<?php

declare(strict_types=1);

namespace FeatureFlag\Tests\FeatureFlag;

use FeatureFlag\FeatureFlag\FeatureFlagRepository;
use FeatureFlag\FeatureFlag\FlagEvaluator;
use FeatureFlag\FeatureFlag\RouteRegistrar;
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

final class FeatureFlagTest extends TestCase
{
    private string $dbFile = '';
    private RequestHandlerInterface $app;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/featureflaglog-' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $factory   = new PdoConnectionFactory($dbConfig);
        $executor  = new PdoDatabaseQueryExecutor($factory);
        $psr17     = new Psr17Factory();
        $json      = new JsonResponseFactory($psr17, $psr17);
        $problems  = new ProblemDetailsResponseFactory($psr17, $psr17);
        $repo      = new FeatureFlagRepository($executor);
        $evaluator = new FlagEvaluator();

        $registrar = new RouteRegistrar($repo, $evaluator, $json, $problems);

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

    // --- Create flag ---

    public function testCreateFlagReturns201(): void
    {
        $res  = $this->req('POST', '/flags', ['name' => 'new-checkout', 'description' => 'New checkout flow']);
        $body = $this->decode($res);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('new-checkout', $body['name']);
        self::assertFalse($body['globally_enabled']);
        self::assertSame(0, $body['rollout_pct']);
    }

    public function testCreateFlagMissingNameReturns422(): void
    {
        $res = $this->req('POST', '/flags', ['description' => 'No name']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testCreateDuplicateFlagReturns409(): void
    {
        $this->req('POST', '/flags', ['name' => 'dup-flag']);
        $res = $this->req('POST', '/flags', ['name' => 'dup-flag']);
        self::assertSame(409, $res->getStatusCode());
    }

    // --- Get flag ---

    public function testGetFlagReturns200WithTargets(): void
    {
        $this->req('POST', '/flags', ['name' => 'get-me']);
        $res  = $this->req('GET', '/flags/get-me');
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('get-me', $body['flag']['name']);
        self::assertIsArray($body['targets']);
    }

    public function testGetNonExistentFlagReturns404(): void
    {
        $res = $this->req('GET', '/flags/no-such-flag');
        self::assertSame(404, $res->getStatusCode());
    }

    // --- Toggle (globally_enabled) ---

    public function testToggleFlagOnReturnsEnabled(): void
    {
        $this->req('POST', '/flags', ['name' => 'toggle-me']);
        $res  = $this->req('POST', '/flags/toggle-me/toggle', ['enabled' => true]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($body['globally_enabled']);
    }

    public function testToggleFlagOffDisablesGlobally(): void
    {
        $this->req('POST', '/flags', ['name' => 'toggle-off']);
        $this->req('POST', '/flags/toggle-off/toggle', ['enabled' => true]);
        $res  = $this->req('POST', '/flags/toggle-off/toggle', ['enabled' => false]);
        $body = $this->decode($res);

        self::assertFalse($body['globally_enabled']);
    }

    // --- Rollout percentage ---

    public function testSetRolloutPct(): void
    {
        $this->req('POST', '/flags', ['name' => 'rollout-flag']);
        $res  = $this->req('PUT', '/flags/rollout-flag/rollout', ['rollout_pct' => 50]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(50, $body['rollout_pct']);
    }

    public function testSetRolloutPctOutOfRangeReturns422(): void
    {
        $this->req('POST', '/flags', ['name' => 'bad-rollout']);
        $res = $this->req('PUT', '/flags/bad-rollout/rollout', ['rollout_pct' => 101]);
        self::assertSame(422, $res->getStatusCode());
    }

    // --- Targeting ---

    public function testUpsertUserTargetEnabled(): void
    {
        $this->req('POST', '/flags', ['name' => 'targeted']);
        $res  = $this->req('PUT', '/flags/targeted/targets', [
            'target_type' => 'user', 'target_id' => 'user-42', 'enabled' => true,
        ]);
        $body = $this->decode($res);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('user', $body['target_type']);
        self::assertSame('user-42', $body['target_id']);
        self::assertTrue($body['enabled']);
    }

    public function testUpsertTargetInvalidTypeReturns422(): void
    {
        $this->req('POST', '/flags', ['name' => 'bad-target']);
        $res = $this->req('PUT', '/flags/bad-target/targets', [
            'target_type' => 'org', 'target_id' => 'org-1', 'enabled' => true,
        ]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testDeleteTargetReturns204(): void
    {
        $this->req('POST', '/flags', ['name' => 'del-target']);
        $this->req('PUT', '/flags/del-target/targets', [
            'target_type' => 'user', 'target_id' => 'user-99', 'enabled' => true,
        ]);
        $res = $this->req('DELETE', '/flags/del-target/targets/user/user-99');
        self::assertSame(204, $res->getStatusCode());
    }

    // --- Evaluation ---

    public function testEvaluateDisabledFlagReturnsFalse(): void
    {
        $this->req('POST', '/flags', ['name' => 'disabled-flag']);
        $res  = $this->req('POST', '/flags/disabled-flag/evaluate', ['user_id' => 'u1']);
        $body = $this->decode($res);

        self::assertFalse($body['enabled']);
    }

    public function testEvaluateGloballyEnabledFlagReturnsTrue(): void
    {
        $this->req('POST', '/flags', ['name' => 'global-flag']);
        $this->req('POST', '/flags/global-flag/toggle', ['enabled' => true]);

        $res  = $this->req('POST', '/flags/global-flag/evaluate', ['user_id' => 'anyone']);
        $body = $this->decode($res);

        self::assertTrue($body['enabled']);
    }

    public function testEvaluateUserTargetOverridesGlobalDisable(): void
    {
        $this->req('POST', '/flags', ['name' => 'user-override']);
        // Flag is globally disabled, but user-5 has explicit enable
        $this->req('PUT', '/flags/user-override/targets', [
            'target_type' => 'user', 'target_id' => 'user-5', 'enabled' => true,
        ]);

        $res  = $this->req('POST', '/flags/user-override/evaluate', ['user_id' => 'user-5']);
        $body = $this->decode($res);

        self::assertTrue($body['enabled']);
    }

    public function testEvaluateUserKillSwitchOverridesGlobalEnable(): void
    {
        $this->req('POST', '/flags', ['name' => 'kill-switch']);
        $this->req('POST', '/flags/kill-switch/toggle', ['enabled' => true]);
        // Explicit disable for one user
        $this->req('PUT', '/flags/kill-switch/targets', [
            'target_type' => 'user', 'target_id' => 'blocked-user', 'enabled' => false,
        ]);

        $res  = $this->req('POST', '/flags/kill-switch/evaluate', ['user_id' => 'blocked-user']);
        $body = $this->decode($res);

        self::assertFalse($body['enabled']);
    }

    public function testEvaluateTenantTargetApplied(): void
    {
        $this->req('POST', '/flags', ['name' => 'tenant-flag']);
        $this->req('PUT', '/flags/tenant-flag/targets', [
            'target_type' => 'tenant', 'target_id' => 'tenant-abc', 'enabled' => true,
        ]);

        $res  = $this->req('POST', '/flags/tenant-flag/evaluate', [
            'user_id' => 'u1', 'tenant_id' => 'tenant-abc',
        ]);
        $body = $this->decode($res);

        self::assertTrue($body['enabled']);
    }

    public function testEvaluateRollout100PctAlwaysTrue(): void
    {
        $this->req('POST', '/flags', ['name' => 'full-rollout']);
        $this->req('PUT', '/flags/full-rollout/rollout', ['rollout_pct' => 100]);

        $res  = $this->req('POST', '/flags/full-rollout/evaluate', ['user_id' => 'any-user']);
        $body = $this->decode($res);

        self::assertTrue($body['enabled']);
    }

    public function testEvaluateRollout0PctAlwaysFalse(): void
    {
        $this->req('POST', '/flags', ['name' => 'zero-rollout']);
        $this->req('PUT', '/flags/zero-rollout/rollout', ['rollout_pct' => 0]);

        $res  = $this->req('POST', '/flags/zero-rollout/evaluate', ['user_id' => 'any-user']);
        $body = $this->decode($res);

        self::assertFalse($body['enabled']);
    }

    public function testEvaluateMissingUserIdReturns422(): void
    {
        $this->req('POST', '/flags', ['name' => 'no-user']);
        $res = $this->req('POST', '/flags/no-user/evaluate', ['tenant_id' => 't1']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testEvaluateNonExistentFlagReturns404(): void
    {
        $res = $this->req('POST', '/flags/ghost-flag/evaluate', ['user_id' => 'u1']);
        self::assertSame(404, $res->getStatusCode());
    }
}
