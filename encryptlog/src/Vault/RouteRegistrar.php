<?php

declare(strict_types=1);

namespace EncryptLog\Vault;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\V;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly VaultRepository $repo,
        private readonly FieldCrypto $crypto,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/vault', $this->create(...));
        $router->get('/vault', $this->list(...));
        $router->get('/vault/search', $this->search(...));
        $router->get('/vault/{id}', $this->get(...));
        $router->patch('/vault/{id}', $this->patch(...));
        $router->delete('/vault/{id}', $this->delete(...));
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $email = V::str($body['email'] ?? null, 320);
        if ($email === null || $email === '') {
            throw new ValidationException([new ValidationError('email', 'email must be a non-empty string', 'invalid_value')]);
        }
        $note = V::str($body['note'] ?? '', 4000) ?? '';

        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $id = $this->repo->create(
            $userId,
            $this->crypto->encrypt($email),
            $this->crypto->blindIndex($email),
            $note === '' ? '' : $this->crypto->encrypt($note),
            $now,
        );
        return $this->json->create($this->view((array) $this->repo->findOwned($id, $userId)), 201);
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $limit = V::queryInt($request->getQueryParams(), 'limit', 1, 100, 50);
        if ($limit === null) {
            throw new ValidationException([new ValidationError('limit', 'limit must be 1..100', 'invalid_value')]);
        }
        return $this->json->create(['records' => array_map($this->view(...), $this->repo->listOwned($userId, $limit))]);
    }

    private function search(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $email = V::str($request->getQueryParams()['email'] ?? null, 320);
        if ($email === null || $email === '') {
            throw new ValidationException([new ValidationError('email', 'email query parameter is required', 'invalid_value')]);
        }
        // Equality search via the blind index — no row is decrypted to find matches.
        $rows = $this->repo->searchByIndex($userId, $this->crypto->blindIndex($email));
        return $this->json->create(['records' => array_map($this->view(...), $rows), 'count' => count($rows)]);
    }

    private function get(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $record = $this->repo->findOwned($this->idParam($request), $userId);
        if ($record === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($record));
    }

    private function patch(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        $id = $this->idParam($request);
        $record = $this->repo->findOwned($id, $userId);
        if ($record === null) {
            return $this->notFound();
        }
        $body = (array) ($request->getParsedBody() ?? []);

        // Default to existing values (decrypt current), override when provided.
        $emailEnc = (string) $record['email_enc'];
        $emailIdx = (string) $record['email_idx'];
        if (array_key_exists('email', $body)) {
            $email = V::str($body['email'], 320);
            if ($email === null || $email === '') {
                throw new ValidationException([new ValidationError('email', 'email must be a non-empty string', 'invalid_value')]);
            }
            $emailEnc = $this->crypto->encrypt($email);
            $emailIdx = $this->crypto->blindIndex($email); // reindex to stay in sync
        }
        $noteEnc = (string) $record['note_enc'];
        if (array_key_exists('note', $body)) {
            $note = V::str($body['note'], 4000);
            if ($note === null) {
                throw new ValidationException([new ValidationError('note', 'note must be a string', 'invalid_type')]);
            }
            $noteEnc = $note === '' ? '' : $this->crypto->encrypt($note);
        }

        $this->repo->update($id, $userId, $emailEnc, $emailIdx, $noteEnc, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));
        return $this->json->create($this->view((array) $this->repo->findOwned($id, $userId)));
    }

    private function delete(ServerRequestInterface $request): ResponseInterface
    {
        $userId = V::userId($request->getHeaderLine('X-User-Id'));
        if ($userId === null) {
            return $this->unauthorized();
        }
        if (!$this->repo->delete($this->idParam($request), $userId)) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    /**
     * Decrypt for the response — ciphertext NEVER appears in the API output.
     * A tampered row makes FieldCrypto::decrypt throw → surfaces as 500.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function view(array $record): array
    {
        return [
            'id' => (int) $record['id'],
            'email' => $this->crypto->decrypt((string) $record['email_enc']),
            'note' => ((string) $record['note_enc']) === '' ? '' : $this->crypto->decrypt((string) $record['note_enc']),
            'created_at' => (string) $record['created_at'],
            'updated_at' => (string) $record['updated_at'],
        ];
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

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Record not found'], 404);
    }
}
