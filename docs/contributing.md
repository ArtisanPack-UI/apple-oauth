---
title: Contributing
---

# Contributing

The authoritative contribution guide lives at `CONTRIBUTING.md` in the package root. This page summarizes what a code contributor to `artisanpack-ui/apple-oauth` specifically needs to know.

## Development setup

Fork and clone the package repository, then pull it into an ArtisanPack UI dev app via a Composer path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../apple-oauth",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "artisanpack-ui/apple-oauth": "@dev"
    }
}
```

The `artisanpack-ui-dev` app in this repository is already wired up this way — symlinks live under `packages/apple-oauth/`.

Install package dependencies:

```bash
cd packages/apple-oauth
composer install
```

## Running tests

```bash
composer test          # runs Pest
```

Filter to a single file or test:

```bash
./vendor/bin/pest tests/Feature/OAuth/OAuthManagerTest.php
./vendor/bin/pest --filter="handles the callback"
```

The test suite uses Orchestra Testbench with an in-memory SQLite database.

## Code style

```bash
composer lint          # php-cs-fixer --dry-run + phpcs
composer fix           # php-cs-fixer fix
composer cs            # phpcs only
composer cs:fix        # phpcbf (auto-fix what phpcs can)
```

The package follows the ArtisanPack UI code style — WordPress-style spacing (`if ( $condition )`, `[ 'key' => 'value' ]`), Yoda conditions, aligned operators, trailing commas in multiline. `composer fix` handles most of it; `composer cs` catches the rest.

> **Do not run `vendor/bin/pint` in this package.** Plain Pint strips the WordPress-style spacing and reformats the entire repo. The `.php-cs-fixer.dist.php` is the real formatter; use `composer fix`.

Configuration:

- `.php-cs-fixer.dist.php` — PHP-CS-Fixer rules, including custom `spaces_inside_parenthesis` / `spaces_inside_brackets` fixers from `artisanpack-ui/code-style-pint`.
- `phpcs.xml` — PHPCS rules for what PHP-CS-Fixer can't enforce (disallowed functions, PHP tag placement).

Run both before opening a pull request.

## Branch strategy

- `main` — release-only.
- `release/x.y.z` — integration branches per milestone (e.g. `release/1.0`).
- `feature/*`, `bugfix/*`, `chore/*`, `docs/*` — prefixes for the type of change. Cut from the current `release/*` branch, merge back into it.

Pull requests target the current `release/*` branch, not `main` directly.

## Commit messages

Conventional commits, matching the ArtisanPack UI convention:

```
feat: add scope registry with ap.apple-oauth.scopes filter hook
fix: preserve refresh_token on re-authorization
docs: clarify APP_KEY rotation behavior
chore: bump orchestra/testbench to ^10.2
```

Types: `feat`, `fix`, `docs`, `chore`, `refactor`, `test`, `ci`.

## PRs must include

1. **A test** — every behavior change gets a Pest test (unit or feature). Bug fixes get a regression test.
2. **Docs updates** — if you're touching public API, changing config, or shifting behavior in a way callers can observe, update the corresponding page under `/docs`. This documentation set is committed to the repo, not derived at build time — updates ship with the code that changes.
3. **Changelog entry** — add a line to `CHANGELOG.md` under `## [Unreleased]`.
4. **Passing CI** — `composer lint` and `composer test` must be green.

## Doc contributions

Docs live under `/docs`. Every file has YAML frontmatter (`title:`), and inter-doc links use the GitLab wiki page-name style (`[Getting Started](Getting-Started)`, `[Config Driver](Drivers/Config)`, `[OAuth Manager](API-Reference/OAuth-Manager)`).

Every subdirectory has a sibling `.md` at its parent's level with the same base name — that file is the section landing page. `docs/oauth/` has `docs/oauth.md` next to it; the same for `installation/`, `drivers/`, and `api-reference/`.

When adding a new page:

1. Add the file with frontmatter and an `# H1` matching the frontmatter title.
2. Link it from its section landing page.
3. Cross-link mentions of classes / methods to their API-reference page.
4. Prefer tables for config keys, method signatures, and exception → cause mappings.

Docs-only PRs are welcome — clarifying a rough section, adding a real-world snippet, fixing a broken link, or filling a gap in the FAQ.

## What to work on

Good first issues:

- Improving test coverage for edge cases in the drivers.
- Documenting fields you had to figure out from source.
- Filling gaps in the FAQ with questions you had while onboarding.

Bigger scoped work:

- JWKS-backed id_token signature verification for callers that want it beyond identity-persistence trust.
- Additional configuration drivers (Vault, HashiCorp Consul, AWS Parameter Store, GCP Secret Manager).
- First-class multi-tenant helpers instead of the manual driver rebind pattern.

## Getting help

- GitHub issues on the [ArtisanPack-UI/apple-oauth repo](https://github.com/ArtisanPack-UI/apple-oauth).
- The maintainer email address is in `composer.json`.

## Code of conduct

See the top-level `CONTRIBUTING.md` for the code of conduct that covers every ArtisanPack UI project. In short: don't be a jerk.
