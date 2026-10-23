# Changelog

All notable changes to `laravel-crm-filament` will be documented in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.4.0] - 2026-10-23

Catches up to `venturedrake/laravel-crm` 2.5.0: product variations, the contact ↔ organization
link, and the purchase-order line fix.

### Upgrading

- **Requires `venturedrake/laravel-crm` `~2.5.0`** (was `~2.4.2`). Variations on line items only
  persist through core 2.5.0's services, and core 2.5.0 deletes the `ProductAttribute` model the
  panel's Product Attributes page was built on. Update both packages together:

  ```bash
  composer update venturedrake/laravel-crm venturedrake/laravel-crm-filament
  php artisan laravelcrm:filament-update
  ```

  `laravelcrm:filament-update` runs core's `laravelcrm:update`, which runs core 2.5.0's three
  migrations: the variation columns, the purchase-order line `comments` / `order_product_id`
  columns, and the removal of the `crm product attributes` permissions.

- **Settings → Product Attributes is gone**, along with `ProductAttributeResource`. It never had a
  table to work against in core. Use custom fields attached to Products instead. A host that links
  to the resource's routes (`filament.*.resources.product-attributes.*`) needs to drop those links.

### Added

- **Product variations.** The product form has a Variations section to add, edit, reorder,
  deactivate and remove variations, each with its own SKU, barcode, price and active flag. Saving
  hands the rows to core's `ProductService::syncVariations()`: a removed variation is
  soft-deleted, so lines on it keep their label, and a variation without a price sells at the
  product price. The product view page lists the variations, and the products index finds a
  product by a variation's SKU.
- **A variation select on line items** on deals, quotes, orders, invoices and purchase orders. It
  appears only for a product with active variations, or for a line already on one. Picking a
  variation prices the line from the variation's price in the document currency, falling back to
  the product price. Changing the product clears the variation. Edit forms load each line's
  variation.
- **Conversions carry the variation**: quote → order, order → invoice and order → purchase order.
  Order → purchase order uses the variation's own price row (`cost_per_unit`, then `unit_price`)
  when it has one. The delivery and purchase-order line pickers show `Product — Variation`. PDFs,
  emails and the portal print it through core's views.

### Fixed

- **Tables could not be scrolled sideways after a Filament update.** A host whose composer hooks
  never ran `filament:upgrade` kept serving the Filament stylesheet from its first install. On
  Filament v5 that copy sized the content area at `100vw` beside the sidebar, so the right-hand
  side of every resource table, scrollbar included, sat off-screen. `laravelcrm:filament-upgrade`,
  which the installer adds to `post-autoload-dump`, now runs `filament:assets` too, so every
  `composer install` republishes the stylesheet for the installed Filament release.
- **A contact person and an organization saved together were never linked to each other.**
  Saving a lead, deal, quote, order, invoice or purchase order with both now makes them related
  contacts of each other, through core's `PersonService::linkToOrganization()`. An existing link is
  not duplicated. Core makes this call in its Livewire components rather than its services, so
  the panel's pages make it themselves.
- **Purchase-order line edits are saved.** Core 2.5.0's `PurchaseOrderService::update()` reads the
  `products` rows the form submits. The panel's `purchaseOrderLines` shim, which core never read in
  a usable shape, is removed.
- **Converting a quote to an order threw.** The labels copy plucked `crm_labelables.id`, a column
  the pivot table does not have, so the conversion failed before the order was created. It now
  plucks the label id.
- **The product edit form loads the price that saving writes to.** It now uses
  `Product::getPrimaryPrice()`, as core 2.5.0 does. For a product priced only in a currency other
  than the install's, the form used to load blank and saving overwrote that price.

### Removed

- `ProductAttributeResource` and its pages, following core 2.5.0. `ProductVariationsRelationManager`,
  which was dead code built on `ProductAttribute`, is now the read-only Variations list on the
  product view page.

## [1.3.0] - 2026-10-05

Catches up to `venturedrake/laravel-crm` 2.4.7, and stops pinning a single core patch release.

### Upgrading

- **Requires `venturedrake/laravel-crm` `~2.4.2`** (was `^2.4.2`). The panel works on every core
  release from 2.4.2 through 2.4.7 and picks up later 2.4 patches without a panel release. The
  floor stays at 2.4.2: `PdfContactDetails`, `PortalLink` and `Modules` landed in 2.4.1, and
  `PortalTeam::forDocument()` and `SettingService::forTeam()` in 2.4.2. The new cap is 2.5, so a
  core minor release waits until the panel has been checked against it.

- **If you use Xero, require `dcblogdev/laravel-xero` yourself, in the same deploy as core 2.4.5 or
  later.** Core moved it from `require` to `suggest` so apps can use Guzzle 8, so updating core
  removes it unless your own `composer.json` asks for it:

  ```bash
  composer require dcblogdev/laravel-xero:^1.1.3
  ```

  Without it, nothing errors and no job fails. The Integrations page shows that command instead
  of "Connect Xero", and core stops syncing. Tokens and `crm_xero_*` rows are kept, so installing
  the package restores the connection without re-authorising. The package caps Guzzle at `^7`.

### Changed

- **The Integrations page knows when the Xero package is missing.** It used to fall through to
  "Connect Xero", which on core 2.4.5+ bounces to core's own settings screen. It now shows an
  install notice with the command to copy. The panel gates on a new
  `Support\XeroIntegration`, which probes the package's service provider the way core does. Core's
  own `XeroIntegration` class only exists from 2.4.5. Connect and Disconnect also hide on a headless
  install (`LARAVEL_CRM_USER_INTERFACE=false`), where core never registers their routes and the
  page would otherwise throw `RouteNotFoundException`.
- **The Owner filter on the deal, lead, quote and feature boards and the calendar searches on the
  server.** It rendered one `<option>` per user on every round trip, and a board re-renders on every
  card drag. It now lists up to 20 owners, keeps the selected owner in the list, and shows a search
  box once a team has more users than that. Core made the same change in 2.4.4. The bulk **Assign
  owner** action searches on the server too, instead of shipping every user to the browser.
- **The index pages stop querying per row**, porting core 2.4.3:
  - People: email and phone come from eager-loaded collections, not `getPrimaryEmail()` /
    `getPrimaryPhone()`, which query per row on core before 2.4.3.
  - People and Organizations: "Next activity" is one `withMin()` subselect on the list query
    instead of a `tasks()` query per row. Core dropped its own column, which read an attribute that
    never existed; the panel's column has always worked, so it stays.
  - Organizations: the Xero contact link is eager loaded when the Xero module is on.
  - Products: the default price reads the eager-loaded `productPrices`. On core before 2.4.3,
    `getDefaultPrice()` re-queried both the currency setting and the prices, twice per row.
  - Deliveries: the shipping address reads eager-loaded addresses instead of up to four queries per
    row.
  - Purchase orders: Edit and Delete check an eager-loaded Xero link instead of two `exists()`
    queries per row.
  - The activity feed and the activity timeline load each row's causer and recordable with the
    rows.

### Fixed

- **Contact and organization pickers found nothing with `encrypt_db_fields` on.** The names are
  stored as ciphertext, so Filament's SQL `LIKE` never matched, the list was sorted by ciphertext,
  and the organization picker labelled its options with the ciphertext itself. This affected the
  lead, deal, quote, order, invoice and purchase-order forms. The pickers, and the title search on
  the lead, deal and quote tables, now match decrypted names in PHP, as core's autocompletes have
  since 2.4.6. With encryption off, they still search in SQL. The Related organizations picker,
  the order → purchase order supplier pickers, and the guest lists on calls, meetings and lunches
  sort by decrypted name too.
- **Clearing the contact or organization on a quote, order, invoice or purchase order did not
  unlink it.** The edit pages read an empty select as "not submitted" and kept the record's current
  link. They now unlink, as the lead and deal pages already did. A field the form does not submit
  at all still keeps the link.

## [1.2.0] - 2026-09-09

### Upgrading

- **Requires `venturedrake/laravel-crm` `^2.4.2`.** The floor moved from `^2.4`, so this is
  breaking for anyone pinned to 2.4.0 or 2.4.1 — `PdfContactDetails`, `PortalLink` and `Modules`
  all landed in 2.4.1. Update both packages together:

  ```bash
  composer update venturedrake/laravel-crm venturedrake/laravel-crm-filament
  php artisan laravelcrm:filament-update
  ```

- **Republish core's assets.** Core 2.4.2 renamed its pdf.js worker to work around a server MIME
  default, so `manifest.json` names a different file. Core's own `laravelcrm:upgrade` does this and
  runs from its composer hook; `laravelcrm:filament-update` calls `laravelcrm:update` for you.

- **The kanban boards now enforce permissions, and fail closed.** They previously authorized
  nothing at all (see Security below). A board is now hidden unless the user passes the resource's
  `canViewAny()`, and every drag or card-button write re-checks `canEdit()` on the record. **On an
  install whose CRM permissions were never seeded this reads as "denied", not "allowed"** — the
  boards will disappear for everyone until you re-seed. `laravelcrm:filament-update` does that via
  `laravelcrm:update`. This is the opposite of how the plugin's settings pages degrade, and
  deliberately so: a board that renders read-only until a command runs is recoverable, and one that
  silently accepts writes from anyone is not.

- **Settings → General is now tabbed.** Nothing about how it saves changed and no setting moved out
  of the page, but the seven ID prefixes and the per-entity terms now live on their entity's tab
  rather than in one flat column. `?tab=invoices` deep-links; an unrecognised tab falls back to
  General, so an old bookmark still opens the page.

### Security

- **The kanban boards accepted writes from any panel user.** Every board reaches the server through a
  public Livewire method invoked straight from the browser — `$wire.moveLead(id, stageId)` out of the
  drag handler, `wire:click="convertToDeal('…')"` off a card button — and none of them authorized
  anything. `Page::canAccess()` defaults to `true`, so the boards were reachable by any authenticated
  panel user, and once mounted a caller could restage any lead, deal or quote by `external_id`, close
  a deal won or lost, accept or reject a quote, complete a task, finish a call, restatus a feature,
  or convert a lead into a deal — none of which they needed a permission for.

  All six boards now gate access on the resource's own `canViewAny()`, and every mutation
  re-authorizes the resolved record against `canEdit()` before writing (`convertToDeal` additionally
  checks `DealResource::canCreate()`, since it creates a Deal). Fail-closed on an install whose
  permissions were never seeded, matching `GuardsPoliciedResource`. Core closed a narrower version of
  this at 2.4.1, where the batch reorder authorized only the first record and then wrote every id it
  had been handed.

### Added

- **A PDF preview slide-over on quotes, orders, deliveries, invoices and purchase orders**, beside
  the download button on both the index rows and the show pages — core 2.4.1 added this to the CRM's
  own UI and the panel had no equivalent, so checking a document before sending it meant a round
  trip through the browser's download tray. The eye icon on those rows is Filament's `ViewAction`
  and goes to the record page; the new button uses `document-magnifying-glass`, the same way core
  resolved the same collision.

  It renders the same bytes the download produces, through a new panel route
  (`documents/{type}/{record}/preview`) registered inside the panel's auth middleware and served
  `inline`. Being a panel user is not sufficient on its own: `DocumentPreviewController` re-checks
  the record against the `view` policy, so preview grants exactly what download grants. The doc
  type arrives as a route parameter and is resolved through a whitelist rather than being treated
  as a class name.

  Unlike core this uses the browser's own PDF viewer in an `<iframe>` rather than pdf.js. The
  plugin ships no Vite bundle, so importing a viewer would mean taking on the worker-MIME class of
  failure core spent 2.4.1 fixing, to gain nothing a native viewer does not already do.

- **A "Get link" button on quotes, invoices and purchase orders**, beside Preview and Download on
  both the index rows and the show pages. It hands over the same 14-day signed portal URL the
  customer receives by email — previously the only way to obtain that link was to send the document.
  It is minted through `PortalUrl::signedFor()`, the same call the send paths make, so the copied
  link and the emailed link cannot differ in signature or lifetime. Read-only and copyable, because
  a signed URL is unusable if a hand-selection clips the signature.

- **The settings logo can be removed.** There had been no way to delete one, only to replace it —
  and clearing the upload field cannot mean "delete", because Filament silently blanks a path it
  cannot see on the disk, which is why `saveLogo()` treats a blank state as a no-op. Removal is now
  an explicit button on the field, gated on `edit crm settings`. A *pending* upload discards back to
  the saved logo rather than destroying artwork that is still printing on every PDF. The image file
  is left in storage: it may be one core wrote, or shared with another install pointed at the same
  disk, and unlinking bytes on the strength of a settings row is not recoverable.

  Telling those two cases apart takes two questions asked of two different things, because the
  uploader's state has three shapes and none of them is simply the path. `hydrateFiles()` rewrites
  the string this page seeds at mount into a uuid-keyed map — the shape the mounted component and
  every Livewire round-trip carry — dehydration on save flattens it back to a string, and a file the
  user has only just picked sits there as a `TemporaryUploadedFile` with no stored path at all.
  So "is anything in the uploader?" is asked of the raw state, and "is it the saved logo?" only of a
  resolved path. Comparing raw state against `logo_file` compares an array with a string and is true
  however identical the paths inside, which made every removal take the discard branch: the button
  reported "Upload discarded" and left the logo in place, which given no other way to remove one
  meant the feature did nothing at all. Resolving both questions to a path is the opposite failure —
  a pending upload reads as an empty field and the saved logo gets deleted instead of kept.

- **A "Document contact details" field on Settings → General.** Core 2.4.1 introduced a shared
  `pdf_contact_details` setting that fills the "From" block on quote, order, delivery and invoice
  PDFs; until now there was no screen in the panel that could write it. The existing "Invoice
  contact details" field still overrides it for invoices only, and both fields explain the chain
  in their helper text.

### Fixed

- **Quote, order and delivery PDFs printed an empty "From" block.** `CrmPdf::viewData()` carried
  its own two-entry map — invoice and purchase order — so the other three doc types resolved the
  block as null on `modern` (the default template), `bold`, `compact` and `professional`.
  Resolution now goes through core's `Support\PdfContactDetails`, which reads
  `{doc_type}_contact_details`, then the shared `pdf_contact_details`, then null.

- **Settings → Templates previewed every document with the invoice's contact block.**
  `PdfTemplatePreview::sampleData()` hardcoded `invoice_contact_details` regardless of doc type,
  so the screen an admin picks a template on showed the wrong block for quotes, orders,
  deliveries and purchase orders. This is the same bug core fixed in
  `TemplatePreviewController::sampleData()` at 2.4.1.

- **"Preview portal" opened a 401 instead of the document.** The invoice, quote and purchase-order
  preview buttons minted an *unsigned* portal URL, and every portal controller in core opens with
  `abort_unless($request->hasValidSignature(), 401)`. They now sign the link, with the expiry taken
  from core's `Support\PortalLink::DAYS` so a link copied out of the panel and a link the CRM
  emailed cannot outlive each other. The four send paths that each hand-rolled a 14-day
  `URL::temporarySignedRoute()` call now read that same constant.

- **A quote or invoice sent from a portal-less install mailed an empty link.** `PortalUrl` returns
  null where the hand-rolled `URL::temporarySignedRoute()` used to throw, and base's `SendQuote` /
  `SendInvoice` expand `[Online … Link]` unconditionally — so on a host with
  `laravel-crm.user_interface=false`, which unloads `portal-routes.php` and is what this plugin's
  installer recommends, the recipient got "please find it here:" followed by `<a href=""></a>`
  while the panel reported success. The new `Support\PortalMessage` only offers the placeholder
  when the route resolves and strips its line when no link does, which is the guard
  `PurchaseOrderPortalLink` already applied to purchase orders; that class now shares the stripping
  rule rather than carrying its own copy.

- **An empty `laravel-crm.modules` array disabled every panel module.** Core reads a falsy value
  for that key — including `[]`, because `! []` is true — as "unconfigured, so everything is on".
  The plugin restated the rule as a bare `in_array()` in six places, which reads `[]` as
  "everything off", so a host that published the config and emptied that key got a full core CRM
  beside an empty Filament panel. All six now call core's `Support\Modules::enabled()`. Note that
  `xero` is a plugin-only slug that is not in core's default `modules` list, so it stays off by
  default under either rule; the behaviour only changes for a host that emptied the array, where
  it now turns on along with everything else.

### Changed

- **Settings → General is split across tabs** rather than one flat column of ~28 unrelated controls,
  matching core 2.4.1. **General** keeps everything belonging to no single entity — identity,
  localisation, tax defaults, the shared contact-details block, the two account-wide toggles and the
  phone / email / address panels — and there is one tab per entity in the order records flow through
  the CRM (**Leads, Deals, Quotes, Orders, Invoices, Deliveries, Purchase orders**), each holding
  that entity's ID prefix beside its own terms. Tabs are query-string-synced, so
  `/admin/general?tab=invoices` deep-links, and a tab is hidden when its module is off.

  It is still one schema over one state path and one atomic save covering every tab, whichever one
  is showing. No public property or method was renamed or removed, and `save()` does not branch on
  the visible tab.

  `save()` does now skip a key that the schema did not render, rather than defaulting it to null.
  That distinction only exists because of the gating above: a tab whose module is off is not built
  at all, so its fields contribute nothing to `getState()`, and writing null for them would blank
  the prefix and terms rows core's own CRM still reads — an admin who had turned off
  purchase-orders would have lost `purchase_order_prefix`, `purchase_order_terms` and
  `purchase_order_delivery_instructions` the next time they saved anything on the General tab, with
  no screen left in the panel that could restore them. A field that *was* rendered and cleared
  still comes back present-and-null, so a deliberate clear persists as before.

- **`laravelcrm:filament-upgrade` warns about drifted published views.** It md5-compares every blade
  published under `resources/views/vendor/laravel-crm-filament` against the copy this release ships,
  reporting both the views that have drifted and the ones the package no longer ships. A published
  view is a frozen copy the view finder prefers, so one left at an older contract keeps rendering
  after the component behind it stopped passing a variable — and the page 500s with nothing in the
  upgrade path having said so. Warn-only, and never fails the composer run it fires from.

- **Document PDFs pin the settings service to the document's own team** while rendering. Core 2.4.2
  fixed this on the portal, where it was a cross-tenant leak: the settings read is `pluck()`ed by
  name, so with the team scope disengaged whichever team's row sorted last supplied the organisation
  name, ABN, contact block and logo for every tenant's documents alike. The panel builds the same
  PDF from the same service. Only reachable on a host running `--allow-teams`, since panel
  multi-tenancy is otherwise refused.

  Only a document that names a team is pinned. Pinning a *null* team is not "read the sensible
  default": `forTeam()` sets an override flag that is meaningful precisely because null is a value,
  so the query drops the team scope and filters to `global = 1` rows — or to `1 = 0`, because the
  settings table is never listed in `laravel-crm.model_with_global` (the config names `settings`
  while the check compares `Setting::getTable()`, which carries the `crm_` prefix). A document
  predating teams, or created outside one, therefore resolved *no settings at all* and printed with
  no organisation name, no contact block, no logo, and a tax label falling back to the literal
  "Tax". Leaving it unpinned is also the safe answer and not just the populated one: the leak core
  closed is a portal leak, and the portal is anonymous, so the scope disengages there. In the panel
  the reader is a signed-in user with a current team, so an unpinned read resolves to their own
  team — the same answer every other settings read on the page gives them, never another tenant's.

- **Requires `venturedrake/laravel-crm` `^2.4.2`** (was `^2.4`). The floor has to move because
  `PdfContactDetails`, `PortalLink` and `Modules` all landed in 2.4.1. Core 2.4.2 expects its
  published assets to be refreshed — core's own `laravelcrm:upgrade` composer hook does this, and
  `laravelcrm:filament-update` runs `laravelcrm:update` for you.

- **Settings → Templates now shows thumbnails.** No change here: core 2.4.0 shipped with none
  (Vite's `emptyOutDir` deleted them, so `PdfTemplateRegistry::thumbnailFile()` returned null and
  the picker was text-only), and 2.4.1 moved the artwork somewhere the build no longer wipes. The
  panel already read it through the registry.

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

[1.4.0]: https://github.com/venturedrake/laravel-crm-filament/releases/tag/v1.4.0
[1.3.0]: https://github.com/venturedrake/laravel-crm-filament/releases/tag/v1.3.0
[1.2.0]: https://github.com/venturedrake/laravel-crm-filament/releases/tag/v1.2.0
[1.1.0]: https://github.com/venturedrake/laravel-crm-filament/releases/tag/v1.1.0
[1.0.0]: https://github.com/venturedrake/laravel-crm-filament/releases/tag/v1.0.0
