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

- Python 3.12+
- Node.js 20+ (with npm)
- Docker & Docker Compose
- [pre-commit](https://pre-commit.com/)

### Backend

```bash
cd backend
python -m venv .venv
source .venv/bin/activate
pip install -r requirements/dev.txt
python manage.py migrate
python manage.py runserver
```

### Frontend

```bash
cd frontend
npm install
npm run dev
```

### Full Stack (Docker)

```bash
cp .env.example .env
docker compose up -d
```

### Pre-commit Hooks

```bash
pre-commit install
```

Hooks run automatically on commit. To run manually:

```bash
pre-commit run --all-files
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

- `feat(vaults): add composite vault support`
- `fix(api): handle missing archive gracefully`
- `docs(readme): update quick start instructions`

## Pull Request Guidelines

- Keep PRs focused — one feature or fix per PR.
- Include tests for new functionality.
- Update documentation if the change affects user-facing behavior.
- Ensure CI passes before requesting review.
- Link related issues in the PR description.

## Code Style

- **Python:** Formatted with [Ruff](https://docs.astral.sh/ruff/), type-checked with mypy.
- **JavaScript/Vue:** Formatted with Prettier, linted with ESLint.
- **All files:** EditorConfig enforced (see `.editorconfig`).

## Reporting Issues

Use [GitHub Issues](https://github.com/your-org/cellar/issues) with the appropriate template:

- **Bug Report** — something is broken
- **Feature Request** — suggest an improvement
- **Custom Document Request** — request a built-in CBD template

## Code of Conduct

Please read and follow our [Code of Conduct](CODE_OF_CONDUCT.md).

## License

By contributing, you agree that your contributions will be licensed under the Apache License 2.0.
