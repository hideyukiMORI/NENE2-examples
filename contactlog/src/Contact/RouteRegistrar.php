<?php

declare(strict_types=1);

namespace ContactLog\Contact;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    public function __construct(
        private readonly ContactRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/owners/{ownerId}/contacts', $this->createContact(...));
        $router->get('/owners/{ownerId}/contacts', $this->searchContacts(...));
        $router->get('/owners/{ownerId}/contacts/{id}', $this->getContact(...));
        $router->put('/owners/{ownerId}/contacts/{id}', $this->updateContact(...));
        $router->delete('/owners/{ownerId}/contacts/{id}', $this->deleteContact(...));
        $router->post('/owners/{ownerId}/groups', $this->createGroup(...));
        $router->put('/owners/{ownerId}/contacts/{contactId}/groups/{groupId}', $this->addToGroup(...));
        $router->delete('/owners/{ownerId}/contacts/{contactId}/groups/{groupId}', $this->removeFromGroup(...));
    }

    private function createContact(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->ownerParam($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ValidationException([new ValidationError('name', 'name must be a non-empty string', 'invalid_value')]);
        }
        [$email, $phone, $notes] = $this->optionalStrings($body);

        $id = $this->repo->createContact($owner, trim($name), $email, $phone, $notes, $this->now());
        return $this->json->create($this->view((array) $this->repo->findContact($id, $owner)), 201);
    }

    private function searchContacts(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->ownerParam($request);
        $q = QueryStringParser::string($request, 'q');
        $groupId = QueryStringParser::int($request, 'group_id');
        $contacts = array_map($this->view(...), $this->repo->search($owner, $q, $groupId));
        return $this->json->create(['contacts' => $contacts, 'count' => count($contacts)]);
    }

    private function getContact(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->ownerParam($request);
        $contact = $this->repo->findContact($this->intParam($request, 'id'), $owner);
        if ($contact === null) {
            return $this->notFound();
        }
        return $this->json->create($this->view($contact));
    }

    private function updateContact(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->ownerParam($request);
        $id = $this->intParam($request, 'id');
        if ($this->repo->findContact($id, $owner) === null) {
            return $this->notFound();
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ValidationException([new ValidationError('name', 'name must be a non-empty string', 'invalid_value')]);
        }
        [$email, $phone, $notes] = $this->optionalStrings($body);

        $this->repo->updateContact($id, $owner, trim($name), $email, $phone, $notes, $this->now());
        return $this->json->create($this->view((array) $this->repo->findContact($id, $owner)));
    }

    private function deleteContact(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->ownerParam($request);
        if (!$this->repo->deleteContact($this->intParam($request, 'id'), $owner)) {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    private function createGroup(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->ownerParam($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ValidationException([new ValidationError('name', 'name must be a non-empty string', 'invalid_value')]);
        }
        $id = $this->repo->createGroup($owner, trim($name), $this->now());
        if ($id === null) {
            return $this->json->create(['error' => 'A group with that name already exists'], 409);
        }
        return $this->json->create(['id' => $id, 'name' => trim($name)], 201);
    }

    private function addToGroup(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->ownerParam($request);
        $result = $this->repo->addToGroup($this->intParam($request, 'contactId'), $this->intParam($request, 'groupId'), $owner);
        if ($result === 'not_found') {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    private function removeFromGroup(ServerRequestInterface $request): ResponseInterface
    {
        $owner = $this->ownerParam($request);
        $result = $this->repo->removeFromGroup($this->intParam($request, 'contactId'), $this->intParam($request, 'groupId'), $owner);
        if ($result === 'not_found') {
            return $this->notFound();
        }
        return $this->json->createEmpty(204);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{string, string, string}
     */
    private function optionalStrings(array $body): array
    {
        $get = static function (mixed $v): string {
            return is_string($v) ? $v : '';
        };
        return [$get($body['email'] ?? ''), $get($body['phone'] ?? ''), $get($body['notes'] ?? '')];
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function view(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'name' => (string) $c['name'],
            'email' => (string) $c['email'],
            'phone' => (string) $c['phone'],
            'notes' => (string) $c['notes'],
            'groups' => $this->repo->groupsOf((int) $c['id']),
            'updated_at' => (string) $c['updated_at'],
        ];
    }

    private function ownerParam(ServerRequestInterface $request): string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (string) ($params['ownerId'] ?? '');
    }

    private function intParam(ServerRequestInterface $request, string $key): int
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        return (int) ($params[$key] ?? 0);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
