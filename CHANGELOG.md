# Changelog

## [0.15.0](https://github.com/cellar-backup/cellar/compare/v0.14.3...v0.15.0) (2026-04-24)


### Features

* add CouchDB support ([#82](https://github.com/cellar-backup/cellar/issues/82)) ([5cd2cd0](https://github.com/cellar-backup/cellar/commit/5cd2cd0c1d657f66070ce6ddb5ca9692304dfe8f))

## [0.14.3](https://github.com/cellar-backup/cellar/compare/v0.14.2...v0.14.3) (2026-04-21)


### Bug Fixes

* trivy review correctly parses grouped dates and checks NVD status ([#80](https://github.com/cellar-backup/cellar/issues/80)) ([d35d3f4](https://github.com/cellar-backup/cellar/commit/d35d3f4405aec00e930fd0b5ae37926227030169))

## [0.14.2](https://github.com/cellar-backup/cellar/compare/v0.14.1...v0.14.2) (2026-04-21)


### Bug Fixes

* dependabot auto-merge uses PAT via pull_request_target ([#77](https://github.com/cellar-backup/cellar/issues/77)) ([f007250](https://github.com/cellar-backup/cellar/commit/f00725074203ea07996052adb889d886e2f5e2a5))

## [0.14.1](https://github.com/cellar-backup/cellar/compare/v0.14.0...v0.14.1) (2026-04-21)


### Bug Fixes

* use GITHUB_TOKEN for dependabot auto-merge (secrets restricted on dependabot PRs) ([#75](https://github.com/cellar-backup/cellar/issues/75)) ([915631a](https://github.com/cellar-backup/cellar/commit/915631a94ccd27d68ed0693ecc4b7361bf4f4f57))

## [0.14.0](https://github.com/cellar-backup/cellar/compare/v0.13.5...v0.14.0) (2026-04-21)


### Features

* automated monthly review of Trivy suppressed CVEs ([#64](https://github.com/cellar-backup/cellar/issues/64)) ([0051b57](https://github.com/cellar-backup/cellar/commit/0051b5730a01ead81b779ce194bc531bb6b3edf2))

## [0.13.5](https://github.com/cellar-backup/cellar/compare/v0.13.4...v0.13.5) (2026-04-21)


### Bug Fixes

* restore button in detail drawer now shows confirmation modal ([#58](https://github.com/cellar-backup/cellar/issues/58)) ([1fe2cca](https://github.com/cellar-backup/cellar/commit/1fe2cca3bbcde9ce73e3e0fe08b1578e1bd4f868))

## [0.13.4](https://github.com/cellar-backup/cellar/compare/v0.13.3...v0.13.4) (2026-04-21)


### Bug Fixes

* entrypoint handles commented and missing env vars in .env ([#56](https://github.com/cellar-backup/cellar/issues/56)) ([7cbe425](https://github.com/cellar-backup/cellar/commit/7cbe42564c6127ba8d05b5113000f250034d8617))

## [0.13.3](https://github.com/cellar-backup/cellar/compare/v0.13.2...v0.13.3) (2026-04-21)


### Bug Fixes

* WebSocket connects on correct port behind reverse proxy ([#54](https://github.com/cellar-backup/cellar/issues/54)) ([8a3fdce](https://github.com/cellar-backup/cellar/commit/8a3fdce35749a56d35f8d85621135ff01b5439b1))

## [0.13.2](https://github.com/cellar-backup/cellar/compare/v0.13.1...v0.13.2) (2026-04-21)


### Bug Fixes

* release-please now bumps config/cellar.php version correctly ([#52](https://github.com/cellar-backup/cellar/issues/52)) ([a5765cb](https://github.com/cellar-backup/cellar/commit/a5765cb035b948ebf309af1dac7afbdde4ca012d))

## [0.13.1](https://github.com/cellar-backup/cellar/compare/v0.13.0...v0.13.1) (2026-04-21)


### Bug Fixes

* add trusted proxies support for HTTPS behind reverse proxy ([#50](https://github.com/cellar-backup/cellar/issues/50)) ([a9188c8](https://github.com/cellar-backup/cellar/commit/a9188c87a67b0021e3cdf438e6f1e5f76eb8d547))

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
