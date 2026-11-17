<?php

declare(strict_types=1);

use Filament\Actions\DeleteAction;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use VentureDrake\LaravelCrm\Models\Field;
use VentureDrake\LaravelCrm\Models\FieldModel;
use VentureDrake\LaravelCrm\Models\FieldOption;
use VentureDrake\LaravelCrm\Models\FieldValue;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Resources\Fields\Pages\EditField;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\CreateOrganization;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\EditOrganization;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0: a custom field's configured default fills the create form, and
 * deleting or unlinking a field goes through Eloquent so core's
 * FieldObserver / FieldModelObserver clean up its links and values.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Custom Field Defaults Tester',
        'email' => 'custom-field-defaults-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');

    // RoleSeeder does not seed the custom field permissions FieldPolicy
    // checks.
    foreach (['view crm fields', 'create crm fields', 'edit crm fields', 'delete crm fields'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->user->givePermissionTo(['view crm fields', 'create crm fields', 'edit crm fields', 'delete crm fields']);

    $this->actingAs($this->user->fresh());
});

function defaultsField(string $name, string $type, ?string $default, array $options = [], array $models = [Organization::class]): Field
{
    $field = Field::create([
        'external_id' => (string) Str::uuid(),
        'name' => $name,
        'key' => Str::slug($name, '_'),
        'type' => $type,
        'default' => $default,
    ]);

    foreach ($options as $order => $value) {
        FieldOption::create(['field_id' => $field->id, 'label' => ucfirst($value), 'value' => $value, 'order' => $order]);
    }

    foreach ($models as $model) {
        FieldModel::create(['field_id' => $field->id, 'model' => $model]);
    }

    return $field->load('fieldOptions');
}

function defaultsOptionId(Field $field, string $value): string
{
    return (string) $field->fieldOptions->firstWhere('value', $value)->id;
}

it('fills the create form with each field type\'s default', function () {
    $text = defaultsField('Region', 'text', 'APAC');
    $checkbox = defaultsField('Newsletter', 'checkbox', '1');
    $select = defaultsField('Tier', 'select', 'gold', ['silver', 'gold']);
    $radio = defaultsField('Size', 'radio', 'large', ['small', 'large']);
    $multiple = defaultsField('Channels', 'checkbox_multiple', json_encode(['email', 'phone']), ['email', 'phone', 'post']);
    $empty = defaultsField('Notes', 'textarea', null);

    livewire(CreateOrganization::class)
        ->assertSchemaStateSet([
            "custom_fields.{$text->id}" => 'APAC',
            "custom_fields.{$checkbox->id}" => true,
            // Defaults are stored as the option's value; the form is keyed by
            // option id, as core's customFieldFormValue() maps them.
            "custom_fields.{$select->id}" => defaultsOptionId($select, 'gold'),
            "custom_fields.{$radio->id}" => defaultsOptionId($radio, 'large'),
            "custom_fields.{$multiple->id}" => [defaultsOptionId($multiple, 'email'), defaultsOptionId($multiple, 'phone')],
            "custom_fields.{$empty->id}" => null,
        ]);
});

it('keeps the stored value on edit rather than the default', function () {
    $text = defaultsField('Region', 'text', 'APAC');
    $organization = Organization::create(['name' => 'Acme']);
    $organization->fields()->where('field_id', $text->id)->update(['value' => 'EMEA']);

    livewire(EditOrganization::class, ['record' => $organization->external_id])
        ->assertSchemaStateSet(["custom_fields.{$text->id}" => 'EMEA']);
});

it('deleting a field from its edit page unlinks it and removes its values through the observers', function () {
    $field = defaultsField('Region', 'text', null, models: [Organization::class, Person::class]);
    Organization::create(['name' => 'Acme']);
    Person::create(['first_name' => 'Ann', 'last_name' => 'Test']);

    // Core's HasCrmFields gave both records a value row when they were
    // created.
    expect(FieldValue::where('field_id', $field->id)->count())->toBe(2);

    livewire(EditField::class, ['record' => $field->external_id])
        ->callAction(DeleteAction::getDefaultName());

    expect(Field::find($field->id))->toBeNull()
        ->and(FieldModel::where('field_id', $field->id)->count())->toBe(0)
        ->and(FieldValue::where('field_id', $field->id)->count())->toBe(0);
});

it('unlinking a model on the edit page removes the field\'s values from that model only', function () {
    $field = defaultsField('Region', 'text', null, models: [Organization::class, Person::class]);
    Organization::create(['name' => 'Acme']);
    Person::create(['first_name' => 'Ann', 'last_name' => 'Test']);

    // Core's HasCrmFields gave both records a value row when they were
    // created.
    expect(FieldValue::where('field_id', $field->id)->count())->toBe(2);

    livewire(EditField::class, ['record' => $field->external_id])
        ->fillForm(['models' => [Person::class]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(FieldModel::where('field_id', $field->id)->pluck('model')->all())->toBe([Person::class])
        ->and(FieldValue::where('field_id', $field->id)->pluck('field_valueable_type')->all())->toBe([Person::class]);
});
