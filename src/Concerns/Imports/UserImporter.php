<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrmFilament\Jobs\SendUserInvite;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;

class UserImporter extends Importer
{
    public function permission(): ?string
    {
        return 'create crm users';
    }

    public function columns(): array
    {
        return [
            'name' => 'Name',
            'email' => 'Email',
            'role' => 'Role',
        ];
    }

    public function dedupeField(): string
    {
        return 'email';
    }

    public function sampleRow(): array
    {
        return [
            'name' => 'Jane Doe',
            'email' => 'jane.doe@example.com',
            'role' => 'Editor',
        ];
    }

    public function importRow(array $row): bool
    {
        $name = trim((string) ($row['name'] ?? ''));
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $roleName = trim((string) ($row['role'] ?? ''));

        if ($email === '' || $name === '') {
            return false;
        }

        $model = $this->userModel();

        if ($model === null) {
            return false;
        }

        if ($model::where('email', $email)->exists()) {
            return false;
        }

        // Resolve and vet the role BEFORE the user row is written. An
        // unassignable role is dropped rather than failing the row — core's
        // behaviour: the user still gets created, an Owner can set their role
        // afterwards, and a rejected escalation leaves nothing half-created.
        $role = $roleName !== ''
            ? Role::assignableBy()->where('name', $roleName)->first()
            : null;

        try {
            $user = $model::forceCreate([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::password(
                    length: 16,
                    letters: true,
                    numbers: true,
                    symbols: true,
                    spaces: false,
                )),
                'crm_access' => 1,
                'mailing_list' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if ($role) {
            $user->assignRole($role);
        }

        // The plugin's own job, not core's SendImportPasswordReset — core's is
        // bound to App\Models\User and silently mails nothing on a host whose
        // user model lives anywhere else. See SendUserInvite.
        SendUserInvite::dispatch($user->email);

        return true;
    }

    /**
     * The host's configured user model.
     *
     * Resolved through UserResource rather than a hardcoded App\Models\User so
     * an import lands in whatever table the host actually authenticates against.
     *
     * @return class-string<Model>|null
     */
    protected function userModel(): ?string
    {
        $model = UserResource::getModel();

        return is_string($model) && is_a($model, Model::class, true) ? $model : null;
    }
}
