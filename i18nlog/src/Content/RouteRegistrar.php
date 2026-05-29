<?php

declare(strict_types=1);

namespace I18nLog\Content;

use Nene2\Http\JsonResponseFactory;
use Nene2\Http\QueryStringParser;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouteRegistrar
{
    /** BCP 47 in the common `language` / `language-REGION` forms. */
    private const string LOCALE_PATTERN = '/^[a-z]{2}(-[A-Z]{2})?$/';

    public function __construct(
        private readonly ContentRepository $repo,
        private readonly JsonResponseFactory $json,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/articles', $this->createArticle(...));
        $router->get('/articles', $this->listArticles(...));
        $router->get('/articles/{id}', $this->getArticle(...));
        $router->put('/articles/{id}/translations/{locale}', $this->upsertTranslation(...));
    }

    private function createArticle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $defaultLocale = is_string($body['default_locale'] ?? null) ? trim((string) $body['default_locale']) : 'en';
        if (!$this->isLocale($defaultLocale)) {
            throw new ValidationException([new ValidationError('default_locale', 'default_locale must be a BCP 47 tag (e.g. en, fr-FR)', 'invalid_value')]);
        }
        // Strict: only JSON true publishes; anything else is a draft.
        $published = ($body['published'] ?? null) === true;

        $id = $this->repo->createArticle($defaultLocale, $published, $this->now());
        return $this->json->create($this->articleView((array) $this->repo->findArticle($id)), 201);
    }

    private function listArticles(ServerRequestInterface $request): ResponseInterface
    {
        $locale = $this->validatedLocaleQuery($request);
        $items = [];
        foreach ($this->repo->listPublished() as $article) {
            $items[] = $this->articleWithContent($article, $locale);
        }
        return $this->json->create(['articles' => $items, 'count' => count($items)]);
    }

    private function getArticle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        $article = $id === null ? null : $this->repo->findArticle($id);
        if ($article === null) {
            return $this->notFound();
        }
        return $this->json->create($this->articleWithContent($article, $this->validatedLocaleQuery($request)));
    }

    private function upsertTranslation(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->idParam($request);
        if ($id === null || $this->repo->findArticle($id) === null) {
            return $this->notFound();
        }
        $locale = $this->localeParam($request);
        if ($locale === null) {
            throw new ValidationException([new ValidationError('locale', 'locale must be a BCP 47 tag', 'invalid_value')]);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $errors = [];
        $title = is_string($body['title'] ?? null) ? trim((string) $body['title']) : '';
        if ($title === '') {
            $errors[] = new ValidationError('title', 'title must not be empty', 'required');
        }
        $content = is_string($body['body'] ?? null) ? (string) $body['body'] : null;
        if ($content === null) {
            $errors[] = new ValidationError('body', 'body must be a string', 'invalid_type');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        assert(is_string($content));

        $result = $this->repo->upsertTranslation($id, $locale, $title, $content, $this->now());
        $translation = $this->repo->translation($id, $locale);
        return $this->json->create([
            'locale' => $locale,
            'title' => $translation === null ? $title : (string) $translation['title'],
        ], $result === 'created' ? 201 : 200);
    }

    /**
     * Attach the best-matching translation: requested locale, else the
     * article's default locale, else null.
     *
     * @param array<string, mixed> $article
     * @return array<string, mixed>
     */
    private function articleWithContent(array $article, ?string $requested): array
    {
        $id = (int) $article['id'];
        $default = (string) $article['default_locale'];
        $resolved = null;
        $translation = null;

        if ($requested !== null) {
            $translation = $this->repo->translation($id, $requested);
            $resolved = $translation !== null ? $requested : null;
        }
        if ($translation === null) {
            $translation = $this->repo->translation($id, $default);
            $resolved = $translation !== null ? $default : null;
        }

        $view = $this->articleView($article);
        $view['available_locales'] = $this->repo->locales($id);
        $view['resolved_locale'] = $resolved;
        $view['content'] = $translation === null ? null : [
            'title' => (string) $translation['title'],
            'body' => (string) $translation['body'],
        ];
        return $view;
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function articleView(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'default_locale' => (string) $a['default_locale'],
            'published' => ((int) $a['published']) === 1,
            'updated_at' => (string) $a['updated_at'],
        ];
    }

    private function validatedLocaleQuery(ServerRequestInterface $request): ?string
    {
        $locale = QueryStringParser::string($request, 'locale');
        if ($locale !== null && !$this->isLocale($locale)) {
            throw new ValidationException([new ValidationError('locale', 'locale must be a BCP 47 tag', 'invalid_value')]);
        }
        return $locale;
    }

    private function localeParam(ServerRequestInterface $request): ?string
    {
        $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
        $locale = (string) ($params['locale'] ?? '');
        return $this->isLocale($locale) ? $locale : null;
    }

    private function isLocale(string $value): bool
    {
        return preg_match(self::LOCALE_PATTERN, $value) === 1;
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

    private function notFound(): ResponseInterface
    {
        return $this->json->create(['error' => 'Article not found'], 404);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
    }
}
