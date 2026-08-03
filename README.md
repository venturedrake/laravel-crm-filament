# Laravel CRM — Filament Plugin

[![Latest Version on Packagist](https://img.shields.io/packagist/v/venturedrake/laravel-crm-filament.svg?style=flat-square)](https://packagist.org/packages/venturedrake/laravel-crm-filament)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/venturedrake/laravel-crm-filament.svg?style=flat-square)](https://packagist.org/packages/venturedrake/laravel-crm-filament)

A native Filament plugin for [`venturedrake/laravel-crm`](https://github.com/venturedrake/laravel-crm). Wraps the existing CRM domain layer (models, services, observers, policies, encryption, audit) in Filament Resources, Clusters, Pages, and Widgets so the same database can be administered via Filament alongside (or instead of) the legacy `/crm` MaryUI/Livewire interface.

## Requirements

- PHP `^8.2`
- Laravel `^11.0 | ^12.0 | ^13.0`
- Filament `^4.0 | ^5.0`
- `venturedrake/laravel-crm` `^2.0`

## Installation

```bash
composer require venturedrake/laravel-crm-filament
php artisan laravelcrm:filament-install
```

The install command first checks whether `venturedrake/laravel-crm` itself has been installed (by looking for `config/laravel-crm.php`). If it hasn't, you'll be asked whether to run `php artisan laravelcrm:install` first — say yes and the underlying CRM package's config, migrations, seed data, and owner user will be set up before the Filament panel is wired up. Pass `--skip-crm-install` to bypass this check.

Once the CRM package is confirmed installed, the command inspects the host app for existing Filament panels and drives an interactive prompt:

- **No panels detected** → publishes a standalone CRM panel automatically.
- **Only the plugin's own `crm` panel already installed** → re-runs the standalone publish (use `--force` to overwrite).
- **One or more other panels detected** → asks whether to publish a standalone `/crm` panel or inject the plugin into an existing panel.

### Branch A — standalone `/crm` panel

Publishes `app/Providers/Filament/CrmPanelProvider.php` (id `crm`, path `/crm`) and registers it in `bootstrap/providers.php`. This is the default when no other panels exist.

Because the core CRM ships a Livewire UI that also lives at `/crm`, the command then prompts:

> Add `LARAVEL_CRM_USER_INTERFACE=false` to your `.env` now (disables the legacy `/crm` Livewire UI so the Filament CRM panel can take over `/crm`)?

Answer **yes** to have the command append the line for you. Answer **no** to keep both UIs — you'll need to either set `LARAVEL_CRM_USER_INTERFACE=false` manually later or change the panel path in the published `CrmPanelProvider`. If the config is already `false` (via env or config edit), the prompt is skipped.

### Branch B — inject into an existing panel

Adds `->plugin(LaravelCrmPlugin::make())` (plus the matching `use` import) directly to the target panel's PanelProvider file. Nothing is published — the plugin becomes part of the host's existing panel.

Before injecting, the command builds a slug map from the target panel's registered resources and the plugin's own resources. If any slugs collide, the injection is aborted with a table of colliding slugs; re-run with `--mode=crm` (standalone panel) or resolve the collision in your host app. Pass `--force` to inject anyway.

### Scripting the install (CI / provisioning)

The prompts can be bypassed with flags:

| Flag | Effect |
|---|---|
| `--mode=crm` | Force Branch A (standalone `/crm` panel), no interactive choice |
| `--mode=inject --panel=<id>` | Force Branch B injection into panel `<id>` |
| `--force` | Overwrite an existing `CrmPanelProvider.php` (Branch A) or bypass slug-collision detection (Branch B) |
| `--skip-crm-install` | Skip the `venturedrake/laravel-crm` install check (assume it has already been installed) |

Example — non-interactive injection into an existing `admin` panel:

```bash
php artisan laravelcrm:filament-install --mode=inject --panel=admin --force
```

Add `Filament\Models\Contracts\FilamentUser` to `App\Models\User` and implement `canAccessPanel()`:

```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use VentureDrake\LaravelCrm\Traits\HasCrmAccess;

class User extends Authenticatable implements FilamentUser
{
    use HasCrmAccess;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasCrmAccess();
    }
}
```

## Registering the plugin

```php
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;

->plugin(
    LaravelCrmPlugin::make()
        // ->modules(['leads' => true, 'deals' => true, /* ... */])
        // ->withChat()
        // ->withEmailMarketing()
        // ->withSmsMarketing()
        // ->withCustomers()
        // ->withXero()
        // ->navigationGroup('CRM')
        // ->brand('Acme CRM')
        // ->brandLogo('https://example.com/logo.svg')
        // ->favicon('https://example.com/favicon.ico')
        // ->primaryColor('#05b3a9')
)
```

By default the plugin reads `config('laravel-crm.modules')` to decide which gated resources to register. Use `->modules([...])` to override per-panel.

If no `brand()` / `brandLogo()` is set the plugin falls back to the core CRM's `laravel-crm.settings`: `organization_name` and `logo_file`. If no `primaryColor()` is set the panel defaults to `#05b3a9` (the CRM's teal accent).

## Feature catalog

The panel was built up in phases. Each phase below maps to a chunk of functionality in the plugin.

### v0.5 — Pipeline conversion actions + PDF download

- **Quote → Order**, **Order → Invoice**, **Order → Delivery**, **Order → Purchase Order** conversion actions on the respective View pages, all routed through the core CRM services (`OrderService`, `InvoiceService`, `DeliveryService`, `PurchaseOrderService`) so observers, audits, and Xero sync still fire.
- Each conversion stamps the back-link FK (`quote.accepted_at`, `order.quote_id`, `invoice.order_id`, etc.), opens an in-app notification with a deep link to the new record, and hides itself once the downstream record exists.
- Shared `Concerns\DownloadsPdf` trait powers both the `Send …` mail action and a standalone **Download PDF** header action on Quote / Invoice / Purchase Order View pages.

### v0.6 — CSV bulk imports

Header **Import CSV** action on People, Organizations, Products, and Users list pages. The action exposes:

- File upload + header-row toggle.
- Reactive column-mapping selects populated from the uploaded CSV's headers.
- Dedupe field (e.g. lowercased email, `code`).
- Chunk size for batch processing.
- **Download sample CSV** footer action that streams a UTF-8-BOM template.

Importers route through the core CRM services (`PersonService`, `OrganizationService`, `ProductService`) and respect the encryption-at-rest setting (`laravel-crm.encrypt_db_fields`).

### v0.7 — Standalone activity/file resources + polymorphic Files RM

- Top-level read-only resources: **Notes**, **Calls**, **Meetings**, **Lunches**, **Files**, **Activities** — each shows global lists of the entity across all parents, with an **Open parent** record action that deep-links back into the owning resource.
- **FilesRelationManager** added to every parent resource (Lead, Deal, Person, Organization, Quote, Order, Invoice, Purchase Order, Delivery). Uploads write a `File` model row with full metadata and log an entry on the parent's activity timeline.

### v0.8 — Campaign send-now, per-recipient analytics, performance widgets

- **Send now** header action on Email + SMS Campaign View pages (with recipient-count confirmation modal).
- **Performance** infolist section with sent / failed / skipped counts and open-rate / click-rate / unsubscribe-rate (email) or delivery-rate / click-rate / unsubscribe-rate (SMS).
- Per-recipient RelationManager columns: `last_opened_at`, `first_clicked_at`, `bounce_status` (email); `delivered_at`, `clicksend_message_id` with copy-to-clipboard (SMS).
- Footer **Sends over time** chart on each campaign View page (auto-hides for sub-hour spans).
- Dashboard **CampaignPerformanceChart** widget for the last 5 sent email campaigns.

### v0.9a — Customer resource + lookup resources

- **CustomerResource** (slug `customers`) — full CRUD with encrypted global search, Files RM, gated on the `customers` module (`->withCustomers()`).
- Settings-cluster lookup resources: **Contact Types**, **Address Types**, **Organization Types**, **Industries**, **Timezones**, **Product Attributes** (all List+Create+Edit).
- **Industry** select on `OrganizationResource::form()`.
- **ProductVariationsRelationManager** on the Product resource (name + description + attribute select).

### v0.9b — Lead/pipeline lookups + Teams + Updates page

- **LeadStatus** + **PipelineStageProbability** lookup resources in the Settings cluster.
- **`lead_status_id`** Select on the Lead form; **`pipeline_stage_probability_id`** Select on the Pipeline Stage form.
- **CrmTeams** resource in the Settings cluster with a **TeamMembersRelationManager** for attaching multiple users via `crm_team_user`.
- **Updates** page (Settings cluster) showing current vs latest version + a **Check for updates** action that queues `laravelcrm:update`.

### v0.10 — Calendar, Task kanban, Reminders settings

- Standalone **Calendar** page rendering Tasks (by `due_at`) + Calls/Meetings/Lunches (by `start_at`) in a FullCalendar month/week grid. Drag-to-reschedule updates the underlying record and writes an activity row.
- **Task Kanban** sub-resource page (Open / Today / Overdue / Completed columns) with drag-to-complete.
- **Reminders** settings page — per-type (Task / Call / Meeting / Lunch) checkbox + `hours_before` input, persisted as user-scoped `Setting` rows.

### v0.11 — Chat widget embed UI, portal preview, branded auth

- ChatWidget **View** page renders the embed `<script>` snippet with copy-to-clipboard and a live `<iframe>` preview of the widget.
- Quote / Invoice **Preview portal** action promoted to a primary header action.
- Branded **Login** + **Profile** auth pages: avatar upload (persisted to `Setting`), section grouping, link to the Reminders settings, and panel-level brand pickup from `SettingService` (`organization_name`, `logo_file`, `primary_color`) in `CrmPanelProvider`.

## What's in the panel

**Main panel resources** (gated on `config('laravel-crm.modules')`):

Paths below assume the default `/crm` mount from the published `CrmPanelProvider`; adjust if you've changed `->path(...)` or injected the plugin into a differently-mounted host panel.

| Resource | Slug | Module gate |
|---|---|---|
| Lead | `/crm/leads` | `leads` |
| Deal | `/crm/deals` | `deals` |
| Quote | `/crm/quotes` | `quotes` |
| Order | `/crm/orders` | `orders` |
| Invoice | `/crm/invoices` | `invoices` |
| Purchase Order | `/crm/purchase-orders` | `purchase-orders` |
| Delivery | `/crm/deliveries` | `deliveries` |
| Email Campaign | `/crm/email-campaigns` | `email-marketing` |
| SMS Campaign | `/crm/sms-campaigns` | `sms-marketing` |
| Chat | `/crm/chat` | `chat` |
| Customer | `/crm/customers` | `customers` |
| Person | `/crm/people` | always |
| Organization | `/crm/organizations` | always |
| Task | `/crm/tasks` | always |
| Product | `/crm/products` | always |
| Notes / Calls / Meetings / Lunches / Files / Activities | `/crm/{slug}` | always (read-only global views) |

**Standalone pages**:

- `/crm/calendar` — month/week grid for tasks + calls + meetings + lunches.
- `/crm/leads/kanban`, `/crm/deals/kanban`, `/crm/quotes/kanban`, `/crm/tasks/kanban`.

**Dashboard widgets**: open leads / open deals / tasks due today + open-leads-by-stage chart + recent activity list + (when email-marketing is enabled) CampaignPerformanceChart.

**Settings cluster** at `/crm/settings`:

- Pipelines, Pipeline Stages, Pipeline Stage Probabilities, Lead Statuses, Lead Sources, Labels, Tax Rates, Product Categories, Product Attributes.
- Contact Types, Address Types, Organization Types, Industries, Timezones.
- Field Groups + Fields (custom field definitions, including option lists and per-model scoping).
- Roles (Spatie\Permission, with Owner/Admin protected from edit/delete).
- Email Templates, SMS Templates, Chat Widgets.
- CRM Teams (with Team Members relation manager).
- General settings page (key/value via `SettingService`).
- Integrations page (Xero connect/disconnect + sync toggles, ClickSend status).
- Reminders page (per-user activity reminders).
- Updates page (version check + `laravelcrm:update`).

**RelationManagers**: Notes, Tasks, Calls, Meetings, Files inline on Lead / Deal / Person / Organization / Customer edit pages (polymorphic via `HasCrmActivities`). Files RM also on Quote / Order / Invoice / Purchase Order / Delivery. Each new entry logs to the core CRM `Activity` table for the timeline feed. Email/SMS campaign view pages get a per-recipient RelationManager showing per-row send/open/click/unsubscribe state.

**Per-resource actions**:

- Quote / Invoice / Purchase Order: **Send** (generates dompdf PDF, sends signed-portal mailable via the core's `Mail\SendQuote` / `SendInvoice` / `SendPurchaseOrder`) + **Download PDF**.
- Quote / Invoice: **Preview portal** (jumps to `/p/quotes/...` or `/p/invoices/...`).
- Quote: **Convert to order**. Order: **Convert to invoice / delivery / purchase order**.
- Email Campaign: **Send now**, **Preview** (renders `EmailCampaignMessage::renderPreview()` in a modal), **Schedule**, **Cancel**.
- SMS Campaign: **Send now**, **Preview** (rendered body + segment count via `SmsCampaignMessage::renderPreview()` / `::segmentCount()`), **Schedule**, **Cancel**. Body Textarea on the form shows a live segment-count estimate via `helperText`.
- Chat: **Reply**, **Close conversation**, **Convert to lead** (creates Person + Lead from visitor); thread view subscribes to `echo:crm-chat.{external_id},.chat.message` for realtime message refresh when Laravel Echo is configured.
- Tasks: **Mark complete** bulk action; Task kanban drag-to-complete.

## Custom fields

Models with the core's `HasCrmFields` trait (Lead, Deal, Quote, Order, Invoice, PurchaseOrder, Person, Organization, Task, Product) automatically get a "Custom fields" section in their Filament forms when `Field` rows are scoped to the model via `FieldModel`. The plugin's `Concerns\HasCrmCustomFields` trait handles:

- Mapping `Field::type` (text / textarea / date / checkbox / select / select_multiple / radio / checkbox_multiple) to the right Filament component.
- Loading `FieldValue` rows on edit.
- Saving `FieldValue` rows on create / update via `updateOrCreate`.

Define fields via the Settings cluster (`/crm/settings/fields`).

## Localization

All user-visible Resource strings (form/column labels, section headings, action labels) are routed through `__('laravel-crm-filament::labels.…')`. The plugin ships three locale files under `resources/lang/`:

| Locale | Path |
|---|---|
| English (canonical) | `resources/lang/en/labels.php` |
| French (starter) | `resources/lang/fr/labels.php` |
| Spanish (starter) | `resources/lang/es/labels.php` |

`labels.php` is grouped into namespaces: `fields`, `contact`, `sales`, `money`, `campaign`, `chat`, `file`, `sections`, `actions`, `import`, `misc`.

### Overriding a translation

Publish the translation files into the host app:

```bash
php artisan vendor:publish --tag=laravel-crm-filament-translations
```

This copies the plugin's `resources/lang/{locale}/labels.php` into the host app's `lang/vendor/laravel-crm-filament/{locale}/labels.php` (Laravel's default vendor-translation location). Edit any key in the published file — Laravel will pick up the override automatically without touching the plugin source.

You can also add a brand-new locale by dropping a `labels.php` with the same structure as `en/labels.php` into `lang/vendor/laravel-crm-filament/{your-locale}/`. Make sure the structure mirrors `en/labels.php` exactly — any key the plugin asks for that's missing falls back to the English value via Laravel's translation fallback.

### Switching the panel locale

The panel respects the application locale set by `app()->setLocale($locale)`. To switch on a per-user basis, set the locale early in the request (e.g. in a middleware or `User::booted()`):

```php
Auth::user() && app()->setLocale(Auth::user()->locale ?? config('app.locale'));
```

## Migrating from the `/crm` Livewire UI

The Filament panel and the core CRM's Livewire UI both target the same database. Because the Filament panel now defaults to `/crm` (matching the Livewire UI's route prefix), the install command asks up front whether to append `LARAVEL_CRM_USER_INTERFACE=false` to `.env` — the Livewire UI's kill switch. Pick the path that matches your rollout:

- **Full cutover.** Accept the install prompt so `LARAVEL_CRM_USER_INTERFACE=false` is written to `.env`. The Filament panel serves `/crm`; the Livewire UI stops mounting its routes. This is the simplest path once you're confident the Filament panel covers everything you need.
- **Side-by-side during transition.** Decline the install prompt and change the published `CrmPanelProvider`'s `->path('crm')` to a distinct path (e.g. `->path('crm-next')`). Both UIs then run against the same database until you're ready to flip the switch.

Regardless of which path you pick, the plugin reads and writes the same `crm_*` tables. It adds exactly one table of its own, `crm_invoice_payments`, which records the payment history behind the invoice **Mark paid** action; `laravelcrm:filament-install` publishes and runs that migration in both `crm` and `inject` mode. If you wire the plugin up by hand instead of running the installer, publish it yourself with `php artisan vendor:publish --tag=crm-filament-migrations && php artisan migrate` — without the table, Mark paid still updates the invoice totals but silently records no payment history. Access control routes through the same `HasCrmAccess` trait and the same Spatie roles/permissions seeded by `php artisan laravelcrm:permissions`, so a user who can see the Livewire UI can see the Filament panel (subject to `canAccessPanel()`). All writes from either UI go through the same observers (`Observers/`), services (`Services/`), and audit listeners; encrypted columns continue to be transparently encrypted/decrypted via `HasEncryptableFields`.

### Differences hosts should know about

Paths in this table assume the default `/crm` Filament mount with the Livewire UI kill switch enabled.

| Behaviour | Livewire UI (before) | Filament panel |
|---|---|---|
| Routing key | mixed `id` / `external_id` | always `external_id` for entity resources, integer `id` for lookup tables that lack `external_id` |
| Branding source | `laravel-crm.settings` (org name + logo) | `LaravelCrmPlugin::brand()` / `brandLogo()` or fallback to the same settings |
| Custom fields | Livewire `HasCrmFields` partial | `Concerns\HasCrmCustomFields` trait via `static::crmCustomFieldsSection(...)` |
| Files | per-model uploads | unified `FilesRelationManager` + read-only `/crm/files` global view |
| Activities | per-entity timeline | per-entity timeline **plus** global `/crm/activities` |
| Calendar | none | `/crm/calendar` aggregating tasks/calls/meetings/lunches |
| Reminders | global config | per-user `/crm/settings/reminders` |
| Updates | `laravelcrm:update` artisan only | `/crm/settings/updates` UI |

## Testing

```bash
./vendor/bin/pest --no-coverage
```

The Pest test suite covers routing, model binding, cluster wiring, RelationManager attachment, custom-fields trait integration, plugin module gating, branding setters, role protection, localization key parity, and structural assertions for every phase's resources/actions/widgets. As of v1.0.0 the suite is 227 tests.

## Contributing

Pull requests are welcome. Before opening a PR, please make sure the test suite and code style checks pass locally:

```bash
./vendor/bin/pest --no-coverage
./vendor/bin/pint
```

## Support

- Bug reports and feature requests: <https://github.com/venturedrake/laravel-crm-filament/issues>
- Core CRM package (models, services, migrations): <https://github.com/venturedrake/laravel-crm>

## License

MIT — same as the core CRM package.
