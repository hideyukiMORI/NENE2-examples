<?php

declare(strict_types=1);

namespace ShiftLog\Shift;

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
    private const int MAX_RANGE_DAYS = 90;
    private const int MAX_NAME = 100;
    private const int MAX_LOCATION = 200;

    public function __construct(
        private readonly ScheduleRepository $repo,
        private readonly ShiftService $service,
        private readonly JsonResponseFactory $json,
        private readonly string $adminKey,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/employees', $this->listEmployees(...));
        $router->post('/employees', $this->createEmployee(...));
        $router->get('/employees/{id}', $this->getEmployee(...));
        $router->get('/employees/{id}/shifts', $this->employeeShifts(...));
        $router->post('/shifts', $this->createShift(...));
        $router->get('/shifts/{id}', $this->getShift(...));
        $router->delete('/shifts/{id}', $this->deleteShift(...));
        $router->get('/schedule', $this->schedule(...));
        $router->get('/summary/hours', $this->summaryHours(...));
    }

    private function listEmployees(ServerRequestInterface $request): ResponseInterface
    {
        [$limit, $offset] = $this->pagination($request);
        return $this->json->create(['employees' => array_map($this->employeeView(...), $this->repo->listEmployees($limit, $offset))]);
    }

    private function createEmployee(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $name = is_string($body['name'] ?? null) ? trim((string) $body['name']) : '';
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            $errors[] = new ValidationError('name', 'name must be 1..100 characters', 'invalid_value');
        }
        $role = is_string($body['role'] ?? null) ? trim((string) $body['role']) : '';
        if ($role === '' || mb_strlen($role) > self::MAX_NAME) {
            $errors[] = new ValidationError('role', 'role must be 1..100 characters', 'invalid_value');
        }
        $rate = $body['hourly_rate'] ?? null;
        if (!is_int($rate) || $rate <= 0) {
            $errors[] = new ValidationError('hourly_rate', 'hourly_rate must be a positive integer (cents/hour)', 'invalid_value');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_int($rate));

        $id = $this->repo->createEmployee($name, $role, $rate, $this->now());
        return $this->json->create($this->employeeView((array) $this->repo->findEmployee($id)), 201);
    }

    private function getEmployee(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $employee = $id === null ? null : $this->repo->findEmployee($id);
        if ($employee === null) {
            return $this->notFound('Employee');
        }
        return $this->json->create($this->employeeView($employee));
    }

    private function employeeShifts(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findEmployee($id) === null) {
            return $this->notFound('Employee');
        }
        [$limit, $offset] = $this->pagination($request);
        return $this->json->create(['shifts' => array_map($this->shiftView(...), $this->repo->shiftsForEmployee($id, $limit, $offset))]);
    }

    private function createShift(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];

        $employeeId = $body['employee_id'] ?? null;
        if (!is_int($employeeId) || $employeeId <= 0) {
            $errors[] = new ValidationError('employee_id', 'employee_id must be a positive integer', 'invalid_value');
        }
        $startsAt = $body['starts_at'] ?? null;
        if (!is_string($startsAt) || !$this->isIsoUtc($startsAt)) {
            $errors[] = new ValidationError('starts_at', 'starts_at must be ISO 8601 UTC', 'invalid_value');
        }
        $endsAt = $body['ends_at'] ?? null;
        if (!is_string($endsAt) || !$this->isIsoUtc($endsAt)) {
            $errors[] = new ValidationError('ends_at', 'ends_at must be ISO 8601 UTC', 'invalid_value');
        }
        $location = is_string($body['location'] ?? null) ? $body['location'] : '';
        if (mb_strlen($location) > self::MAX_LOCATION) {
            $errors[] = new ValidationError('location', 'location must not exceed 200 characters', 'max_length');
        }
        if (is_string($startsAt) && is_string($endsAt) && $errors === [] && $endsAt <= $startsAt) {
            $errors[] = new ValidationError('ends_at', 'ends_at must be after starts_at', 'invalid_range');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($startsAt) && is_string($endsAt));

        [$status, $id] = $this->service->schedule($employeeId, $startsAt, $endsAt, $location, $this->now());
        return match ($status) {
            'not_found' => $this->notFound('Employee'),
            'overlap' => $this->json->create(['error' => 'Shift overlaps an existing shift'], 409),
            default => $this->json->create($this->shiftView((array) $this->repo->findShift($id)), 201),
        };
    }

    private function getShift(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $shift = $id === null ? null : $this->repo->findShift($id);
        if ($shift === null) {
            return $this->notFound('Shift');
        }
        return $this->json->create($this->shiftView($shift));
    }

    private function deleteShift(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }
        $id = $this->idParam($request);
        if ($id === null || !$this->repo->deleteShift($id)) {
            return $this->notFound('Shift');
        }
        return $this->json->createEmpty(204);
    }

    private function schedule(ServerRequestInterface $request): ResponseInterface
    {
        [$from, $to] = $this->dateWindow($request);
        $shifts = array_map($this->shiftView(...), $this->repo->shiftsInWindow($from, $to));
        return $this->json->create(['shifts' => $shifts, 'count' => count($shifts)]);
    }

    private function summaryHours(ServerRequestInterface $request): ResponseInterface
    {
        [$from, $to] = $this->dateWindow($request);
        $threshold = QueryStringParser::int($request, 'threshold');

        /** @var array<int, float> $hoursByEmployee */
        $hoursByEmployee = [];
        foreach ($this->repo->shiftsInWindow($from, $to) as $shift) {
            $employeeId = (int) $shift['employee_id'];
            $start = new \DateTimeImmutable((string) $shift['starts_at']);
            $end = new \DateTimeImmutable((string) $shift['ends_at']);
            $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
            $hoursByEmployee[$employeeId] = ($hoursByEmployee[$employeeId] ?? 0.0) + $hours;
        }

        $summary = [];
        foreach ($hoursByEmployee as $employeeId => $hours) {
            if ($threshold !== null && $hours <= $threshold) {
                continue;
            }
            $summary[] = ['employee_id' => $employeeId, 'hours' => round($hours, 2)];
        }
        return $this->json->create(['summary' => $summary, 'from' => $from, 'to' => $to]);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Validate ?from= / ?to= ISO dates and cap the range to 90 days (V-08).
     *
     * @return array{string, string}
     */
    private function dateWindow(ServerRequestInterface $request): array
    {
        $from = QueryStringParser::string($request, 'from');
        $to = QueryStringParser::string($request, 'to');
        if ($from === null || $to === null || !$this->isIsoUtc($from) || !$this->isIsoUtc($to)) {
            throw new ValidationException([new ValidationError('from', 'from and to must be ISO 8601 UTC', 'invalid_value')]);
        }
        if ($to <= $from) {
            throw new ValidationException([new ValidationError('to', 'to must be after from', 'invalid_range')]);
        }
        $days = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days ?: 0;
        if ($days > self::MAX_RANGE_DAYS) {
            throw new ValidationException([new ValidationError('to', 'date range must not exceed 90 days', 'out_of_range')]);
        }
        return [$from, $to];
    }

    private function isIsoUtc(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function isAdmin(ServerRequestInterface $request): bool
    {
        if ($this->adminKey === '') {
            return false;
        }
        $provided = $request->getHeaderLine('X-Admin-Key');
        return $provided !== '' && hash_equals($this->adminKey, $provided);
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    private function employeeView(array $e): array
    {
        return [
            'id' => (int) $e['id'],
            'name' => (string) $e['name'],
            'role' => (string) $e['role'],
            'hourly_rate' => (int) $e['hourly_rate'],
            'created_at' => (string) $e['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private function shiftView(array $s): array
    {
        return [
            'id' => (int) $s['id'],
            'employee_id' => (int) $s['employee_id'],
            'starts_at' => (string) $s['starts_at'],
            'ends_at' => (string) $s['ends_at'],
            'location' => (string) $s['location'],
        ];
    }

    /** @return array{int, int} */
    private function pagination(ServerRequestInterface $request): array
    {
        $limit = QueryStringParser::int($request, 'limit', 20) ?? 20;
        $offset = QueryStringParser::int($request, 'offset', 0) ?? 0;
        return [max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset)];
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

    private function forbidden(): ResponseInterface
    {
        return $this->json->create(['error' => 'Admin key required'], 403);
    }

    private function notFound(string $what): ResponseInterface
    {
        return $this->json->create(['error' => $what . ' not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
