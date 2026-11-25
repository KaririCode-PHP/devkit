# ADR-008: On-Demand Toolchain Provisioning for a Global `kcode`

**Status:** Accepted
**Date:** 2026-11-25
**Deciders:** Walmir Silva
**Context:** KaririCode Framework Devkit v1.2.0

## Context

Since 1.1.2 the toolchain is installed into `.kcode/vendor/` by `kcode init`, and components already ship with an empty `require-dev`. Two gaps remained:

1. A developer had to remember `kcode init` after cloning, after editing `devkit.php` and after every devkit upgrade; a stale `.kcode/` produced confusing failures.
2. The README still recommended `composer require --dev kariricode/devkit`, which puts a QA dependency back into every component.

The goal is a component that contains only its source, its `composer.json` and optionally a `devkit.php`: tests and validations run through a globally installed `kcode`, and nothing else.

## Decision

Every toolchain command (`test`, `analyse`, `cs:fix`, `rector`, `format`, `quality`, `run`) prepares `.kcode/` before it runs, when the project is a KaririCode component:

| Condition | Meaning |
|---|---|
| `composer.json` name starts with `kariricode/` | Ecosystem component |
| `devkit.php` exists (even empty) | Explicit opt-in for any project |
| `.kcode/` exists | The project already uses kcode |

Preparation steps, each skipped when already current:

1. **Configs** — regenerate `.kcode/` when the fingerprint of devkit version + `composer.json` + `devkit.php` changed, or a generated file is missing.
2. **Container** — start it in `--docker` mode.
3. **Toolchain** — `composer install --working-dir=.kcode` when `.kcode/vendor/autoload.php` is missing or the fingerprint of `.kcode/composer.json` + `devkit.lock` changed.
4. **Project dependencies** — `composer install` at the root when `vendor/autoload.php` is missing (tests bootstrap it).

Fingerprints live in `.kcode/state.json`. Concurrent kcode processes serialize on `.kcode/.provision.lock`. `KCODE_NO_PROVISION=1` disables the behaviour.

The project root is located by walking up to the nearest `composer.json` (skipping `.kcode/`), so every command works from any subdirectory.

### Reproducible toolchains: `devkit.lock`

`kcode init --lock` copies `.kcode/composer.lock` to `devkit.lock` at the project root. When `devkit.lock` is committed, it is copied back before every install, so all machines and CI run the exact same tool versions. Without it, a lock left by a previous install is discarded when the manifest changes, so new constraints resolve again.

### Test-support libraries

Any Composer package can join the toolchain through `devkit.php` → `tools` (for example `'mockery/mockery' => '^1.6'`). PHPUnit runs from `.kcode/vendor/bin/` and loads `.kcode/vendor/autoload.php` first, so tests find those classes without a `require-dev` entry. Tools without a dedicated command run through `kcode run <binary>`.

## Alternatives Considered

- **Keep explicit `kcode init`.** Simple, but stale `.kcode/` directories were the most common failure after devkit upgrades.
- **Provision every Composer project.** A global tool must not install packages into unrelated projects; non-KaririCode projects get a hint to run `kcode init` instead.
- **Bundle the tools inside `kcode.phar`.** Rejected in ADR-002: tool versions would be tied to devkit releases and the PHAR would grow to tens of megabytes.

## Consequences

### Positive

- A fresh clone runs `kcode test` (or `kcode quality`) with no preparation: configs, toolchain and project dependencies are installed on first use, then reused.
- `require-dev` stays empty; test-support libraries live in `devkit.php`.
- CI may keep `kcode init`; it is idempotent with provisioning.

### Negative

- The first command after cloning takes as long as a Composer install.
- Behaviour depends on the project's identity (`kariricode/` prefix or `devkit.php`), which must be documented.
- Without a committed `devkit.lock`, tool versions float within the constraints in `.kcode/composer.json`.

## References

- ADR-002: Zero External Dependencies in Core
- ADR-004: Three-Tier Binary Resolution Strategy
- ADR-005: Centralized `.kcode/` Directory Convention
- KaririCode Spec V4.3.3 §32 (supply chain) — `devkit.lock` is the reproducibility mechanism for the toolchain
