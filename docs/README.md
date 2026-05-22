# Docs index

- [openapi.yaml](openapi.yaml) — **машинно-читаемая спека (OpenAPI 3.1)**: все Green API методы, webhooks, Partner API, Admin/Container API
- [ARCHITECTURE.md](ARCHITECTURE.md) — компоненты, data flow, security boundaries
- [API.md](API.md) — Green API совместимый narrative-reference (методы + webhooks)
- [RUNBOOK.md](RUNBOOK.md) — оперативные сценарии, troubleshooting
- [SECURITY.md](SECURITY.md) — поверхности атаки, hardening, threat model
- [MIGRATION.md](MIGRATION.md) — план перехода с green-api.com на iqteco

## Как работать с openapi.yaml

```bash
# Lint
npx -y @redocly/cli@latest lint docs/openapi.yaml

# Локальный рендер Swagger-UI / Redoc
npx -y @redocly/cli@latest preview-docs docs/openapi.yaml
# → http://127.0.0.1:8080

# Импорт в Postman: File → Import → Files → docs/openapi.yaml.
# Postman сам создаст коллекцию со всеми запросами.
```
