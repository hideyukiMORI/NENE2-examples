# i18nlog — Multilingual Content

> **FT232** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/multilingual-content.md)

Articles whose content is stored as locale-keyed translations, with BCP 47 validation, upsert semantics, and locale fallback for content negotiation.

## Highlights

- **BCP 47 locale validation** — `^[a-z]{2}(-[A-Z]{2})?$` accepts `en` / `fr-FR`, rejects `EN`, `en_US`, `../../etc` (`422`). Applied to `default_locale`, the path `{locale}`, and `?locale=`.
- **Upsert translations** — `PUT /articles/{id}/translations/{locale}`; `201` on create, `200` on update (`UNIQUE(article_id, locale)`).
- **Locale fallback** — `?locale=` returns that translation, else the article's `default_locale`, else `null` content; `resolved_locale` reports which was served.
- **Strict publish flag** — only JSON `true` publishes; `"true"`/`1` leave a draft. List shows published only.

## Run

```bash
composer install
composer test        # PHPUnit (12 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/articles` | Create (`default_locale`, `published`) |
| `GET` | `/articles` | List published (`?locale=`) |
| `GET` | `/articles/{id}` | Get with fallback content (`?locale=`) |
| `PUT` | `/articles/{id}/translations/{locale}` | Upsert a translation |

## Related

- [Howto: Multilingual Content](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/multilingual-content.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
