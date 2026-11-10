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

The header's light/dark switch stores the preference in the browser on the current device. Currency settings are available in the Settings page's section navigation and start with SAR; administrators can add or remove three-letter ISO 4217 codes, and each user can choose an available currency in the header. Administrators are responsible for entering valid ISO codes. Removing a currency resets users who selected it to SAR. Run `php artisan db:seed --class=TranslationSeeder` after upgrading to add new interface translations without overwriting existing edits.

The currency selection is a display preference, not an exchange rate or a conversion. The formatting helper accepts an amount already denominated in the specified currency; it must never be used to relabel an unconverted amount. Future financial records should store the original amount in integer minor units (or exact decimal), its ISO currency code, and, when conversion is introduced, the exact rate, direction, source and effective time used for each transaction. Never recompute historical transactions using a current rate. Dashboard content is a placeholder.

## Tests

The PHPUnit configuration specifies an in-memory SQLite database, and the test base class refuses to run against any other database. Clear cached production configuration and database environment overrides before running tests:

```sh
php artisan test --compact
```
