# Repository Guidelines

## Project Structure & Module Organization
- `src/` holds application code (Symfony controllers, services, entities).
- `config/` stores framework configuration and environment-specific settings.
- `public/` is the web root (front controller, assets served by the web server).
- `bin/console` is the primary CLI entry point for Symfony commands.
- `var/` contains runtime cache and logs; treat as generated.
- `vendor/` is Composer-managed dependencies; do not edit by hand.
- Environment config lives in `.env` and `.env.dev`.

## Build, Test, and Development Commands
- `composer install` installs dependencies and runs Symfony auto-scripts.
- `php bin/console` lists available Symfony commands.
- `php bin/console cache:clear` clears application cache (useful after config changes).
- `php -S localhost:8000 -t public` runs the built-in PHP server for local dev.

## Coding Style & Naming Conventions
- Follow `.editorconfig`: 4-space indentation, LF line endings, trim trailing whitespace.
- Use PSR-12 style for PHP code and standard Symfony naming (e.g., `App\Service\*`).
- Class names are `StudlyCase`, methods/variables are `camelCase`.
- Keep configuration files in `config/` and environment values in `.env*`.

## Testing Guidelines
- A `tests/` directory is not present yet, but `composer.json` defines `App\Tests\` autoloading.
- If you add tests, place them under `tests/` and name files `*Test.php` (e.g., `UserServiceTest.php`).
- No test runner is configured; add PHPUnit before introducing automated tests.

## Commit & Pull Request Guidelines
- Git history is not available in this workspace, so no commit message convention can be inferred.
- Use clear, imperative commit messages (e.g., "Add address validator"), and keep PRs scoped.
- Include a short description of behavior changes and any manual verification steps.

## Security & Configuration Tips
- Do not commit secrets; keep sensitive values in `.env.local` or deployment secrets.
- Avoid editing `var/` and `vendor/`; regenerate via Symfony/Composer instead.
