# Changelog

All notable changes to **KaririCode Devkit** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [1.2.0] — 2026-11-25

Components no longer carry QA dependencies or Docker files: a globally installed `kcode` provisions the toolchain on demand and runs it on the host or in one generated PHP container.

### Added

- **Container runtime (ADR-007):** `kcode init` renders `.kcode/docker/` — `Dockerfile` (`php:<version>-cli-alpine`, PCOV, Xdebug off, intl, zip, Composer 2, git), a bind-mounted `php.ini` and `compose.yaml` — from the new `devkit.php` → `docker` key (`enabled`, `php_version`, `image`, `extensions`, `packages`, `ini`, `environment`, `ports`, `services`, `service`). The project is mounted at its host path; the image is tagged `kcode-php:<php>-<Dockerfile hash>` and shared by identical settings.
- **Container commands:** `up [--build]`, `down [--volumes]`, `shell [shell]`, `exec <command>`, `ps`, `logs`.
- **`--docker` / `--no-docker`:** global options (also `KCODE_DOCKER`, `docker.enabled`) that run every tool, Composer install and audit inside the container, started on demand, as the host UID:GID, with `XDEBUG_*` forwarded.
- **On-demand provisioning (ADR-008):** inside a KaririCode component (`kariricode/*`, or any project with `devkit.php`) every toolchain command regenerates stale configs, installs a missing or stale toolchain into `.kcode/vendor/` and runs `composer install` when `vendor/autoload.php` is missing. Fingerprints live in `.kcode/state.json`; `KCODE_NO_PROVISION=1` opts out.
- **Project root location:** `kcode` works from any subdirectory; the root is the nearest `composer.json`, skipping `.kcode/`.
- **`devkit.lock`:** `kcode init --lock` pins the installed tool versions; a committed lock is installed exactly on every machine.
- **`kcode run <binary>`:** runs any toolchain binary (for example Infection or ParaTest added through `devkit.php` → `tools`).
- **`kcode clean --all`:** removes the whole `.kcode/` directory.
- **`kcode migrate`:** removes per-component Docker scaffolding (`.docker/`, `docker/`, compose files, `Dockerfile`, `.dockerignore`, the legacy `.env.example` and Docker `Makefile`), superseded QA tools (PHP_CodeSniffer, PHP Insights, Security Checker, PHPMD, phpcpd, ECS) and their configs; moves test-support libraries (Mockery, Infection, ParaTest, prophecy-phpunit, bypass-finals) to `devkit.php` → `tools`; rewrites Composer scripts that called the removed binaries into `kcode` commands. New `--keep-configs` flag keeps root tool configs.
- **Root config precedence (ADR-009):** a committed `phpunit.xml(.dist)`, `phpstan.neon(.dist)`, `psalm.xml(.dist)`, `.php-cs-fixer(.dist).php` or `rector.php` wins over the generated file, so KaririCode Spec V4.3.3 §31 components keep their gates while using the kcode toolchain.
- **`KCODE_DOCKER_BINARY`:** alternative Compose-compatible CLI (for example `podman`).
- **Integration test suite:** runs the real `bin/kcode` against throw-away projects with stand-in `docker` and `composer` binaries.

### Changed

- `Devkit::VERSION` is `1.2.0`; it still reported `1.0.0` in every 1.x release.
- composer.json rewrites keep the original indentation and keep `"require-dev": {}` as an object.
- `tools` accepts any Composer package name, not only the five tool short names.
- README, SPEC-001/002/003 (1.2.0), ADR-004 and ADR-005 amended; ADR-007, ADR-008 and ADR-009 added.

### Fixed

- `ProcessExecutor` deadlocked when a tool wrote more than one pipe buffer (64 KiB) to stderr; stdout and stderr are now drained concurrently.
- `kcode test` ran no test at all on machines without PCOV or Xdebug (coverage mode): `--no-coverage` is added automatically unless coverage is requested.
- Hints printed by `init` and `migrate` showed a literal `\033[1m` instead of bold text.
- `Makefile` quality targets pointed at a non-existent `vendor/bin/kcode`.
- PHPUnit 12.5 notices for mocks without expectations in `DevkitTest`.

---

## [1.1.4] — 2026-03-05

### Added

- **`RectorApplyRunner`:** `kcode format` and `kcode rector --fix` apply changes without the `--no-dry-run` option Rector removed.
- PHPUnit runner passes `--testdox` and `--colors=always`; the `Functional` suite is detected.

### Changed

- `MigrationService` extracted from `MigrationReport`, which became a pure value object (ARFA 1.3).
- composer.json description and keywords.

### Fixed

- Generated `phpunit.xml.dist`: `failOnWarning`/`failOnRisky` set to `false`, `restrict*` attributes removed from `<source>`, no default `src/Exception` coverage exclusion.

## [1.1.3] — 2026-03-03

### Fixed

- Generated `phpunit.xml.dist` sets `beStrictAboutCoverageMetadata="false"`, so `#[UsesClass]` works on classes that extend vendor base classes.

## [1.1.2] — 2026-03-02

### Added

- **`KcodeComposerGenerator`:** writes `.kcode/composer.json`; `kcode init` installs the tools into `.kcode/vendor/` (`Devkit::installTools()`).
- **`ComposerResolver`:** resolves the Composer binary (`COMPOSER_BINARY`, PATH, known paths).

### Changed

- Tier-1 binary resolution is `.kcode/vendor/bin/` (the PHAR-internal tier was unreachable).
- `InitCommand` receives `MigrationDetector` through its constructor.

### Fixed

- `kcode.phar` download URL in the release notes; CS-Fixer formatting; Psalm `PossiblyFalseOperand`.

## [1.0.1] — 2026-03-02

### Changed

- The release workflow builds `kcode.phar` with `bin/build-phar.php` instead of Box.

## [1.0.0] — 2026-03-02

First tagged release of the CLI toolchain. It combines the initial feature set (written 2025-12-01) and the hardening pass that preceded the tag.

### Added (initial toolchain)

- **Core:** `Devkit` orchestrator, `ProjectDetector`, `ProjectContext`, `DevkitConfig`, `ProcessExecutor`.
- **Contracts:** `ConfigGenerator` and `ToolRunner` interfaces.
- **Configuration generators:** PHPUnit, PHPStan, PHP-CS-Fixer, Rector, Psalm.
- **Tool runners:** PHPUnit, PHPStan, PHP-CS-Fixer, Rector, Psalm, Composer Audit.
- **CLI commands:** `init`, `migrate`, `test`, `analyse`, `cs:fix`, `rector`, `security`, `quality`, `format`, `clean`.
- **Migration detector:** Scans `composer.json` require-dev and project root for redundant files and cache paths. Interactive cleanup with `--dry-run` and `--no-interaction`.
- **CLI framework:** Zero-dependency `Application` router with ANSI output, argument parsing, and passthrough support.
- **Value objects:** `ToolResult`, `QualityReport`, `MigrationReport` (immutable, readonly).
- **Exception hierarchy:** `DevkitException`, `ConfigurationException`, `ToolException`.
- **Build automation:** `Makefile` with targets: `install`, `build`, `verify`, `self-test`, `quality`, `security`, `clean`, `release`.
- **CI/CD:** GitHub Actions workflows for quality checks (`ci.yml`) and automated PHAR releases (`release.yml`).
- **Binary resolution:** Three-tier strategy — PHAR → vendor → global PATH.
- **Override system:** `devkit.php` at project root with type-safe merging. Scaffold with `kcode init --config`.
- **Documentation:** README, 6 ADRs, 3 specifications, docs index.

### Added (hardening pass before the tag)

- **`bin/build-phar.php`:** Native PHP PHAR builder — compiles `kcode.phar` without `humbug/box`. Added as a workaround for a Box 4.x / PHP 8.4 incompatibility (`chdir(): Not a directory` during `endBuffering()`).
- **Test suite — `tests/Unit/Exception`:** `DevkitExceptionTest`, `ConfigurationExceptionTest`, `ToolExceptionTest`.
- **Test suite — `tests/Unit/ValueObject`:** `ToolResultTest`, `QualityReportTest`, `MigrationReportTest`.
- **Test suite — `tests/Unit/Core`:** `ProjectContextTest`, `DevkitConfigTest`.
- **Full unit test suite:** 41 tests, 81 assertions — all passing on PHP 8.4 / PHPUnit 12.4.5.

### Changed

- **`composer.json`:** `phpunit/phpunit` constraint updated from `^11.0` to `^12.0`. Composer scripts renamed to `kcode:*` prefix to avoid collision with built-in Composer commands. `infection/extension-installer: false` added to `allow-plugins` to suppress spurious warnings. The `build` script now calls `bin/build-phar.php` instead of `box compile`.
- **`Makefile`:** `build` target no longer requires `humbug/box` — uses `PHAR_BUILDER := bin/build-phar.php`. `VERSION` simplified to `git describe --tags` only (no `box.json` fallback). `check-env` shows PHAR builder status instead of Box version. `_require-box` guard replaced by `_require-phar-builder`. `install`/`install-prod` add `--no-scripts`. New `security` target. New `_require-kcode` guard on all quality targets. `distclean` no longer removes `composer.lock`. `check-env` correctly detects `vendor/bin/kcode`.
- **`.github/workflows/ci.yml`:** Added `develop` branch to push/PR triggers.
- **`.github/workflows/code-quality.yml`:** Replaced 378-line legacy workflow (PHPMD + runtime `composer require`) with a lean 160-line workflow aligned to the kcode CLI. Jobs: `dependencies → security → phpstan → cs-fixer → quality-summary`.
- **`.gitignore`:** Expanded from 8 to 42 patterns (legacy scaffold files, IDE artefacts, OS artefacts, environment files, tool caches).
- **`docs/BUILDING.md`:** Fully rewritten for `bin/build-phar.php` — removed all `humbug/box` references.
- **`phpunit.xml`:** `memory_limit` reduced from 1G to 256M. Attributes reformatted one-per-line.

### Fixed

- **`bin/kcode`:** Fatal `TypeError` when invoked via Composer scripts — `$argv` was inaccessible inside the static IIFE. Fixed by passing it as an explicit parameter (`$argv ?? []`).
- **`src/Configuration/PhpStanConfigGenerator`:** Removed `checkMissingIterableValueType` and `checkGenericClassInNonGenericObjectType` — both were removed from PHPStan 2.x core and caused `Invalid configuration` errors.
- **`src/Configuration/CsFixerConfigGenerator`:** Added `(string)` cast on `preg_replace()` return values in `exportRules()` to satisfy PHPStan level-9 type constraints.
- **`src/Core/Devkit`:** Added `false` guard on `file_get_contents()` in `appendGitignore()`. Added `@var \SplFileInfo` annotation in `removeRecursive()`.
- **`src/Core/DevkitConfig`:** Added `@var array<string, mixed>` before assigning loaded overrides. `toolVersions()` now correctly returns `array<string, string>` via `array_filter`.
- **`src/Core/MigrationDetector`:** Added `false !== $raw` guard on `file_get_contents()`. Added `@var` annotations on `json_decode()` result.
- **`src/Core/ProjectDetector`:** Added `false !== $raw` guard on `file_get_contents()`. Extracted `$psr4Source`, `$psr4Test`, `$projectName` with proper `is_array()` guards. Fixed `detectNamespace()` and `detectPhpVersion()` with nested `is_array()` / `is_string()` checks.
- **`src/Runner/*`:** Added `#[\Override]` attribute to `toolName()`, `vendorBin()`, and `defaultArguments()` across all six runners.
- **`src/ValueObject/MigrationReport`:** Added `false` guard after `file_get_contents()` and `json_encode()`. Extracted `$requireDev` with `@var` and `is_array()` guard. Fixed `removePackagesFromComposer()` to write back the updated `$requireDev` slice into `$composer` before `json_encode()`. Added `@var \SplFileInfo` in `removeRecursive()`.
- **Security:** `phpunit/phpunit` updated from 12.4.2 → 12.4.5 (fixes CVE-2026-24765, HIGH).

### Removed

- **`humbug/box`:** Removed as a build dependency. `bin/build-phar.php` replaces it.
- **Legacy scaffold files (42):** `.config/`, `.docs/`, `.make/`, `devkit/`, `docker-compose.yml`, `.env.example`, `.env.xdebug`, root-level tool configs, `build/`, `coverage/`.
- **Prototype test files (4):** Targeting removed classes `UserProfile`, `Email`, `UserId`, `UserRole`.

**Net result:** PHPStan level 9 — **0 errors** (down from 35). PHPUnit — **41 tests, 81 assertions passing**.

---

[Unreleased]: https://github.com/KaririCode-PHP/devkit/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/KaririCode-PHP/devkit/compare/v1.1.4...v1.2.0
[1.1.4]: https://github.com/KaririCode-PHP/devkit/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/KaririCode-PHP/devkit/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/KaririCode-PHP/devkit/compare/v1.0.1...v1.1.2
[1.0.1]: https://github.com/KaririCode-PHP/devkit/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/KaririCode-PHP/devkit/releases/tag/v1.0.0
