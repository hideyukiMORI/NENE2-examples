<?php

declare(strict_types=1);

namespace PollLog\Tests\Poll;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use PollLog\AppFactory;
use Psr\Http\Message\ResponseInterface;

class PollTest extends TestCase
{
    private const ADMIN_KEY = 'admin-secret';

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
    private function req(string $method, string $path, array $headers = [], mixed $body = null, string $adminKey = self::ADMIN_KEY): ResponseInterface
    {
        $app = AppFactory::createSqlite($this->dbFile, $adminKey);
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest($method, 'http://localhost' . $path);
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
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> created poll view
     */
    private function makePoll(array $overrides = []): array
    {
        $body = array_merge(['question' => 'Best color?', 'options' => ['Red', 'Green', 'Blue']], $overrides);
        $res = $this->req('POST', '/polls', ['X-Admin-Key' => self::ADMIN_KEY], $body);
        assert($res->getStatusCode() === 201);
        return $this->json($res);
    }

    // ── creation / validation ────────────────────────────────────────────────

    public function testCreateRequiresAdmin(): void
    {
        $this->assertSame(403, $this->req('POST', '/polls', [], ['question' => 'q', 'options' => ['a', 'b']])->getStatusCode());
    }

    public function testEmptyAdminKeyFailsClosed(): void
    {
        $res = $this->req('POST', '/polls', ['X-Admin-Key' => ''], ['question' => 'q', 'options' => ['a', 'b']], '');
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testCreatePoll(): void
    {
        $poll = $this->makePoll();
        $this->assertCount(3, $poll['options']);
    }

    public function testTooFewOptionsRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/polls', ['X-Admin-Key' => self::ADMIN_KEY], ['question' => 'q', 'options' => ['only one']])->getStatusCode());
    }

    public function testTooManyOptionsRejected(): void
    {
        $opts = array_map(static fn (int $i): string => "opt{$i}", range(1, 21));
        $this->assertSame(422, $this->req('POST', '/polls', ['X-Admin-Key' => self::ADMIN_KEY], ['question' => 'q', 'options' => $opts])->getStatusCode());
    }

    public function testEmptyOptionLabelRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/polls', ['X-Admin-Key' => self::ADMIN_KEY], ['question' => 'q', 'options' => ['ok', '  ']])->getStatusCode());
    }

    public function testNonBoolIsPublicRejected(): void
    {
        $this->assertSame(422, $this->req('POST', '/polls', ['X-Admin-Key' => self::ADMIN_KEY], ['question' => 'q', 'options' => ['a', 'b'], 'is_public' => 1])->getStatusCode());
    }

    // ── voting ────────────────────────────────────────────────────────────────

    public function testVote(): void
    {
        $poll = $this->makePoll();
        $optId = (int) $poll['options'][0]['id'];
        $res = $this->req('POST', '/polls/' . $poll['id'] . '/vote', ['X-User-Id' => '1'], ['option_id' => $optId]);
        $this->assertSame(201, $res->getStatusCode());
    }

    public function testDuplicateVoteRejected(): void
    {
        $poll = $this->makePoll();
        $optId = (int) $poll['options'][0]['id'];
        $this->req('POST', '/polls/' . $poll['id'] . '/vote', ['X-User-Id' => '1'], ['option_id' => $optId]);
        // same user, even a different option → 409
        $other = (int) $poll['options'][1]['id'];
        $this->assertSame(409, $this->req('POST', '/polls/' . $poll['id'] . '/vote', ['X-User-Id' => '1'], ['option_id' => $other])->getStatusCode());
    }

    public function testVoteRequiresUser(): void
    {
        $poll = $this->makePoll();
        $optId = (int) $poll['options'][0]['id'];
        $this->assertSame(401, $this->req('POST', '/polls/' . $poll['id'] . '/vote', [], ['option_id' => $optId])->getStatusCode());
    }

    public function testNonIntOptionRejected(): void
    {
        $poll = $this->makePoll();
        $this->assertSame(422, $this->req('POST', '/polls/' . $poll['id'] . '/vote', ['X-User-Id' => '1'], ['option_id' => '1'])->getStatusCode());
    }

    public function testCrossPollOptionInjectionRejected(): void
    {
        $pollA = $this->makePoll(['question' => 'A']);
        $pollB = $this->makePoll(['question' => 'B']);
        $foreignOption = (int) $pollB['options'][0]['id'];
        // voting on poll A with an option that belongs to poll B → 422
        $res = $this->req('POST', '/polls/' . $pollA['id'] . '/vote', ['X-User-Id' => '1'], ['option_id' => $foreignOption]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ── results ──────────────────────────────────────────────────────────────────

    public function testResultsIncludeZeroVoteOptions(): void
    {
        $poll = $this->makePoll();
        $opt0 = (int) $poll['options'][0]['id'];
        $this->req('POST', '/polls/' . $poll['id'] . '/vote', ['X-User-Id' => '1'], ['option_id' => $opt0]);
        $this->req('POST', '/polls/' . $poll['id'] . '/vote', ['X-User-Id' => '2'], ['option_id' => $opt0]);
        $data = $this->json($this->req('GET', '/polls/' . $poll['id'] . '/results'));
        $this->assertSame(2, $data['total_votes']);
        $this->assertCount(3, $data['results']); // all options present
        $this->assertSame(2, $data['results'][0]['votes']);
        $this->assertSame(0, $data['results'][1]['votes']); // zero-vote option still listed
    }

    // ── private poll access ────────────────────────────────────────────────────────

    public function testPrivatePollHiddenFromNonAdmin(): void
    {
        $poll = $this->makePoll(['is_public' => false]);
        // public GET → 404 (existence hidden)
        $this->assertSame(404, $this->req('GET', '/polls/' . $poll['id'])->getStatusCode());
        // admin → 200
        $this->assertSame(200, $this->req('GET', '/polls/' . $poll['id'], ['X-Admin-Key' => self::ADMIN_KEY])->getStatusCode());
    }

    public function testPrivatePollVoteHiddenFromNonAdmin(): void
    {
        $poll = $this->makePoll(['is_public' => false]);
        $optId = (int) $poll['options'][0]['id'];
        $this->assertSame(404, $this->req('POST', '/polls/' . $poll['id'] . '/vote', ['X-User-Id' => '1'], ['option_id' => $optId])->getStatusCode());
    }

    public function testUnknownPollIs404(): void
    {
        $this->assertSame(404, $this->req('GET', '/polls/999')->getStatusCode());
    }
}
