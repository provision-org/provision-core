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
