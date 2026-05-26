# FT179 — isolationlog

**Tenant Isolation & Cross-Tenant IDOR Prevention**

Demonstrates multi-tenant resource scoping — every SQL query carries a
`tenant_id` guard, cross-tenant access returns 404 (not 403), and
`V::userId()` rejects all header injection attempts.

## What this example covers

- **SQL-level isolation**: all reads/writes include `AND tenant_id = ?` — never query by ID alone
- **Header-based identity**: `X-Tenant-Id` and `X-User-Id` validated with `V::userId()`
- **Body injection prevention**: `tenant_id` in POST body is ignored; server header always wins
- **404 vs 403**: cross-tenant access returns 404 to prevent existence leakage
- **Tenant existence check**: note creation fails with 422 if tenant doesn't exist

## Run

```bash
cd isolationlog
composer install
composer check    # cs + analyse + test
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/tenants` | X-Admin-Key | Create tenant |
| `GET` | `/tenants` | X-Admin-Key | List all tenants |
| `GET` | `/tenants/{id}` | X-Admin-Key | Get tenant |
| `POST` | `/notes` | X-Tenant-Id + X-User-Id | Create note |
| `GET` | `/notes` | X-Tenant-Id + X-User-Id | List own tenant's notes |
| `GET` | `/notes/{id}` | X-Tenant-Id + X-User-Id | Get note (cross-tenant → 404) |
| `DELETE` | `/notes/{id}` | X-Tenant-Id + X-User-Id | Delete own note |

## Attack scenarios tested (ATK-01〜12)

| ATK | Attack | Result |
|-----|--------|--------|
| 01 | No auth headers | 401 |
| 02 | Cross-tenant GET (IDOR) | 404 — note exists, not for this tenant |
| 03 | X-Tenant-Id: `1.5`, `+1`, `1 OR 1=1` | 401 — V::userId rejects |
| 04 | POST body with `tenant_id: 99` | 201 — body value ignored, header wins |
| 05 | Cross-tenant DELETE | 404 — note untouched |
| 06 | X-Tenant-Id: `0`, `-1` | 401 |
| 07 | X-Tenant-Id: 20-digit overflow | 401 |
| 08 | Tenant creation without admin key | 401 |
| 09 | Wrong admin key | 401 |
| 10 | Note for non-existent tenant | 422 |
| 11 | List: T1 sees only T1 notes | SQL WHERE tenant_id enforced |
| 12 | `?limit=-1`, `?limit=10.5`, 20-digit | 422 — V::queryInt guards |

## Related

- [How-to: Tenant Isolation](../../NENE2/docs/howto/tenant-isolation.md)
- [FT178 — patchlog](../patchlog/README.md) — JSON Merge Patch & ETag
- [FT176 — grantlog](../grantlog/README.md) — delegated access grants (multi-party IDOR)
