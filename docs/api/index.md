# API Reference

Cellar exposes a RESTful API documented via OpenAPI 3.0.

All endpoints are prefixed with `/api/v1/` and require JWT authentication (except health check).

## Authentication

```bash
# Obtain token pair
POST /api/v1/auth/token/
{ "username": "admin", "password": "..." }

# Use access token
Authorization: Bearer <access_token>
```

## Resources

| Endpoint                       | Methods                  | Description              |
| ------------------------------ | ------------------------ | ------------------------ |
| `/api/v1/vaults/`              | GET, POST, PATCH, DELETE | Backup vault definitions |
| `/api/v1/vaults/{id}/backup/`  | POST                     | Trigger backup           |
| `/api/v1/vaults/{id}/restore/` | POST                     | Initiate restore         |
| `/api/v1/archives/`            | GET                      | List all archives        |
| `/api/v1/storages/`            | GET, POST, PATCH, DELETE | Storage backends         |
| `/api/v1/jobs/`                | GET                      | List jobs                |
| `/api/v1/documents/`           | GET, POST, PATCH, DELETE | Custom backup documents  |
| `/api/v1/system/health/`       | GET                      | Health check (no auth)   |

For the full interactive API documentation, visit `/api/docs/` (Swagger UI) or `/api/redoc/` when Cellar is running.
