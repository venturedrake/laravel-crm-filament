<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorizes the kanban boards' drag-and-drop and card-button mutations.
 *
 * Every board reaches the server the same way: a public Livewire method invoked
 * straight from the browser — `$wire.moveLead(id, stageId)` out of the SortableJS
 * `onEnd` handler, `wire:click="convertToDeal('…')"` off a card button. Livewire
 * dispatches those by name from the request payload, so they are a public write
 * endpoint, not an internal detail of the blade that happens to call them.
 *
 * They carried no authorization at all. `Page::canAccess()` defaults to `true`
 * (the same Filament default {@see AuthorizesCrmSettingsPage} exists to close on
 * the settings pages), so the boards were reachable by any authenticated panel
 * user, and once mounted every mutation on them ran unchecked — a user with no
 * CRM permissions could restage any lead, deal or quote by external_id, mark a
 * deal won or a quote accepted, or convert a lead into a deal.
 *
 * Core closed a narrower version of this at 2.4.1, where `onStageSorted`
 * authorized only the first resolvable record in the batch and then wrote every
 * id it had been handed.
 *
 * Two levels, because they are two different questions:
 *
 *  - `canAccess()` asks whether the board may be *seen*, and defers to the
 *    resource's own `canViewAny()` so the board and the list it toggles with
 *    agree.
 *  - `authorizeKanbanEdit()` / `authorizeKanbanCreate()` ask whether *this*
 *    record may be written, per call, after it has been resolved. Seeing a
 *    board is not permission to rewrite what is on it, and the record id
 *    arrives from the browser.
 *
 * Fail-closed, like {@see GuardsPoliciedResource} and unlike
 * {@see ChecksCrmPermissions}: an install whose permissions were never seeded
 * denies the write rather than allowing it. A board that renders read-only until
 * `laravelcrm:update` runs is recoverable; a board that silently accepts writes
 * from anyone is not.
 *
 * @phpstan-require-extends Page
 */
trait AuthorizesKanbanBoard
{
    public static function canAccess(array $parameters = []): bool
    {
        return static::kanbanPolicyCheck(
            fn (): bool => static::getResource()::canViewAny(),
        );
    }

    /**
     * Abort unless the current user may write this record.
     *
     * Takes the resolved model rather than the external id: the policy needs
     * the record, and resolving first means a caller cannot be authorized
     * against one record and then write another.
     */
    protected function authorizeKanbanEdit(Model $record): void
    {
        abort_unless(
            static::kanbanPolicyCheck(fn (): bool => static::getResource()::canEdit($record)),
            Response::HTTP_FORBIDDEN,
        );
    }

    /**
     * Abort unless the current user may create records of `$resource`.
     *
     * Converting a lead to a deal writes a *new* Deal, so edit rights on the
     * lead are not the ability being exercised.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    protected function authorizeKanbanCreate(string $resource): void
    {
        abort_unless(
            static::kanbanPolicyCheck(fn (): bool => $resource::canCreate()),
            Response::HTTP_FORBIDDEN,
        );
    }

    /**
     * Core's policies call `$user->hasPermissionTo(...)` unguarded, which
     * throws on an install that never seeded the permission. Same two
     * exceptions {@see GuardsPoliciedResource} catches, same resolution: treat
     * "the permission does not exist here" as denied.
     */
    protected static function kanbanPolicyCheck(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (PermissionDoesNotExist | QueryException) {
            return false;
        }
    }
}
