<?php

declare(strict_types=1);

namespace Upload\Upload;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteRegistrar
{
    public function __construct(
        private SqliteUploadRepository $repo,
        private FileValidator $validator,
        private JsonResponseFactory $json,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/uploads', $this->listUploads(...));
        $router->post('/uploads', $this->createUpload(...));
        $router->get('/uploads/{id}', $this->getUpload(...));
        $router->delete('/uploads/{id}', $this->deleteUpload(...));
    }

    private function listUploads(ServerRequestInterface $request): ResponseInterface
    {
        $files = $this->repo->listAll();

        return $this->json->createList(
            array_map(static fn (UploadedFile $f) => $f->toArray(), $files),
        );
    }

    private function createUpload(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        $content  = isset($body['content']) && is_string($body['content']) ? $body['content'] : '';
        $filename = isset($body['filename']) && is_string($body['filename']) ? $body['filename'] : '';

        if ($content === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'content', 'code' => 'required', 'message' => 'Base64-encoded file content is required.']],
            ]);
        }
        if ($filename === '') {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => 'filename', 'code' => 'required', 'message' => 'filename is required.']],
            ]);
        }

        try {
            $validated = $this->validator->validate($content, $filename);
        } catch (UploadValidationException $e) {
            return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
                'errors' => [['field' => $e->field, 'code' => $e->errorCode, 'message' => $e->getMessage()]],
            ]);
        }

        $sanitized    = $this->validator->sanitizeFilename($filename);
        $storedName   = bin2hex(random_bytes(8)) . '_' . $sanitized;

        $file = $this->repo->store(
            bytes:            $validated['bytes'],
            mimeType:         $validated['mime'],
            sizeBytes:        $validated['size'],
            originalFilename: $filename,
            storedFilename:   $storedName,
        );

        return $this->json->create($file->toArray(), 201);
    }

    private function getUpload(ServerRequestInterface $request): ResponseInterface
    {
        $id   = (int) (Router::param($request, 'id') ?? '0');
        $file = $id > 0 ? $this->repo->findById($id) : null;

        if ($file === null) {
            return $this->problems->create($request, 'not-found', 'Not Found', 404, 'Upload not found.');
        }

        return $this->json->create($file->toArray());
    }

    private function deleteUpload(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) (Router::param($request, 'id') ?? '0');

        if ($id <= 0 || !$this->repo->delete($id)) {
            return $this->problems->create($request, 'not-found', 'Not Found', 404, 'Upload not found.');
        }

        return $this->json->create(['deleted' => true]);
    }
}
