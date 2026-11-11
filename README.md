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

Contacts are available to every member of a workspace, while global administrators can manage contacts in all workspaces. Contact records, groups, tags and import/export are scoped to the selected workspace. Other future workspace-specific records and routes must explicitly enforce membership and scope their queries. New protected pages and actions must define and enforce their own permissions on the server and add them to the administrator's assignment UI.

## Contacts

After migrating and seeding translations, open Contacts and select a workspace. Contacts use international phone numbers beginning with `+` and contain a name, optional email, consent status, groups and tags. New contacts default to opted out; users are responsible for recording opt-in only when appropriate. This module records consent but does not send messages or automatically enforce consent in future sending features.

CSV and Excel `.xlsx` files use the columns `name,phone,email,opted_in` in that order, with `opted_in` set to `0` or `1`. Format Excel phone cells as text to preserve the `+` and digits. Imports accept up to 5,000 rows and 2 MB; invalid files are rejected without changes. A matching phone in the same workspace updates the name and email but preserves its existing consent, groups and tags; new rows use the specified consent. Exports include only those four columns, not group or tag membership. CSV export prefixes spreadsheet-formula-like values with an apostrophe to reduce formula injection risk.

Excel support requires PHP's ZIP extension (as well as the extensions listed by Composer). Enable it for both the web server and command-line PHP and restart the web server if needed. Avoid opening untrusted spreadsheets outside a protected environment.

The header's light/dark switch stores the preference in the browser on the current device. Currency settings are available in the Settings page's section navigation and start with SAR; administrators can add or remove three-letter ISO 4217 codes, and each user can choose an available currency in the header. Administrators are responsible for entering valid ISO codes. Removing a currency resets users who selected it to SAR. Run `php artisan db:seed --class=TranslationSeeder` after upgrading to add new interface translations without overwriting existing edits.

The currency selection is a display preference, not an exchange rate or a conversion. The formatting helper accepts an amount already denominated in the specified currency; it must never be used to relabel an unconverted amount. Future financial records should store the original amount in integer minor units (or exact decimal), its ISO currency code, and, when conversion is introduced, the exact rate, direction, source and effective time used for each transaction. Never recompute historical transactions using a current rate. Dashboard content is a placeholder.

## WhatsApp Cloud API

After migrating and seeding translations, an administrator can configure one shared account under Settings > WhatsApp. Enter the WhatsApp business account ID, phone number ID, supported Graph API version, access token, Meta app secret, and a verify token chosen by the administrator. The three secrets are encrypted using `APP_KEY` and are never displayed again; leave their fields blank to retain them. Preserve the application key and secure database and queue backups. The connection test checks the saved phone number ID and access token against Meta, not webhook subscription or delivery.

Configure the displayed publicly reachable HTTPS callback URL and the same verify token in the Meta app, then subscribe to the WhatsApp `messages` webhook field for the business account. Ensure the application URL and reverse proxy HTTPS configuration are correct. Meta's POST signature is verified using the app secret before a job is queued. Events for other business accounts or phone numbers are ignored. Incoming messages and individual status updates are stored idempotently as event records; this foundation does not send messages or provide an inbox. Event payloads can contain personal data, so restrict database access and establish an appropriate retention policy.

## Templates

After migrating and seeding translations, administrators can manage shared templates for the configured business account on the Templates page. Create and preview local drafts, then submit them to Meta for review. The editor supports marketing and utility templates with a text or image/video/document header, body, optional footer, and quick-reply, static URL, or phone buttons. Media headers require an example upload handle obtained from Meta's resumable upload API; the application does not upload media. Numbered body variables require examples in order, and a text header supports a single `{{1}}` example. Meta applies additional template rules and may reject a submission.

Sync imports all pages of templates from the configured account and refreshes their contents and statuses. Templates submitted or imported from Meta cannot be edited or deleted here; use Meta to manage those templates. A submission timeout or ambiguous error may mean Meta created the template despite the error. Sync and check Meta before restoring the draft using the recovery action, or a duplicate may result. A stuck submission can be restored after five minutes only after that check. Switching the configured business account hides records for the previous account rather than moving them.

Subscribe the business account to the `message_template_status_update` webhook field as well as `messages`, and run the queue worker for status changes to be processed. Status events are applied by account and template ID, with older timestamps ignored; sync to reconcile missed or ambiguous events. The status shown is Meta's reported status or event and is not a guarantee of send eligibility. Templates are not used to send messages by this module.

Use a persistent queue connection and run a supervised queue worker, for example `php artisan queue:work --tries=4`, in production. The webhook responds after dispatch, not after processing. Failed jobs should be monitored and retried using Laravel's queue tooling. Queue job payloads are encrypted; changing `APP_KEY` without retaining the previous key also prevents queued jobs and stored credentials from being decrypted.

## Tests

The PHPUnit configuration specifies an in-memory SQLite database, and the test base class refuses to run against any other database. Clear cached production configuration and database environment overrides before running tests:

```sh
php artisan test --compact
```
