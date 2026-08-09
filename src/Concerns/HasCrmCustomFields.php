<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use VentureDrake\LaravelCrm\Models\Field;
use VentureDrake\LaravelCrm\Models\FieldModel;
use VentureDrake\LaravelCrm\Models\FieldValue;

/**
 * Drop-in support for `venturedrake/laravel-crm` Custom Fields on a Filament Resource.
 *
 * - `crmCustomFieldsSection($modelClass)` returns a `Section` of Filament inputs
 *   keyed by `custom_fields.{field_id}`, suitable for splicing into form()->components().
 * - `loadCrmCustomFieldsInto($data, $record)` hydrates `custom_fields` from the
 *   record's polymorphic FieldValue rows.
 * - `saveCrmCustomFields($data, $record)` upserts FieldValue rows after the parent
 *   create/update has run, mirroring HasCustomFormFields::saveCustomFields().
 */
trait HasCrmCustomFields
{
    public static function crmCustomFieldsSection(string $modelClass): ?Section
    {
        $fields = FieldModel::query()
            ->where('model', $modelClass)
            ->with(['field.fieldOptions'])
            ->get()
            ->pluck('field')
            ->filter()
            ->values();

        if ($fields->isEmpty()) {
            return null;
        }

        $components = $fields
            ->map(fn (Field $field) => static::buildCrmFieldComponent($field))
            ->all();

        return Section::make('Custom fields')->heading(__('laravel-crm-filament::labels.sections.custom_fields'))
            ->schema($components)
            ->collapsible()
            ->columnSpanFull();
    }

    protected static function buildCrmFieldComponent(Field $field): Component
    {
        $key = "custom_fields.{$field->id}";
        $label = $field->name;
        $required = (bool) $field->required;
        $options = $field->fieldOptions
            ->mapWithKeys(fn ($option) => [
                $option->id => $option->label ?: $option->value,
            ])
            ->all();

        return match ($field->type) {
            'textarea' => Textarea::make($key)->label($label)->required($required)->rows(3),
            'date' => DatePicker::make($key)->label($label)->required($required),
            'checkbox' => Checkbox::make($key)->label($label),
            'select' => Select::make($key)->label($label)->options($options)->searchable()->required($required),
            'select_multiple' => Select::make($key)->label($label)->multiple()->options($options)->searchable()->required($required),
            'radio' => Radio::make($key)->label($label)->options($options)->required($required),
            'checkbox_multiple' => CheckboxList::make($key)->label($label)->options($options)->required($required),
            default => TextInput::make($key)->label($label)->required($required)->maxLength(255),
        };
    }

    public static function loadCrmCustomFieldsInto(array $data, $record): array
    {
        if (! $record || ! method_exists($record, 'fields')) {
            return $data;
        }

        $data['custom_fields'] = [];

        foreach ($record->fields()->with('field.fieldOptions')->get() as $fv) {
            $value = $fv->value;
            $field = $fv->field;
            $type = $field?->type;

            if (in_array($type, ['checkbox_multiple', 'select_multiple'], true) && $value !== null) {
                $value = json_decode($value, true) ?: [];
                // Legacy rows hold option value strings; the form is keyed by
                // option id. See crmCustomFieldOptionId().
                $value = array_values(array_filter(array_map(
                    fn ($item) => static::crmCustomFieldOptionId($field, $item),
                    (array) $value,
                ), fn ($item) => $item !== null));
            } elseif ($type === 'checkbox') {
                $value = (bool) $value;
            } elseif (in_array($type, ['select', 'radio'], true)) {
                $value = static::crmCustomFieldOptionId($field, $value);
            }

            $data['custom_fields'][$fv->field_id] = $value;
        }

        return $data;
    }

    public static function saveCrmCustomFields(array $data, $record): void
    {
        $values = $data['custom_fields'] ?? null;
        if (! is_array($values) || ! $record || ! method_exists($record, 'fields')) {
            return;
        }

        foreach ($values as $fieldId => $value) {
            if (is_array($value)) {
                $value = json_encode(array_values(array_filter($value, fn ($v) => $v !== null && $v !== '')));
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            FieldValue::updateOrCreate(
                [
                    'field_id' => $fieldId,
                    'field_valueable_type' => get_class($record),
                    'field_valueable_id' => $record->id,
                ],
                ['value' => $value],
            );
        }
    }

    /**
     * Map a stored custom-field value onto the FieldOption id the form expects.
     *
     * Ported from core's HasCustomFormFields::customFieldOptionId(). As of
     * 2.4.0 option-backed fields are keyed by the option's id, but rows written
     * before that hold the option's `value` string instead. Hydrating those
     * unmapped matches no Select option, so the field renders blank — and then
     * saves blank, quietly destroying the value.
     *
     * @param  mixed  $value
     */
    protected static function crmCustomFieldOptionId($field, $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $options = $field?->fieldOptions;

        if ($options === null) {
            return (string) $value;
        }

        if ($options->contains(fn ($option) => (string) $option->id === (string) $value)) {
            return (string) $value;
        }

        if ($option = $options->firstWhere('value', $value)) {
            return (string) $option->id;
        }

        return (string) $value;
    }
}
