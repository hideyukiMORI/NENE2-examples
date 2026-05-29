# projtrack — Project & Task Management (Nested Resources)

> **FT241** &nbsp;·&nbsp; [NENE2 Examples](https://github.com/hideyukiMORI/NENE2-examples) &nbsp;·&nbsp; [Howto guide ↗](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/project-task-management.md)

A two-level nested resource API: tasks belong to projects. Parent-existence validation, project-scoped task access, `PATCH` selective updates, status allow-list, and pagination.

## Highlights

- **Nested parent validation** — every task route checks the project exists first; `/projects/99/tasks` → `404`.
- **Project-scoped access** — `WHERE id = ? AND project_id = ?`; task 5 of project 1 is **not** reachable via `/projects/2/tasks/5` (cross-project → `404`).
- **`PATCH` with `array_key_exists`** — omitted fields are preserved; a single `UPDATE` merges provided values over the existing row.
- **Strict `priority`** — `is_int()` only; JSON `1.0` / `"1"` are rejected (`422`).
- **Status allow-list** — app-level `422` with a message, backed by a DB `CHECK` constraint; also a `?status=` list filter (`422` on invalid).
- **`ON DELETE CASCADE`** — deleting a project removes its tasks.
- **Pagination** — `?limit=&offset=` via `QueryStringParser`; `{ items, total, limit, offset }` envelope.

## Run

```bash
composer install
composer test        # PHPUnit (15 tests)
composer analyse     # PHPStan level 8
composer cs          # PHP CS Fixer dry-run
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/projects` | List (paginated) |
| `POST` | `/projects` | Create |
| `GET` | `/projects/{id}` | Get |
| `DELETE` | `/projects/{id}` | Delete (cascades tasks) |
| `GET` | `/projects/{projectId}/tasks` | List tasks (paginated, `?status=`) |
| `POST` | `/projects/{projectId}/tasks` | Create task |
| `GET` | `/projects/{projectId}/tasks/{taskId}` | Get task |
| `PATCH` | `/projects/{projectId}/tasks/{taskId}` | Selective update |
| `DELETE` | `/projects/{projectId}/tasks/{taskId}` | Delete task (204) |

## Related

- [Howto: Project & Task Management](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/project-task-management.md)
- [NENE2 framework](https://github.com/hideyukiMORI/NENE2)
