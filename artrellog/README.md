# artrellog — Article Relations (typed, auto-inverse)

> **FT334** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/article-relations-api.md)

Typed article-to-article relations (`related`, `sequel`, `prequel`, `reference`) with **automatic inverse management** so every edge stays consistent in both directions.

> Distinct from `relatedlog` (FT173 content-relations): this models *typed* edges with symmetric/asymmetric inverses created and deleted transactionally.

## Highlights

- **Auto-inverse, transactional** — adding `A —sequel→ B` also inserts `B —prequel→ A`; deleting one deletes both. Both writes run in `transactional()`.
- **Symmetric vs asymmetric** — `related`/`reference` are their own inverse; `sequel`↔`prequel`.
- **Embedded relations** — `GET /articles/{id}` returns `{ data, relations: [{ relation, related }] }`.
- **Guards** — relation-type allow-list, no self-relation, related article must exist, `UNIQUE(article_id, related_id, relation_type)` → `409` on duplicate, `ctype_digit` ids.

## Run

```bash
composer install
composer test        # PHPUnit (10 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/articles` | Create article |
| `GET` | `/articles/{id}` | Get with embedded relations |
| `POST` | `/articles/{id}/relations` | Add relation (`related_id`, `relation_type`) |
| `GET` | `/articles/{id}/relations` | List (`?type=`) |
| `DELETE` | `/articles/{id}/relations/{relatedId}?type=` | Remove relation + inverse |

## Related

- [Howto: Article Relations API](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/article-relations-api.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
