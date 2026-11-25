# ADR-009: Root-Level Tool Configs Take Precedence

**Status:** Accepted
**Date:** 2026-11-25
**Deciders:** Walmir Silva
**Context:** KaririCode Framework Devkit v1.2.0

## Context

ADR-005 moved every tool config into the generated `.kcode/` directory, and runners always passed the generated file.

KaririCode Spec V4.3.3 §31 keeps `phpunit.xml.dist`, `phpstan.neon` and `psalm.xml` at the component root, and the components revised for V4.3.3 carry the §29.1 gates there: PHPStan level `max`, Psalm `errorLevel` 1, a Conformance suite, PER Coding Style. ADR-007 of kariricode/property-inspector rejected the devkit for that reason: its generated configs run PHPStan level 9 and Psalm level 3 and ignore the root files.

## Decision

A tool config committed at the project root wins over the generated one. Runners check the candidates in each tool's own lookup order and fall back to `.kcode/`:

| Tool | Root candidates (in order) | Generated fallback |
|---|---|---|
| PHPUnit | `phpunit.xml`, `phpunit.xml.dist` | `.kcode/phpunit.xml.dist` |
| PHPStan | `phpstan.neon`, `phpstan.neon.dist`, `phpstan.dist.neon` | `.kcode/phpstan.neon` |
| Psalm | `psalm.xml`, `psalm.xml.dist` | `.kcode/psalm.xml` |
| PHP-CS-Fixer | `.php-cs-fixer.php`, `.php-cs-fixer.dist.php` | `.kcode/php-cs-fixer.php` |
| Rector | `rector.php` | `.kcode/rector.php` |

The binaries still come from `.kcode/vendor/bin/`, so a V4.3.3 component keeps its root configs, drops its QA `require-dev` entries and runs everything through kcode.

`kcode migrate --keep-configs` removes everything else (Docker scaffolding, QA packages, superseded configs such as `phpcs.xml`) and keeps the root tool configs.

## Alternatives Considered

- **Always use the generated configs.** Forces V4.3.3 components to choose between the Specification and the devkit.
- **Encode every §29.1 gate in `devkit.php`.** Duplicates the Specification in a second format; root configs are what the Specification names.
- **A `devkit.php` switch.** Adds a setting whose only correct value for a spec-governed component is "use the root files".

## Consequences

### Positive

- One toolchain for components with and without root configs.
- The Specification prevails where it defines component tooling, as required by its own precedence rule.

### Negative

- A component that was not migrated keeps using its legacy root configs (for example an old PHPUnit schema) until `kcode migrate` removes them.
- Root configs that reference `vendor/…` paths of QA packages (for example PHPStan extension includes) must point to `.kcode/vendor/…` once those packages leave `require-dev`.

## References

- ADR-005: Centralized `.kcode/` Directory Convention
- KaririCode Spec V4.3.3 §29.1, §31
- kariricode/property-inspector ADR-007 — Quality Toolchain and Supply Chain of Spec V4.3.3
