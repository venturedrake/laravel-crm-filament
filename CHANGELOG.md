# Changelog

All notable changes to `laravel-crm-filament` will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-08-10

### Upgrading

- **Requires `venturedrake/laravel-crm` `^2.4`.** The floor moved from `^2.0`, so this is
  breaking for anyone pinned to 2.0–2.3. Update both packages together:

  ```bash
  composer update venturedrake/laravel-crm venturedrake/laravel-crm-filament
  php artisan laravelcrm:filament-update
  ```

  `laravelcrm:filament-update` is new in this release and runs `laravelcrm:update` for you, in the
  right order — see below.

- **Re-seed your CRM permissions.** `laravelcrm:update` does this. Core 2.4.0 registers
  `ActivityPolicy` and `ProductAttributePolicy` for the first time; permissions that were
  never seeded now deny rather than being ignored.

- **Product attributes are now permission-gated.** No policy resolved for them before, and
  Filament allows when it finds none — so `ProductAttributeResource` was reachable by *every*
  panel user. It now enforces `view|create|edit|delete crm product attributes`. A host that
  never re-seeds gets a 403, not the 500 core's unguarded `hasPermissionTo()` would otherwise
  produce; the same guard covers Activities.

- **The seven plugin Pages are permission-gated.** This shipped in the 2.3.0 parity work
  (US-004) and was never announced. Dashboard, Activity feed, Calendar, General settings,
  Integrations, ClickSend and Updates all check a CRM permission now. The checks fail *open*
  on an install that never seeded the permission, and now log a warning when they do.

- **Multi-tenancy (`LARAVEL_CRM_TEAMS=true`) is not supported.** `laravelcrm:filament-install`
  refuses to install onto a tenanted CRM unless `--allow-teams` is passed, and a running panel
  shows a banner. See "Multi-tenancy" in the README.

- **Task edits no longer clear `start_at`.** If you have been running this plugin against a
  2.4.0 pre-release, check your tasks: every edit through the panel silently wiped the column.

- **The panel will report its database as behind until you run the update.** A pre-1.1.0 install
  has never had `crm_filament_db_version` stamped, and a missing marker counts as behind — so the
  Updates page and the system-check banner both carry the alert until
  `php artisan laravelcrm:filament-update` records it.

- **Add the composer hook once, by hand**, on a panel installed before this release — or just
  re-run `php artisan laravelcrm:filament-install`:

  ```json
  "scripts": {
      "post-autoload-dump": [
          "@php artisan package:discover --ansi",
          "@php artisan laravelcrm:upgrade --ansi",
          "@php artisan laravelcrm:filament-upgrade --ansi"
      ]
  }
  ```

  Without it, Filament's cached panel components hide newly-shipped resources and pages after a
  `composer update`.

- **Check any money figures you have taken from the panel.** Amounts are stored in minor units and
  most of them rendered 100× too large before this release; the two dashboard stats widgets
  rendered round totals 100× too small. Nothing stored was wrong — only what was displayed.

### Added

- **`laravelcrm:filament-upgrade`** — clears the cached Filament panel components (plus config,
  routes and views). Touches no database and never prompts, so it is safe to run unattended.
  `laravelcrm:filament-install` now appends it to your `composer.json` `post-autoload-dump`
  scripts, which is what makes newly-shipped resources and pages actually appear after a
  `composer update`: Filament caches the panel's discovered components, and a stale cache hides
  them. Pass `--no-composer-hook` to opt out; an unparseable `composer.json` is left untouched and
  the line to add is printed instead.

- **`laravelcrm:filament-update`** — runs `laravelcrm:filament-upgrade`, then `laravelcrm:update`,
  then publishes and runs this package's migrations, then records `crm_filament_db_version`.
  Core first, because `crm_invoice_payments` carries foreign keys into core's tables. Every step is
  fatal: a failure exits non-zero and says the panel has **not** been updated, rather than warning
  and carrying on. `--force` for deploy scripts, `--skip-crm-update` when core is already current.

- **Panel version reporting.** The panel now has its own version constant
  (`config('laravel-crm-filament.version')`, merged from a non-publishable `config/package.php`) and
  its own database-behind-code check. `Settings → Updates` reports both versions side by side under
  "Installed versions", both latest published releases under "Latest available", and its "Database
  update required" section is driven by the panel's check as well as core's. The system-check banner
  carries the same alert, naming `php artisan laravelcrm:filament-update`.

- **The panel's latest release is looked up on Packagist**, cached in a `crm_filament_version_latest`
  setting by the existing **Check for updates** action. Core's version API only knows core's
  releases, and Packagist is where `composer update` reads the same answer from — so the page cannot
  disagree with the command it tells you to run. Stable tags only, a plain GET with no install data
  attached, bounded by the same timeouts as core's check, and best-effort: a Packagist outage does
  not report the whole check as failed when core's half succeeded.

  `laravelcrm:filament-install` records `crm_filament_db_version` as well, right after it runs the
  migrations — a missing marker counts as behind, so an installer that skipped it would greet a
  fresh install with a banner telling the operator to update the schema it had just created. If the
  marker cannot be written the install still succeeds and prints the one-line remedy; unlike
  `laravelcrm:filament-update`, aborting mid-install over it would be the worse outcome.

  Both commands read the constant from `config/package.php` directly when the merged config cannot
  answer. `mergeConfigFrom()` is a no-op while the host's configuration is cached, and `config:clear`
  does not undo that mid-process — it deletes `bootstrap/cache/config.php` and leaves the loaded
  repository alone. Without the fallback, upgrading a host with a `config:cache` written before this
  release would migrate, silently skip the marker and still report success.

- **Settings → Templates sits directly below General settings** in the navigation, rather
  than at the bottom of the group next to Updates.
- **Role filter on the Users list**, matching core's user index: multi-select, scoped to CRM
  roles so a host's own Spatie roles neither appear in the dropdown nor widen the results.
- **Task `start_at`** on the task form, infolist, table (toggled off by default) and CSV
  export, and on both task relation managers. The calendar renders a task with both timestamps
  as a span, includes tasks that start before the visible window, and shifts both ends by the
  same delta when one is dragged.
- **User invitations**, replacing the plugin's own invite flow with core 2.4.0's
  `UserInvitation` lifecycle. An invitation is minted and mailed; the account is created (or
  granted CRM access) only when the invitee redeems it.
  - `Support\InvitationUrl` resolves the accept link, preferring the panel's own route.
  - `Pages\AcceptInvitation`, a panel-owned acceptance page registered outside the panel's
    auth middleware — core's acceptance routes live behind `laravel-crm.user_interface`, which
    this plugin's own installer turns off.
  - `Support\UserInvitationAcceptor` holds the three acceptance branches and core's
    fail-closed Owner-escalation check.
  - `Notifications\UserInvitationNotification`, which logs and bails when no accept route
    exists rather than throwing inside a queued job.
  - `Resources\UserInvitations\UserInvitationResource` — an index-only pending list with
    resend and revoke, cross-linked from the Users list with a pending badge.
- **Settings → Templates** (`Pages\TemplateSettings`), the PDF template picker, with inline
  thumbnails and an in-panel PDF preview. Both are served from the page rather than from
  core's routes, which are gated behind `laravel-crm.user_interface`.
- **Per-record PDF template picker** on the Quote, Order, Invoice, Purchase Order and Delivery
  forms (`Concerns\Forms\PdfTemplateSelect`).
- **Decimal quantities** (`decimal(15,3)`) on line items, with server-side drawdown
  validation: an invoice or delivery line cannot draw more than the order line has outstanding
  (`Support\RemainingQuantity`).
- **System-check banner** (`Livewire\SystemCheckBanner`), rendered through Filament's
  `CONTENT_START` hook. Shares core's `system_check_dismissed` key, so a dismissal carries
  across both UIs.
- **`LaravelCrmPlugin::allowUnsupportedTenancy()`** to acknowledge and silence the
  multi-tenancy warning.
- **`--allow-teams`** flag on `laravelcrm:filament-install`.
- New `Support\*` classes: `CrmPdf`, `PdfTemplatePreview`, `InvitationUrl`, `InvitableEmail`,
  `MoneyForm`, `OrderDrawdownPrefill`, `RemainingQuantity`, `TenancyGuard`, `UserGate`,
  `UserInvitationAcceptor`.
- `barryvdh/laravel-dompdf` and `spatie/laravel-permission` are now declared dependencies.
  Both were already used directly (`Concerns\DownloadsPdf`, `RoleResource`) and were only
  reaching the autoloader transitively.

### Changed

- **Money renders through one helper everywhere.** Amounts are stored in minor units, and
  Filament's `->money()` takes a `$divideBy` that defaults to `0` — falsy, so it never divides.
  Every money render now goes through `Support\MoneyForm::display()`, i.e. through cknow's
  `money()`, the same helper core's Blade views, PDFs and mail templates use, with
  `Support\CrmMoney::column()` and `::entry()` as the reusable factories. This replaces five
  competing ad-hoc patterns, so the same amount no longer renders differently depending on the
  page, and `/crm` matches core's own UI exactly. Kanban stage totals carry cents and render the
  same way.

- **The `Settings → Updates` page is read-only and tells operators to run
  `php artisan laravelcrm:filament-update`** rather than `php artisan laravelcrm:update`. The old
  instruction migrated core but never the panel's own table, and never recorded that the panel's
  database work had been done. Still two lines — the new command runs core's for you, in the right
  order. **Check for updates** now populates `version_latest` itself.

- Dismissing the system-check banner now fingerprints core's alerts **and** the panel's. Previously
  the stored signature covered core's alone, so a dismissal would have swallowed every subsequent
  panel alert.

- **PDF rendering goes through one place.** Ten hardcoded `laravel-crm::{x}.pdf` view strings
  across two parallel implementations collapse into `Support\CrmPdf`, which resolves the view
  through `PdfTemplateRegistry::viewForModel()` — record choice, then the Settings default,
  then a legacy view the host published and edited. Storage paths and filenames are unchanged,
  so the mailers still find their attachments.
- **PDF logos are inlined** as data URIs via core's `PdfLogo`. DomPDF refuses http(s) URLs
  unless `dompdf.enable_remote` is on, so the logo used to render as a broken-image box. The
  browser-facing paths (login, panel brand) still use `asset()`.
- **Order → Invoice and Order → Delivery prefill the outstanding quantity**, not the full
  ordered quantity, and drop lines with nothing left to draw. Header totals are recomputed from
  the prefilled lines so a partial invoice's header matches its own body.
- **The Dashboard's three data widgets are permission-gated.** The page itself is not — a user
  who can reach the panel has to land somewhere.
- **`ChecksCrmPermissions` still fails open**, but logs a memoised warning when it does.
- `FeatureCommentsRelationManager` passes the moderator flag explicitly to
  `FeatureService::comment()` rather than letting core re-derive it.
- `MonitorResource` surfaces `perf_notified_at` / `recovered_notified_at` and documents the
  `monitoring.*_alert_rate_limit_minutes` config keys.
- CI runs on every branch, adds a `--prefer-lowest` cell, and a `quality` job running
  `composer validate --strict`, PHPStan and Pint — none of which were previously exercised.

### Fixed

- **Most money in the panel rendered 100× too large.** Filament's `->money()` never divided,
  because its `$divideBy` argument defaults to a falsy `0`. Two further cases went the other way:
  the dashboard stats widgets called `money($cents / 100)`, and PHP's integer-preserving division
  makes a round total an `int`, which cknow reads back as minor units — so `4461300` rendered 100×
  too small. The Xero invoice and purchase-order infolists dropped the currency symbol entirely.
  See "Money renders through one helper everywhere" under Changed.

- **The published `invoice_payments` migration died with "Undefined variable `$prefix`"** on any
  host that did not already have a `crm_invoice_payments` table. The stub read the table prefix
  into `$prefix` and then referenced it for the `invoice_id` foreign key inside the
  `Schema::create()` closure without importing it. The test harness creates the table itself, so
  the stub's `hasTable()` guard returned before the closure ever ran and nothing caught it; a test
  now runs the shipped stub against a dropped table.

- **Callout body text was never rendered.** Filament's `x-filament::callout` — the same file in v4
  and v5 — draws its icon, heading, description, footer and controls but never echoes `$slot`, so
  a sentence written between the tags vanished while the border, icon and controls still drew.
  That left the system-check banner as an icon and a dismiss button with nothing between them, and
  the Template Settings "published override" warning showing its heading alone. Both now pass their
  body through `description`, and `CrmBladeStylingTest` scans every package view for body text in a
  callout's default slot.

- **The Templates and Updates pages rendered as unstyled text.** This package ships no CSS,
  and Filament's compiled stylesheet contains only its own `fi-*` classes — no general
  Tailwind utility layer, not even `flex` or `text-sm`. Every raw utility class in a package
  Blade view therefore resolved to nothing. Both pages now render through Filament components
  and schemas; `CrmBladeStylingTest` guards the rule mechanically.
- **Settings pages rendered their sections flush against each other.** `.fi-page-content` is
  already a grid with a row gap, but the `space-y-6` wrapper on General settings,
  Integrations, ClickSend and Reminders collapsed it into a single cell — and `space-y-6`
  itself does not exist in the compiled theme. Swapped for Filament's own `fi-sc-form`.
- **Editing a user stripped their CRM role.** `role_id` and `crm_team_ids` carried
  `->dehydrated(false)`, and `EditRecord::save()` dehydrates *before*
  `mutateFormDataBeforeSave()` reads them — so `$this->roleId` was always null and `afterSave()`
  fell through to `syncRoles([])`. Editing a user's name silently removed their role.
  **This affects anyone who edited a user through the panel.**
- **Task edits cleared `start_at`.** Core's `TaskService` writes `start_at` from the request
  unconditionally, and the plugin's task forms had no such field.
- **Editing an invoice dropped its order linkage.** `EditInvoice` hydrated `order_product_id`
  into each line but the repeater had no field for it, so every save re-submitted it absent and
  `InvoiceService` nulled it — making every subsequent outstanding-quantity calculation wrong.
- **Line items are rounded per line**, matching core's arithmetic. Without it, two lines of
  0.5 × $9.99 stored 500 + 500 while the header computed 999, and a perfectly consistent
  document showed a "broken document" badge with no way to clear it.
- Delivery line items had no quantity validation at all; they now validate and show the
  remaining quantity rather than the ordered one.
- The line-item quantity field is debounced, so typing `0.` no longer fires a recalculation
  against a non-numeric partial on every keystroke.
- `OrganizationResource` no longer hardcodes `App\Models\User` for its owner select.
- `UserResource::syncMorphRows()` uses `Str::uuid()` rather than `Ramsey\Uuid`, which the
  plugin does not require.

### Removed

- **The "Run update" action on the Updates page.** `laravelcrm:update` publishes assets, migrates
  and reseeds the live database and runs one-shot data backfills. That is a deployment step for an
  operator with a backup and a console, not a button behind a generic "are you sure?" modal. The
  page now reports installed versus latest version and shows the two commands to run. Tests assert
  it holds no Artisan dispatch path at all, so this cannot quietly return as a job or direct call.
- **The Install ID on the Updates page.** It identifies the install to the version API and is of no
  use to the operator reading the page. `fetchLatestVersion()` still reads, posts and persists
  `install_id` — this changes what the page displays, not what the version check does.
- The invite action's `name` field, `crm_access` toggle and `->unique()` email rule, along with
  `inviteCrmUser()`'s `forceCreate`. Creating the host user up front is exactly what core 2.4.0
  replaced: a mistyped address left a real account nobody could sign into, burning the unique
  email index.
- `UserImporter`'s teams branch, which produced a half-tenanted user — a pivot row and a
  `current_team_id`, with every subsequent panel query untenanted.
- The `crm_role` `TernaryFilter` on `RoleResource`, made redundant by the scoped query. It had
  no `query()` closure, so it SQL-errored on any install without the column.
- `version_latest_notes` from the Updates page. Nothing in core writes it, and it was rendered
  unescaped from a settings row whose only conceivable writer is a remote HTTP response.

### Security

- **`Role::assignableBy()` is now the single predicate** for "roles this caller may hand out",
  shared between every dropdown and — via core's `AssignableRole` rule — every validator, on
  user create, user edit, the invite action and the CSV importer. A non-Owner can neither see
  nor submit the `Owner` role. Entitlement is re-checked at the assignment site as well as in
  the rule, so a rejected escalation leaves no half-created user behind.
- **`RoleResource` was ungated.** Its `$model` pointed at Spatie's `Role`, but core registers
  `RolePolicy` for `VentureDrake\LaravelCrm\Models\Role` only, and `Gate::getPolicyFor()` walks
  child → parent — so no policy resolved and Filament allowed. **Any panel user could edit roles
  and grant themselves every permission in the system.** It now binds core's `Role`, enforces
  `RolePolicy`, scopes its query to CRM roles and its permission list to CRM permissions.
- **CSV import had no authorization at all** — no `->visible()`, no `->authorize()`, no
  `abort_unless` — and `ListUsers` registered it unconditionally, so **any panel user could
  bulk-create accounts with `crm_access = 1`.** Each importer now declares the create permission
  of its own resource, enforced both as visibility and as a server-side `abort_unless`.
- The invite action enforces `abort_unless` as the first statement of its action closure, not
  merely `->visible()`. A Livewire action is callable by anyone who can reach the page.
- Invitation acceptance re-checks the *inviter's* Owner entitlement at redemption time and fails
  closed, so an invitation minted before that guard existed — or written by any other path —
  cannot be redeemed into an Owner.
- The pending-invitations resource registers an index page only. `UserInvitation`'s route key is
  the 64-character redemption secret; a View or Edit page would put it in a URL, and from there
  into browser history, referrer headers and access logs.
- The system-check banner recomputes its dismissal signature server-side. The public Livewire
  property is client-writable, so persisting it would let a caller pin the banner shut
  permanently.
- `UserImporter` no longer hardcodes `App\Models\User`, and dispatches the plugin's own
  `SendUserInvite` rather than core's `App\Models\User`-bound `SendImportPasswordReset`, which
  silently mailed nothing on any other host model.

### Previously undocumented

These landed on `develop` after 1.0.0 and were never written up. They ship in 1.1.0.

- Registered the `invoice_payments` migration and made the Record-payment action resilient when
  it is absent (US-001).
- `Support\PortalUrl` route-resolves portal preview links and hides the ones core does not
  register (US-002).
- Fixed encrypted-table search and the default tax rate (US-003).
- Gated the seven plugin Pages behind CRM permissions (US-004).
- Closed relation-manager authorization escape hatches, removed a hardcoded `App\User` and made
  global search encryption-aware (US-005).
- Settings and Integrations parity: the `xero_quotes` toggle, logo upload, language options and
  an editable primary colour (US-006).
- The original user invite flow (US-007), now replaced by core's invitation lifecycle.
- Honoured the `dynamic_products` setting and the teams module toggle (US-008).
- Honoured `show_related_activity` by rolling related contacts' activity up to the parent
  (US-009).
- Purchase-order store-multiple, CSV export coverage and convert-to-invoice wiring (US-010).

## [1.0.0] - 2026-07-21

### Added

- **Plugin entry point + install command**
  - `LaravelCrmPlugin` Filament panel plugin with fluent module flags (`->modules()`, `->withChat()`, `->withEmailMarketing()`, `->withSmsMarketing()`, `->withCustomers()`, `->withXero()`, `->navigationGroup()`, `->brand()`, `->brandLogo()`, `->favicon()`, `->primaryColor()`).
  - `php artisan laravelcrm:filament-install` command that publishes `app/Providers/Filament/CrmPanelProvider.php` (panel at `/crm`) and registers it in `bootstrap/providers.php`. Interactive: detects existing Filament panels and offers two install modes — publish a standalone `/crm` panel (Branch A) or inject the plugin into an existing panel (Branch B). Flags `--mode=crm|inject`, `--panel=<id>`, and `--force` bypass the prompts for CI. Branch A offers to append `LARAVEL_CRM_USER_INTERFACE=false` to `.env` so the Filament panel can take over `/crm` from the legacy CRM Livewire UI; Branch B aborts on resource-slug collisions between the host's panel and the plugin unless `--force` is passed.
  - **Bootstraps `venturedrake/laravel-crm` if needed** — before publishing the panel, `laravelcrm:filament-install` checks whether the underlying CRM package has been installed (by looking for `config/laravel-crm.php`). If it hasn't, it offers to run `php artisan laravelcrm:install` for you so the panel isn't wired up over a half-installed package. Pass `--skip-crm-install` to bypass the check (useful for CI where the underlying install runs in a separate step).

- **Filament v4 + v5 support** — the plugin supports **Filament v4 alongside Filament v5** (`filament/filament`, `filament/forms`, `filament/tables` accept `^4.0 | ^5.0`).

- **v0.5 — Pipeline conversion actions + PDF download**
  - **Quote → Order**, **Order → Invoice**, **Order → Delivery**, **Order → Purchase Order** conversion actions on the respective View pages, routed through the core CRM services (`OrderService`, `InvoiceService`, `DeliveryService`, `PurchaseOrderService`) so observers, audits, and Xero sync still fire.
  - Each conversion stamps the back-link FK (`quote.accepted_at`, `order.quote_id`, `invoice.order_id`, etc.), opens an in-app notification deep-linking to the new record, and hides itself once the downstream record exists.
  - Shared `Concerns\DownloadsPdf` trait powering both the `Send …` mail action and a standalone **Download PDF** header action on Quote / Invoice / Purchase Order View pages.

- **v0.6 — CSV bulk imports**
  - Header **Import CSV** action on People, Organizations, Products, and Users list pages.
  - File upload + header-row toggle, reactive column-mapping selects populated from uploaded CSV headers, dedupe field, chunk size for batch processing, and a **Download sample CSV** footer action streaming a UTF-8-BOM template.
  - Importers route through the core CRM services (`PersonService`, `OrganizationService`, `ProductService`) and respect the encryption-at-rest setting (`laravel-crm.encrypt_db_fields`).

- **v0.7 — Standalone activity/file resources + polymorphic Files RM**
  - Top-level read-only resources: **Notes**, **Calls**, **Meetings**, **Lunches**, **Files**, **Activities** — global lists across all parents with an **Open parent** record action deep-linking back to the owning resource.
  - **FilesRelationManager** added to every parent resource (Lead, Deal, Person, Organization, Quote, Order, Invoice, Purchase Order, Delivery). Uploads write a `File` model row with full metadata and log an entry on the parent's activity timeline.

- **v0.8 — Campaign send + per-recipient analytics + performance widgets**
  - **Send now** header action on Email + SMS Campaign View pages with a recipient-count confirmation modal.
  - **Performance** infolist section with sent / failed / skipped counts and open-rate / click-rate / unsubscribe-rate (email) or delivery-rate / click-rate / unsubscribe-rate (SMS).
  - Per-recipient RelationManager columns: `last_opened_at`, `first_clicked_at`, `bounce_status` (email); `delivered_at`, `clicksend_message_id` with copy-to-clipboard (SMS).
  - Footer **Sends over time** chart on each campaign View page (auto-hides for sub-hour spans).
  - Dashboard **CampaignPerformanceChart** widget for the last 5 sent email campaigns.

- **v0.9a — Customer resource + settings lookups**
  - **CustomerResource** (slug `customers`) — full CRUD with encrypted global search, Files RM, gated on the `customers` module (`->withCustomers()`).
  - Settings-cluster lookup resources: **Contact Types**, **Address Types**, **Organization Types**, **Industries**, **Timezones**, **Product Attributes** (List + Create + Edit).
  - **Industry** select on `OrganizationResource::form()`.
  - **ProductVariationsRelationManager** on the Product resource (name + description + attribute select).

- **v0.9b — Lead/pipeline lookups + Teams + Updates page**
  - **LeadStatus** and **PipelineStageProbability** lookup resources in the Settings cluster.
  - **`lead_status_id`** Select on the Lead form; **`pipeline_stage_probability_id`** Select on the Pipeline Stage form.
  - **CrmTeams** resource in the Settings cluster with a **TeamMembersRelationManager** for attaching multiple users via `crm_team_user`.
  - **Updates** page (Settings cluster) showing current vs latest version + a **Check for updates** action that queues `laravelcrm:update`.

- **v0.10 — Calendar + Task kanban + Reminders settings**
  - Standalone **Calendar** page rendering Tasks (by `due_at`) + Calls / Meetings / Lunches (by `start_at`) in a FullCalendar month/week grid. Drag-to-reschedule updates the underlying record and writes an activity row.
  - **Task Kanban** sub-resource page (Open / Today / Overdue / Completed columns) with drag-to-complete.
  - **Reminders** settings page — per-type (Task / Call / Meeting / Lunch) checkbox + `hours_before` input, persisted as user-scoped `Setting` rows.

- **v0.11 — Chat widget embed / portal preview / branded auth**
  - ChatWidget **View** page rendering the embed `<script>` snippet with copy-to-clipboard and a live `<iframe>` preview of the widget.
  - Quote / Invoice **Preview portal** action promoted to a primary header action.
  - Branded **Login** + **Profile** auth pages: avatar upload (persisted to `Setting`), section grouping, link to the Reminders settings, and panel-level brand pickup from `SettingService` (`organization_name`, `logo_file`, `primary_color`) in `CrmPanelProvider`.

- **Localization**
  - All user-visible Resource strings (form/column labels, section headings, action labels) routed through `__('laravel-crm-filament::labels.…')`.
  - Ships three locale files under `resources/lang/`: English (canonical), French (starter), Spanish (starter).
  - `labels.php` grouped into namespaces: `fields`, `contact`, `sales`, `money`, `campaign`, `chat`, `file`, `sections`, `actions`, `import`, `misc`.
  - Publishable via `php artisan vendor:publish --tag=laravel-crm-filament-translations`.

### Requirements

- PHP `^8.2`
- Laravel `^11.0 | ^12.0 | ^13.0` (`illuminate/contracts`)
- Filament `^4.0 | ^5.0` (`filament/filament`, `filament/forms`, `filament/tables`)
- `venturedrake/laravel-crm` `^2.0`

[1.1.0]: https://github.com/venturedrake/laravel-crm-filament/releases/tag/v1.1.0
[1.0.0]: https://github.com/venturedrake/laravel-crm-filament/releases/tag/v1.0.0
