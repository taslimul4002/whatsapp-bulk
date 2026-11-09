# Namaa WhatsApp Bulk

Laravel application foundation using Inertia, React, TypeScript, Tailwind CSS and shadcn-compatible components. Authentication uses Laravel's session guard directly; no authentication starter kit or Fortify is used.

## Setup

Install PHP 8.5, Composer and Node.js 20.19+ or 22.12+, then run:

```sh
composer install
npm install
```

Copy `.env.example` to `.env` if needed, configure `APP_KEY` and the MySQL connection, and build the frontend with `npm run build` (or use `npm run dev` during development). Generate a key with `php artisan key:generate` only for a new application; changing an existing key invalidates encrypted data and sessions.

**Database migrations are not run by the setup scripts.** Before running `php artisan migrate`, verify the configured connection and database name and make sure you have permission to change that database. Back up existing data when appropriate.

After migrations, create the initial administrator from an interactive terminal:

```sh
php artisan app:create-admin
```

The command prompts for account details and a confirmed password. It does not create a default account or expose the password in command history. Public registration is disabled. Administrators create other accounts from the Users page.

## Access and workspaces

Global administrators can manage users, assign their workspace memberships and page permissions, and create workspaces. Ordinary users can only access the Dashboard and Workspaces pages when an administrator grants the matching permission. The Workspaces page lists only their assigned workspaces; profile and personal settings remain available to every signed-in user. Navigation visibility and route access use the same permissions, and administrative endpoints are enforced server-side.

This is a workspace foundation, not a complete tenant isolation layer for future features. Any new workspace-specific records and routes must explicitly enforce membership and scope their queries. New protected pages and actions must define and enforce their own permissions on the server and add them to the administrator's assignment UI.

Settings currently store the light/dark preference in the browser on the current device. Dashboard content is a placeholder.

## Tests

The PHPUnit configuration specifies an in-memory SQLite database, and the test base class refuses to run against any other database. Clear cached production configuration and database environment overrides before running tests:

```sh
php artisan test --compact
```
