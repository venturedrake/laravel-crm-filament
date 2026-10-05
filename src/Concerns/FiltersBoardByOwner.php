<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Illuminate\Support\Collection;

/**
 * The Owner dropdown on the kanban boards and the calendar.
 *
 * It used to render one <option> per user on every Livewire round trip — and
 * a board re-renders on every card drag. Core 2.4.4 measured the same pattern
 * in its own Owner filter at 31.7 MB per page on an ~11,500-user install and
 * replaced it with a server-side search capped at 20 rows
 * (Livewire\Traits\HasUserOwnerFilter, which only exists from 2.4.4, so this
 * mirrors it rather than using it).
 *
 * The selected owner is merged back into the options even when it falls
 * outside the current matches, so the <select> never loses its label.
 */
trait FiltersBoardByOwner
{
    public ?int $ownerFilter = null;

    public string $ownerSearch = '';

    public static int $ownerOptionsLimit = 20;

    /**
     * @return Collection<int|string, string>
     */
    public function getOwners(): Collection
    {
        $userClass = config('auth.providers.users.model');
        $search = trim($this->ownerSearch);

        $owners = $userClass::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%' . $search . '%'))
            ->orderBy('name')
            ->limit(static::$ownerOptionsLimit)
            ->pluck('name', 'id');

        if ($this->ownerFilter !== null && ! $owners->has($this->ownerFilter)) {
            $selected = $userClass::query()->whereKey($this->ownerFilter)->value('name');

            if ($selected !== null) {
                $owners->prepend($selected, $this->ownerFilter);
            }
        }

        return $owners;
    }

    /**
     * Only offer the search box when the list is actually cut short, so a
     * small team keeps the plain dropdown it had.
     */
    public function ownerSearchEnabled(): bool
    {
        if ($this->ownerSearch !== '') {
            return true;
        }

        $userClass = config('auth.providers.users.model');

        return $userClass::query()->count() > static::$ownerOptionsLimit;
    }
}
