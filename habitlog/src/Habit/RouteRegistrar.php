<?php

declare(strict_types=1);

namespace HabitLog\Habit;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const array FREQUENCIES = ['daily', 'weekly', 'monthly'];
    private const int MAX_NAME = 200;

    public function __construct(
        private readonly HabitRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/habits', $this->listHabits(...));
        $router->post('/habits', $this->createHabit(...));
        $router->get('/habits/{id}', $this->getHabit(...));
        $router->delete('/habits/{id}', $this->deleteHabit(...));
        $router->post('/habits/{id}/completions', $this->complete(...));
        $router->get('/habits/{id}/completions', $this->listCompletions(...));
        $router->get('/habits/{id}/streak', $this->streak(...));
    }

    private function listHabits(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $frequency = QueryStringParser::string($request, 'frequency');
        if ($frequency !== null && !in_array($frequency, self::FREQUENCIES, true)) {
            throw new ValidationException([$this->frequencyError()]);
        }
        $habits = array_map($this->view(...), $this->repo->listOwned($owner, $frequency));
        return $this->json->create(['habits' => $habits, 'count' => count($habits)]);
    }

    private function createHabit(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $name = is_string($body['name'] ?? null) ? trim((string) $body['name']) : '';
        if ($name === '') {
            $errors[] = new ValidationError('name', 'name must not be empty', 'required');
        } elseif (mb_strlen($name) > self::MAX_NAME) {
            $errors[] = new ValidationError('name', 'name must not exceed 200 characters', 'max_length');
        }
        $frequency = is_string($body['frequency'] ?? null) ? $body['frequency'] : 'daily';
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            $errors[] = $this->frequencyError();
        }
        $description = is_string($body['description'] ?? null) ? $body['description'] : '';
        if (mb_strlen($description) > 2000) {
            $errors[] = new ValidationError('description', 'description must not exceed 2000 characters', 'max_length');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id = $this->repo->create($owner, $name, $description, $frequency, $this->now());
        return $this->json->create($this->view((array) $this->repo->findOwned($id, $owner)), 201);
    }

    private function getHabit(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        $habit = $id === null ? null : $this->repo->findOwned($id, $owner);
        if ($habit === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($habit));
    }

    private function deleteHabit(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        if ($id === null || !$this->repo->delete($id, $owner)) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    private function complete(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findOwned($id, $owner) === null) {
            return $this->notFound();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $date = is_string($body['completed_on'] ?? null) ? $body['completed_on'] : '';
        if (!$this->isCanonicalDate($date)) {
            throw new ValidationException([new ValidationError('completed_on', 'completed_on must be a valid YYYY-MM-DD date', 'invalid_value')]);
        }
        $note = is_string($body['note'] ?? null) ? $body['note'] : '';

        return match ($this->repo->complete($id, $date, $note)) {
            'duplicate' => $this->json->create(['error' => 'Already completed on that date'], 409),
            default => $this->json->create(['habit_id' => $id, 'completed_on' => $date], 201),
        };
    }

    private function listCompletions(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findOwned($id, $owner) === null) {
            return $this->notFound();
        }
        $items = array_map(
            static fn (array $c): array => [
                'id' => (int) $c['id'],
                'completed_on' => (string) $c['completed_on'],
                'note' => (string) $c['note'],
            ],
            $this->repo->completions($id),
        );
        return $this->json->create(['completions' => $items, 'count' => count($items)]);
    }

    private function streak(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->owner($request);
        if ($owner === null) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findOwned($id, $owner) === null) {
            return $this->notFound();
        }
        $today = QueryStringParser::string($request, 'today') ?? date('Y-m-d');
        if (!$this->isCanonicalDate($today)) {
            throw new ValidationException([new ValidationError('today', 'today must be a valid YYYY-MM-DD date', 'invalid_value')]);
        }
        return $this->json->create(['habit_id' => $id, 'streak' => $this->repo->streak($id, $today)]);
    }

    private function isCanonicalDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    /**
     * @param array<string, mixed> $h
     * @return array<string, mixed>
     */
    private function view(array $h): array
    {
        return [
            'id' => (int) $h['id'],
            'name' => (string) $h['name'],
            'description' => (string) $h['description'],
            'frequency' => (string) $h['frequency'],
            'created_at' => (string) $h['created_at'],
        ];
    }

    private function owner(ServerRequestInterface $request): ?int
    {
        $raw = $request->getHeaderLine('X-User-Id');
        if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function idParam(ServerRequestInterface $request): ?int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $raw = (string) ($params['id'] ?? '');
        if (!ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function frequencyError(): ValidationError
    {
        return new ValidationError('frequency', 'frequency must be one of: ' . implode(', ', self::FREQUENCIES), 'invalid_value');
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->json->create(['error' => 'Valid X-User-Id header required'], 401);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Habit not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
