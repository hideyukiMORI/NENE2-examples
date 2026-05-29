<?php

declare(strict_types=1);

namespace PollLog\Poll;

use Nene2\Database\DatabaseConstraintException;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MIN_OPTIONS = 2;
    private const int MAX_OPTIONS = 20;
    private const int MAX_LABEL_LEN = 100;

    public function __construct(
        private readonly PollRepository $repo,
        private readonly PollService $service,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/polls', $this->create(...));
        $router->get('/polls/{id}', $this->show(...));
        $router->post('/polls/{id}/vote', $this->vote(...));
        $router->get('/polls/{id}/results', $this->results(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $body = (array) ($request->getParsedBody() ?? []);

        $question = V::str($body['question'] ?? null, 500);
        if ($question === null || $question === '') {
            throw new ValidationException([new ValidationError('question', 'question must be a non-empty string', 'invalid_value')]);
        }

        // is_public must be a strict bool when present (rejects 1/0/"true").
        $isPublic = true;
        if (array_key_exists('is_public', $body)) {
            if (!is_bool($body['is_public'])) {
                throw new ValidationException([new ValidationError('is_public', 'is_public must be a boolean', 'invalid_value')]);
            }
            $isPublic = $body['is_public'];
        }

        $options = $this->parseOptions($body['options'] ?? null);

        $id = $this->service->create($question, $isPublic, $options, $this->now());
        return $this->json->create($this->pollView($id, $isPublic), 201);
    }

    private function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $poll = $id === 0 ? null : $this->repo->find($id);
        if ($poll === null) {
            return $this->notFound();
        }
        // Private polls are 404 for non-admins (existence hiding).
        if ((int) $poll['is_public'] === 0 && !$this->isAdmin($request)) {
            return $this->notFound();
        }
        return $this->json->create([
            'id' => (int) $poll['id'],
            'question' => (string) $poll['question'],
            'is_public' => (int) $poll['is_public'] === 1,
            'options' => array_map(
                static fn (array $o): array => ['id' => (int) $o['id'], 'label' => (string) $o['label']],
                $this->repo->options($id),
            ),
        ]);
    }

    private function vote(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $pollId = $this->idParam($request);
        $poll = $pollId === 0 ? null : $this->repo->find($pollId);
        if ($poll === null || ((int) $poll['is_public'] === 0 && !$this->isAdmin($request))) {
            return $this->notFound();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $optionId = $body['option_id'] ?? null;
        if (!is_int($optionId)) { // strict — rejects floats/strings
            throw new ValidationException([new ValidationError('option_id', 'option_id must be an integer', 'invalid_value')]);
        }
        // Cross-poll option injection guard.
        if (!$this->repo->optionBelongsToPoll($optionId, $pollId)) {
            throw new ValidationException([new ValidationError('option_id', 'option does not belong to this poll', 'invalid_value')]);
        }
        if ($this->repo->hasVoted($pollId, $userId)) {
            return $this->json->create(['error' => 'already voted'], 409);
        }
        try {
            $this->repo->insertVote($pollId, $optionId, $userId, $this->now());
        } catch (DatabaseConstraintException) {
            // UNIQUE(poll_id, user_id) safety net against a concurrent double-vote.
            return $this->json->create(['error' => 'already voted'], 409);
        }
        return $this->json->create(['voted' => true], 201);
    }

    private function results(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $poll = $id === 0 ? null : $this->repo->find($id);
        if ($poll === null || ((int) $poll['is_public'] === 0 && !$this->isAdmin($request))) {
            return $this->notFound();
        }
        $results = array_map(
            static fn (array $r): array => [
                'option_id' => (int) $r['id'],
                'label' => (string) $r['label'],
                'votes' => (int) $r['votes'],
            ],
            $this->repo->results($id),
        );
        $total = array_sum(array_column($results, 'votes'));
        return $this->json->create(['poll_id' => $id, 'total_votes' => $total, 'results' => $results]);
    }

    /**
     * @return list<string>
     */
    private function parseOptions(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ValidationException([new ValidationError('options', 'options must be a JSON array', 'invalid_value')]);
        }
        $count = count($raw);
        if ($count < self::MIN_OPTIONS || $count > self::MAX_OPTIONS) {
            throw new ValidationException([new ValidationError('options', 'options must have 2..20 entries', 'invalid_value')]);
        }
        $labels = [];
        foreach ($raw as $idx => $label) {
            if (!is_string($label) || trim($label) === '') {
                throw new ValidationException([new ValidationError("options[{$idx}]", 'option must be a non-empty string', 'invalid_value')]);
            }
            if (strlen($label) > self::MAX_LABEL_LEN) {
                throw new ValidationException([new ValidationError("options[{$idx}]", 'option too long (max 100)', 'invalid_value')]);
            }
            $labels[] = trim($label);
        }
        return $labels;
    }

    /** @return array<string, mixed> */
    private function pollView(int $id, bool $isPublic): array
    {
        return [
            'id' => $id,
            'is_public' => $isPublic,
            'options' => array_map(
                static fn (array $o): array => ['id' => (int) $o['id'], 'label' => (string) $o['label']],
                $this->repo->options($id),
            ),
        ];
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        return hash_equals($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : 0;
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }

    private function forbidden(): ResponseInterface
    {
        return $this->json->create(['error' => 'admin key required'], 403);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'poll not found'], 404);
    }
}
