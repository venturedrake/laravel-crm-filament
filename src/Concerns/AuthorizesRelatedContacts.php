<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Authorizes the Related people / Related organizations relation managers
 * against the policy registered for their related model.
 *
 * Both managers bind to a filtered morphMany registered by
 * LaravelCrmFilamentServiceProvider::packageBooted()
 * (`relatedPeopleContacts` / `relatedOrganizationContacts`), each of which
 * resolves to VentureDrake\LaravelCrm\Models\Contact. Core CRM binds that
 * model to ContactPolicy, so deferring to the Gate gives the standard
 * `create crm contacts` / `delete crm contacts` checks for free — the same
 * policy Filament already consults for `viewAny` in
 * RelationManager::canViewForRecord().
 *
 * Filament v4 actions carry NO implicit policy authorization
 * (Actions\Concerns\CanBeAuthorized::$authorization defaults to null, i.e.
 * allowed), so every action has to opt in explicitly.
 *
 * @phpstan-require-extends RelationManager
 */
trait AuthorizesRelatedContacts
{
    /**
     * Resolve the model class actually backing this manager's relationship.
     *
     * Deliberately resolved off the owner record rather than hardcoded, so the
     * authorization follows the registered relation: if the morphMany polyfill
     * ever changes shape the policy lookup changes with it instead of silently
     * checking the wrong model. Mirrors how Filament resolves the model in
     * RelationManager::canViewForRecord().
     *
     * @return class-string<Model>
     */
    public function getRelatedContactModel(): string
    {
        return $this->getOwnerRecord()
            ->{static::getRelationshipName()}()
            ->getQuery()
            ->getModel()::class;
    }

    /**
     * Defer an action's authorization to the related model's policy.
     *
     * Row actions pass the record so the policy sees the concrete Contact;
     * header actions pass none, so the model class is used instead.
     */
    protected function authorizeRelatedContact(string $ability, ?Model $record = null): bool
    {
        return Gate::allows($ability, $record ?? $this->getRelatedContactModel());
    }
}
