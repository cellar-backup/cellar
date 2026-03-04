# Contributing to Cellar

Thanks for your interest in contributing! Cellar is open source under the Apache 2.0 license, and we welcome contributions of all kinds.

## Getting Started

1. **Fork** the repository and clone your fork locally.
2. Create a **feature branch** from `main`:
   ```bash
   git checkout -b feat/your-feature-name
   ```
3. Set up your development environment (see below).
4. Make your changes, write tests, and verify everything passes.
5. Open a **Pull Request** against `main`.

## Development Setup

### Prerequisites

- PHP 8.4+ and Composer
- Node.js 20+ (with npm)
- Docker & Docker Compose

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Frontend

```bash
cd frontend
npm install
npm run dev
```

### Full Stack (Docker)

```bash
docker compose up -d
```

## Branch Naming

| Prefix      | Purpose                               |
| ----------- | ------------------------------------- |
| `feat/`     | New feature                           |
| `fix/`      | Bug fix                               |
| `docs/`     | Documentation changes                 |
| `refactor/` | Code refactoring (no behavior change) |
| `test/`     | Adding or updating tests              |
| `chore/`    | Maintenance, CI, dependencies         |

## Commit Messages

We follow [Conventional Commits](https://www.conventionalcommits.org/):

```
type(scope): short description

Optional longer description.
```

**Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`, `ci`, `perf`

**Examples:**

- `feat(sources): add edit modal for sources`
- `fix(api): handle missing archive gracefully`
- `docs(readme): update quick start instructions`

## Pull Request Guidelines

- Keep PRs focused — one feature or fix per PR.
- Include tests for new functionality.
- Update documentation if the change affects user-facing behavior.
- Ensure CI passes before requesting review.
- Link related issues in the PR description.

## Code Style

- **PHP:** PSR-12 style, Laravel conventions.
- **JavaScript/Vue:** Formatted with Prettier, linted with ESLint.
- **All files:** EditorConfig enforced (see `.editorconfig`).

## Reporting Issues

Use [GitHub Issues](https://github.com/borger/cellar/issues) with the appropriate template:

- **Bug Report** — something is broken
- **Feature Request** — suggest an improvement

## Code of Conduct

Please read and follow our [Code of Conduct](CODE_OF_CONDUCT.md).

## License

By contributing, you agree that your contributions will be licensed under the Apache License 2.0.
