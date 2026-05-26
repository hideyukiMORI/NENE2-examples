<?php

declare(strict_types=1);

namespace ImportLog\Import;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly ImportRepository $repo,
        private readonly CsvImporter $importer,
        private readonly JsonResponseFactory $json,
    ) {}

    public function register(Router $router): void
    {
        $router->post('/imports', $this->handleCreateImport(...));
        $router->get('/imports', $this->handleListImports(...));
        $router->get('/imports/{importId}', $this->handleGetImport(...));
    }

    private function handleCreateImport(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        if (!isset($body['csv']) || !is_string($body['csv'])) {
            throw new ValidationException([
                new ValidationError('csv', 'csv is required', 'required'),
            ]);
        }

        $csv = $body['csv'];
        $filename = isset($body['filename']) && is_string($body['filename'])
            ? trim($body['filename'])
            : 'upload.csv';

        if (trim($csv) === '') {
            throw new ValidationException([
                new ValidationError('csv', 'csv must not be empty', 'required'),
            ]);
        }

        if (!$this->importer->validateHeader($csv)) {
            throw new ValidationException([
                new ValidationError('csv', 'CSV must have header row: name,email,age', 'invalid_format'),
            ]);
        }

        $parsed = $this->importer->parse($csv);
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

        $totalRows = count($parsed['rows']) + count($parsed['errors']);
        $jobId = $this->repo->createJob(
            $filename,
            $totalRows,
            count($parsed['rows']),
            count($parsed['errors']),
            $parsed['errors'],
            $now,
        );

        foreach ($parsed['rows'] as $row) {
            $this->repo->insertRecord($jobId, $row['name'], $row['email'], $row['age'], $now);
        }

        $job = $this->repo->findJob($jobId);
        assert($job !== null);

        return $this->json->create($this->formatJob($job), 201);
    }

    private function handleListImports(ServerRequestInterface $request): ResponseInterface
    {
        $jobs = $this->repo->listJobs();
        return $this->json->create([
            'imports' => array_map($this->formatJob(...), $jobs),
            'count' => count($jobs),
        ]);
    }

    private function handleGetImport(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $importId = (int) ($params['importId'] ?? 0);

        $job = $this->repo->findJob($importId);
        if ($job === null) {
            return $this->json->create(['error' => 'Import job not found'], 404);
        }

        $records = $this->repo->listRecords($importId);

        $formatted = $this->formatJob($job);
        $formatted['records'] = array_map($this->formatRecord(...), $records);

        return $this->json->create($formatted);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function formatJob(array $job): array
    {
        /** @var list<array{row: int, value: string, error: string}> $errors */
        $errors = json_decode((string) $job['errors'], true) ?? [];
        return [
            'id' => (int) $job['id'],
            'filename' => (string) $job['filename'],
            'status' => (string) $job['status'],
            'total_rows' => (int) $job['total_rows'],
            'imported_rows' => (int) $job['imported_rows'],
            'failed_rows' => (int) $job['failed_rows'],
            'errors' => $errors,
            'created_at' => (string) $job['created_at'],
            'completed_at' => $job['completed_at'] !== null ? (string) $job['completed_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function formatRecord(array $record): array
    {
        return [
            'id' => (int) $record['id'],
            'name' => (string) $record['name'],
            'email' => (string) $record['email'],
            'age' => $record['age'] !== null ? (int) $record['age'] : null,
            'created_at' => (string) $record['created_at'],
        ];
    }
}
