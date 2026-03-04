# What is Cellar?

Cellar is an open-source, container-native backup management platform designed for HomeLab users who need enterprise-grade backup capabilities without the steep learning curve.

## The Problem

Existing backup solutions fall into two camps:

- **CLI power tools** (borgmatic, restic) — powerful but require YAML config and cron scheduling
- **GUI tools** — polished but limited to database dumps with no deduplication or filesystem support

## The Solution

Cellar bridges this gap by wrapping proven deduplication engines (BorgBackup/restic) in a beautiful web UI, supporting databases and filesystems alike, and offering extensibility through Custom Backup Documents.

## Key Features

- **Deduplication-first** storage efficiency
- **Database-agnostic** — PostgreSQL, MySQL, MongoDB, SQLite, Redis, and custom
- **Container-native** — ships as Docker images, compose-ready
- **Beautiful by default** — dark-mode UI inspired by Linear and Vercel
- **Open source** — Apache 2.0, community-driven
