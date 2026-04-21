# Changelog

## [0.13.0](https://github.com/cellar-backup/cellar/compare/v0.12.3...v0.13.0) (2026-04-21)


### Features

* click database navigates to backups + database settings modal ([#48](https://github.com/cellar-backup/cellar/issues/48)) ([9fae77d](https://github.com/cellar-backup/cellar/commit/9fae77d297df35303836f83ac0cf8e55a490c98d))

## [0.12.3](https://github.com/cellar-backup/cellar/compare/v0.12.2...v0.12.3) (2026-04-21)


### Bug Fixes

* health endpoint returns 500 when Redis unreachable ([#45](https://github.com/cellar-backup/cellar/issues/45)) ([f043939](https://github.com/cellar-backup/cellar/commit/f043939df907e766aeec5ad5b230fd54a4079c57))

## [0.12.2](https://github.com/cellar-backup/cellar/compare/v0.12.1...v0.12.2) (2026-04-21)


### Bug Fixes

* entrypoint creates .env from template when missing ([2510071](https://github.com/cellar-backup/cellar/commit/251007198c637f736d3efdad164c72dbfb1ccdb7))

## [0.11.0](https://github.com/cellar-backup/cellar/compare/v0.10.1...v0.11.0) (2026-03-30)


### Features

* auto-generate APP_KEY on first boot with env var priority ([#20](https://github.com/cellar-backup/cellar/issues/20)) ([17085bd](https://github.com/cellar-backup/cellar/commit/17085bdc1acd3de39251f4a31d92a21dc19e8d57))

## [0.10.1](https://github.com/cellar-backup/cellar/compare/v0.10.0...v0.10.1) (2026-03-30)


### Bug Fixes

* **scheduler:** trigger release for prune retention fix and P2 improvements ([741e3c4](https://github.com/cellar-backup/cellar/commit/741e3c468ddc6abab95fafe4d36d6badd713cf82))

## [0.10.0](https://github.com/cellar-backup/cellar/compare/v0.9.0...v0.10.0) (2026-03-29)


### Features

* security hardening — first-boot protection, encrypted backups, CI hardening, tests ([14ab707](https://github.com/cellar-backup/cellar/commit/14ab70709d73db733cdab66036034b4d85c6bd6c))
