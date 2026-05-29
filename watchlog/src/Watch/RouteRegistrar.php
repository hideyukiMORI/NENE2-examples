<?php

declare(strict_types=1);

namespace WatchLog\Watch;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly WatchRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/watch', $this->listEntries(...));
        $router->post('/watch', $this->createEntry(...));
        $router->get('/watch/{id}', $this->getEntry(...));
        $router->patch('/watch/{id}/status', $this->updateStatus(...));
        $router->post('/watch/{id}/archive', $this->archive(...));
        $router->post('/watch/{id}/restore', $this->restore(...));
        $router->delete('/watch/{id}', $this->deleteEntry(...));
    }

    private function listEntries(ServerRequestInterface $request): ResponseInterface
    {
        $errors = [];
        $status = $this->filterStatus($request, $errors);
        $type = $this->filterType($request, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $includeArchived = QueryStringParser::bool($request, 'include_archived') ?? false;
        [$limit, $offset] = $this->pagination($request);

        $items = array_map($this->view(...), $this->repo->list($status, $type, $includeArchived, $limit, $offset));
        return $this->json->create(['items' => $items, 'count' => count($items)]);
    }

    private function createEntry(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $title = is_string($body['title'] ?? null) ? trim((string) $body['title']) : '';
        if ($title === '') {
            $errors[] = new ValidationError('title', 'title must not be empty', 'required');
        }
        $type = $this->requireEnum($body, 'media_type', MediaType::class, $errors);
        $status = WatchStatus::WantToWatch;
        if (array_key_exists('status', $body)) {
            $parsed = is_string($body['status']) ? WatchStatus::tryFrom($body['status']) : null;
            if ($parsed === null) {
                $errors[] = new ValidationError('status', 'invalid status value', 'invalid_value');
            } else {
                $status = $parsed;
            }
        }
        [$ratingProvided, $rating] = $this->parseRating($body, $errors);
        $note = is_string($body['note'] ?? null) ? $body['note'] : '';

        if ($errors !== [] || $type === null) {
            throw new ValidationException($errors === [] ? [new ValidationError('media_type', 'media_type is required', 'required')] : $errors);
        }

        $id = $this->repo->create($title, $type, $status, $ratingProvided ? $rating : null, $note, $this->now());
        return $this->json->create($this->view((array) $this->repo->findById($id)), 201);
    }

    private function getEntry(ServerRequestInterface $request): ResponseInterface
    {
        $entry = $this->repo->findById($this->idParam($request));
        if ($entry === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($entry));
    }

    private function updateStatus(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            return $this->notFound();
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $statusRaw = is_string($body['status'] ?? null) ? $body['status'] : null;
        $status = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;
        if ($statusRaw === null) {
            $errors[] = new ValidationError('status', 'status is required', 'required');
        } elseif ($status === null) {
            $errors[] = new ValidationError('status', 'invalid status value', 'invalid_value');
        }
        [$ratingProvided, $rating] = $this->parseRating($body, $errors);
        $note = array_key_exists('note', $body) && is_string($body['note']) ? $body['note'] : null;

        if ($errors !== [] || $status === null) {
            throw new ValidationException($errors);
        }

        $this->repo->updateStatus($id, $existing, $status, $ratingProvided, $rating, $note, $this->now());
        return $this->json->create($this->view((array) $this->repo->findById($id)));
    }

    private function archive(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($this->repo->findById($id) === null) {
            return $this->notFound();
        }
        $this->repo->archive($id, $this->now());
        return $this->json->create($this->view((array) $this->repo->findById($id)));
    }

    private function restore(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($this->repo->findById($id) === null) {
            return $this->notFound();
        }
        $this->repo->restore($id, $this->now());
        return $this->json->create($this->view((array) $this->repo->findById($id)));
    }

    private function deleteEntry(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->repo->delete($this->idParam($request))) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Parse an optional nullable rating. array_key_exists distinguishes
     * "absent" from "explicitly null" (clear).
     *
     * @param array<string, mixed> $body
     * @param list<ValidationError> $errors
     * @return array{bool, int|null}
     */
    private function parseRating(array $body, array &$errors): array
    {
        if (!array_key_exists('rating', $body)) {
            return [false, null];
        }
        $rating = $body['rating'];
        if ($rating === null) {
            return [true, null];
        }
        if (!is_int($rating) || $rating < 1 || $rating > 5) {
            $errors[] = new ValidationError('rating', 'rating must be an integer from 1 to 5, or null', 'out_of_range');
            return [false, null];
        }
        return [true, $rating];
    }

    /** @param list<ValidationError> $errors */
    private function filterStatus(ServerRequestInterface $request, array &$errors): ?WatchStatus
    {
        $raw = QueryStringParser::string($request, 'status');
        if ($raw === null) {
            return null;
        }
        $value = WatchStatus::tryFrom($raw);
        if ($value === null) {
            $errors[] = new ValidationError('status', 'invalid status value', 'invalid_value');
        }
        return $value;
    }

    /** @param list<ValidationError> $errors */
    private function filterType(ServerRequestInterface $request, array &$errors): ?MediaType
    {
        $raw = QueryStringParser::string($request, 'media_type');
        if ($raw === null) {
            return null;
        }
        $value = MediaType::tryFrom($raw);
        if ($value === null) {
            $errors[] = new ValidationError('media_type', 'invalid media_type value', 'invalid_value');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     * @param class-string<MediaType> $enum
     * @param list<ValidationError> $errors
     */
    private function requireEnum(array $body, string $key, string $enum, array &$errors): ?MediaType
    {
        $raw = is_string($body[$key] ?? null) ? $body[$key] : null;
        if ($raw === null) {
            $errors[] = new ValidationError($key, $key . ' is required', 'required');
            return null;
        }
        $value = $enum::tryFrom($raw);
        if ($value === null) {
            $errors[] = new ValidationError($key, 'invalid ' . $key . ' value', 'invalid_value');
        }
        return $value;
    }

    /** @return array{int, int} */
    private function pagination(ServerRequestInterface $request): array
    {
        $limit = QueryStringParser::int($request, 'limit', 20) ?? 20;
        $offset = QueryStringParser::int($request, 'offset', 0) ?? 0;
        return [max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset)];
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function view(array $e): array
    {
        return [
            'id' => (int) $e['id'],
            'title' => (string) $e['title'],
            'media_type' => (string) $e['media_type'],
            'status' => (string) $e['status'],
            'rating' => $e['rating'] === null ? null : (int) $e['rating'],
            'note' => (string) $e['note'],
            'archived_at' => $e['archived_at'] === null ? null : (string) $e['archived_at'],
            'updated_at' => (string) $e['updated_at'],
        ];
    }

    private function idParam(ServerRequestInterface $request): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params['id'] ?? 0);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Watch entry not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
