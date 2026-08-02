<?php

namespace VentureDrake\LaravelCrmFilament;

use App\User;
use Filament\Actions\EditAction;
use Illuminate\Filesystem\Filesystem;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use VentureDrake\LaravelCrm\Models\Activity;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\Contact;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Models\EmailCampaignClick;
use VentureDrake\LaravelCrm\Models\EmailCampaignRecipient;
use VentureDrake\LaravelCrm\Models\Feature;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Label;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Monitor;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Phone;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\SmsCampaign;
use VentureDrake\LaravelCrm\Models\SmsCampaignClick;
use VentureDrake\LaravelCrm\Models\SmsCampaignRecipient;
use VentureDrake\LaravelCrm\Models\Team;
use VentureDrake\LaravelCrmFilament\Console\InstallCommand;
use VentureDrake\LaravelCrmFilament\Models\Audit;

class LaravelCrmFilamentServiceProvider extends PackageServiceProvider
{
    public static string $name = 'laravel-crm-filament';

    public static string $viewNamespace = 'laravel-crm-filament';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            // The plugin owns the `crm_invoice_payments` table written by the
            // invoice Mark Paid action — core CRM ships no payments table.
            // Registering it here gives hosts a publishable migration; note
            // Spatie derives publish tags from Package::shortName(), which
            // strips the `laravel-` prefix, so the tag is
            // `crm-filament-migrations` (see InstallCommand).
            ->hasMigration('create_laravel_crm_invoice_payments_table')
            ->runsMigrations()
            ->hasCommands($this->getCommands());

        if (file_exists($package->basePath('/../resources/lang'))) {
            $package->hasTranslations();
        }

        if (file_exists($package->basePath('/../resources/views'))) {
            $package->hasViews(static::$viewNamespace);
        }
    }

    public function packageRegistered(): void
    {
        // Spatie's PackageServiceProvider derives the translation namespace
        // from Package::shortName(), which strips the `laravel-` prefix —
        // so $package->hasTranslations() registers the namespace as
        // `crm-filament::*`. Every call site in the plugin uses the
        // fully-qualified `laravel-crm-filament::*` namespace, so register
        // it explicitly here. Without this, every __() call falls back to
        // returning the raw key string verbatim in the host app.
        $this->loadTranslationsFrom(
            __DIR__ . '/../resources/lang',
            'laravel-crm-filament',
        );
    }

    public function packageBooted(): void
    {
        // Apply consistent gray styling to every EditAction across the panel,
        // so Edit pills match the gray Back-to-index pill.
        EditAction::configureUsing(fn (EditAction $action) => $action->color('gray'));

        // Core CRM declares `labels()` on Lead/Deal/Quote/Order/Invoice/PurchaseOrder/Person/Organization/Customer
        // but not on Product or Delivery, even though the same polymorphic
        // `labelables` pivot supports them. Inject the relation via
        // `Model::resolveRelationUsing()` so Filament's
        // `->relationship('labels', 'name')` works on those resources too.
        $prefix = config('laravel-crm.db_table_prefix', 'crm_');
        $morphName = $prefix . 'labelable';

        Product::resolveRelationUsing('labels', function ($model) use ($morphName) {
            return $model->morphToMany(Label::class, $morphName);
        });

        Delivery::resolveRelationUsing('labels', function ($model) use ($morphName) {
            return $model->morphToMany(Label::class, $morphName);
        });

        if (class_exists(Feature::class)) {
            Feature::resolveRelationUsing('labels', function ($model) use ($morphName) {
                return $model->morphToMany(Label::class, $morphName);
            });
        }

        // Core CRM's base Model does not implement OwenIt\Auditing\Auditable
        // even though the audits table ships with the install. Resolve an
        // `audits()` morphMany on every primary model so the
        // AuditsRelationManager can hang off the standard
        // `protected static string $relationship = 'audits'` contract.
        foreach (static::auditableModels() as $auditableModel) {
            if (! class_exists($auditableModel)) {
                continue;
            }
            $auditableModel::resolveRelationUsing('audits', function ($model) {
                return $model->morphMany(Audit::class, 'auditable');
            });
        }

        // Core CRM declares `activities()` on each primary model via
        // HasCrmActivities (a morphMany on `timelineable_*`). Filament's
        // RelationManager contract uses `protected static string $relationship`,
        // so we register a parallel `timelineActivities` relation under an
        // unambiguous name the Crm activities RelationManager can bind to.
        // Mirrors the audits/labels precedent above; extended to every primary
        // model so the Crm* RM family attaches uniformly.
        foreach (static::timelineActivityModels() as $timelineModel) {
            if (! class_exists($timelineModel)) {
                continue;
            }
            $timelineModel::resolveRelationUsing('timelineActivities', function ($model) {
                return $model->morphMany(Activity::class, 'timelineable');
            });
        }

        // Core CRM's Team model only declares `userCreated()` for the
        // `user_id` column. Filament's parity table column shape names an
        // `ownerUser` relation (analogous to every other entity in the
        // family) for surfacing an explicit team owner — but core's
        // crm_teams table doesn't ship a `user_owner_id` column yet.
        // Register the relation so the column renders cleanly with its
        // `Unallocated` placeholder until upstream lands the column.
        Team::resolveRelationUsing('ownerUser', function ($model) {
            return $model->belongsTo(User::class, 'user_owner_id');
        });

        // Core CRM's Person/Organization expose a `contacts()` morphMany that
        // mixes related-people and related-organizations rows. The Filament
        // RelationManager contract uses a single `$relationship` per RM, so
        // register two filtered variants (`relatedPeopleContacts` /
        // `relatedOrganizationContacts`) that scope the morphMany by the
        // related entity's morph type. Mirrors the v0.x labels/audits
        // polyfill pattern.
        Person::resolveRelationUsing('relatedPeopleContacts', function ($model) {
            return $model->morphMany(Contact::class, 'contactable')
                ->where('entityable_type', 'like', '%Person%');
        });

        Person::resolveRelationUsing('relatedOrganizationContacts', function ($model) {
            return $model->morphMany(Contact::class, 'contactable')
                ->where('entityable_type', 'like', '%Organization%');
        });

        Organization::resolveRelationUsing('relatedPeopleContacts', function ($model) {
            return $model->morphMany(Contact::class, 'contactable')
                ->where('entityable_type', 'like', '%Person%');
        });

        Organization::resolveRelationUsing('relatedOrganizationContacts', function ($model) {
            return $model->morphMany(Contact::class, 'contactable')
                ->where('entityable_type', 'like', '%Organization%');
        });

        // Polyfill phones / addresses / crmTeams on the host's configured
        // User model so the Filament User form can hydrate them as
        // polymorphic morphMany relations the same way Person/Organization
        // do. Skips registration when the User model has already declared
        // them (e.g. when the host uses HasCrmTeams trait).
        $userModelClass = config('auth.providers.users.model');
        if (is_string($userModelClass) && class_exists($userModelClass)) {
            $userInstance = new $userModelClass;

            if (! method_exists($userInstance, 'phones')) {
                $userModelClass::resolveRelationUsing('phones', function ($model) {
                    return $model->morphMany(Phone::class, 'phoneable');
                });
            }

            if (! method_exists($userInstance, 'addresses')) {
                $userModelClass::resolveRelationUsing('addresses', function ($model) {
                    return $model->morphMany(Address::class, 'addressable');
                });
            }

            if (! method_exists($userInstance, 'crmTeams')) {
                $userModelClass::resolveRelationUsing('crmTeams', function ($model) {
                    return $model->belongsToMany(Team::class, 'crm_team_user', 'user_id', 'crm_team_id');
                });
            }
        }

        // Core CRM declares `clicks()` on EmailCampaignRecipient / SmsCampaignRecipient
        // but not on the campaign model itself. The ClicksRelationManager binds
        // to the campaign, so we resolve a hasManyThrough that walks
        // campaign -> recipient -> click. That gives a single `clicks` relation
        // we can hang the RelationManager + TopUrls widget off.
        EmailCampaign::resolveRelationUsing('clicks', function ($model) {
            return $model->hasManyThrough(
                EmailCampaignClick::class,
                EmailCampaignRecipient::class,
                'email_campaign_id',
                'email_campaign_recipient_id',
                'id',
                'id',
            );
        });

        SmsCampaign::resolveRelationUsing('clicks', function ($model) {
            return $model->hasManyThrough(
                SmsCampaignClick::class,
                SmsCampaignRecipient::class,
                'sms_campaign_id',
                'sms_campaign_recipient_id',
                'id',
                'id',
            );
        });

        // Publish PanelProvider stub used by the install command.
        if ($this->app->runningInConsole()) {
            $files = new Filesystem;

            if ($files->isDirectory(__DIR__ . '/../stubs')) {
                foreach ($files->files(__DIR__ . '/../stubs') as $file) {
                    $this->publishes([
                        $file->getRealPath() => base_path("stubs/laravel-crm-filament/{$file->getFilename()}"),
                    ], 'laravel-crm-filament-stubs');
                }
            }
        }
    }

    /**
     * @return array<class-string>
     */
    public static function auditableModels(): array
    {
        return [
            Lead::class,
            Deal::class,
            Quote::class,
            Order::class,
            Invoice::class,
            Delivery::class,
            PurchaseOrder::class,
            Person::class,
            Organization::class,
            Product::class,
            Feature::class,
            Monitor::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    public static function timelineActivityModels(): array
    {
        return [
            Lead::class,
            Deal::class,
            Quote::class,
            Order::class,
            Invoice::class,
            PurchaseOrder::class,
            Delivery::class,
            Person::class,
            Organization::class,
            Feature::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    protected function getCommands(): array
    {
        return [
            InstallCommand::class,
        ];
    }
}
