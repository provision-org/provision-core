# Contributing to Provision

Thank you for your interest in contributing to Provision. This guide will help you get set up and familiar with the project workflow.

## Getting Started (Docker)

```bash
git clone https://github.com/your-org/provision.git
cd provision
cp .env.example .env
docker compose up -d
```

Visit `http://localhost:8000` once the containers are running.

## Development Without Docker

Requirements: PHP 8.3+, Node 22+, Composer, SQLite.

```bash
git clone https://github.com/your-org/provision.git
cd provision
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate
composer run dev
```

This starts the Laravel server, queue worker, log tail, and Vite dev server concurrently.

### Local cloud provisioning via `herd share`

When testing the team-creation → DigitalOcean / Hetzner / Linode provisioning flow locally, the cloud-init script on the freshly created droplet needs to POST progress callbacks back to your local app. Use Laravel Herd's `herd share` (Expose tunnel) to give your `provision.test` site a public URL the droplet can reach:

```bash
herd share
```

`herd share` automatically updates `APP_URL` in `.env` to its public URL so generated callback URLs point at the tunnel.

**Known quirk:** when you run `npm run dev` (Vite HMR) with `herd share` active, Vite is exposed via a *second*, hash-named tunnel. Two-fer of plane-wifi behavior to watch for:

1. **First navigation to a fresh route can render an empty page.** Vite's tunnel sometimes drops the first dynamic-import request after a long-idle period; you'll see a `TypeError: Failed to fetch dynamically imported module` in the console. Hard-reload (`Cmd+Shift+R`) once and it'll mount.
2. **If your network blips, both tunnels can desync** — the page HTML still references the old Vite tunnel hash but the dev server registered a new one. Symptom: pages stop rendering and the Vite tunnel URL returns 404. Fix: stop `npm run dev`, run `npm run build` once, and `rm public/hot`. Herd will then serve static built assets directly from `provision.test` with no Vite tunnel involved. (This is what the E2E test harness does — it's more resilient than HMR on unreliable wifi.)

## Running Tests

```bash
php artisan test --compact
```

To run a specific test file or filter by name:

```bash
php artisan test --compact tests/Feature/Auth/LoginTest.php
php artisan test --compact --filter=testUserCanLogin
```

Tests use an in-memory SQLite database. No additional setup is required.

## Code Style

PHP formatting uses Laravel Pint. TypeScript uses ESLint and Prettier.

```bash
# PHP
vendor/bin/pint --dirty

# TypeScript
npm run lint
npm run format
```

Run both before submitting a pull request.

## Making Changes

1. Fork the repository and clone your fork.
2. Create a feature branch from `main`: `git checkout -b feature/your-change`
3. Make your changes.
4. Add or update tests to cover your changes.
5. Run the test suite and confirm it passes.
6. Run the linters and formatters listed above.
7. Commit with a clear, descriptive message.
8. Push your branch and open a pull request against `main`.

## Pull Request Guidelines

- Keep PRs focused on a single concern. Avoid mixing unrelated changes.
- Write a clear description of what the PR does and why.
- Include tests for new features and bug fixes.
- Reference any related issues in the PR description.
- Ensure CI passes before requesting review.
- Be responsive to feedback during review.

## Architecture Overview

Provision is a Laravel 12 + React 19 SPA using Inertia.js v2 as the bridge between server and client. Key directories:

- `app/Http/Controllers/` -- Server-side controllers returning Inertia responses
- `resources/js/pages/` -- React page components (file path maps to route)
- `resources/js/components/ui/` -- Reusable UI components (Radix UI + Tailwind CSS v4)
- `app/Models/` -- Eloquent models
- `app/Jobs/` -- Queued jobs for server provisioning and agent management

## Module Development

Provision uses a module system for extensible integrations. If you are adding a new cloud provider, channel, or agent harness driver:

- See `app/Contracts/Modules/` for the interfaces your module must implement.
- See `app/Modules/` for existing implementations as reference.

Follow the patterns established by existing modules. Each module should include its own tests.

## What We Are Looking For

We welcome contributions in the following areas:

- **Bug fixes** -- Reproduce the issue, write a failing test, then fix it.
- **Cloud provider integrations** -- New providers beyond Hetzner and DigitalOcean.
- **Channel integrations** -- New messaging channels (e.g., Microsoft Teams, WhatsApp).
- **Agent harness drivers** -- New agent framework integrations beyond the existing drivers.
- **Documentation improvements** -- Corrections, clarifications, or new guides.
- **Test coverage** -- Expanding test coverage for existing features.

## Questions

If you have questions about contributing, open a discussion on GitHub. For bug reports, use the issue tracker with a clear reproduction case.

## License

By contributing, you agree that your contributions will be licensed under the same license as the project.
