<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Fields\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Field;
use VentureDrake\LaravelCrm\Models\FieldModel;
use VentureDrake\LaravelCrm\Models\FieldOption;
use VentureDrake\LaravelCrmFilament\Resources\Fields\FieldResource;

class EditField extends EditRecord
{
    protected static string $resource = FieldResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Field $field */
        $field = $this->record;

        $data['models'] = $field->fieldModels()->pluck('model')->all();
        $data['options'] = $field->fieldOptions()
            ->orderBy('order')
            ->get()
            ->map(fn ($o) => [
                'label' => $o->label,
                'value' => $o->value,
                'order' => $o->order,
            ])
            ->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $models = $data['models'] ?? [];
        $options = $data['options'] ?? [];
        unset($data['models'], $data['options']);

        /** @var Field $record */
        $record->update($data);

        // Sync FieldModels: remove unwanted, add new
        $existingModels = $record->fieldModels()->pluck('model')->all();
        foreach (array_diff($existingModels, $models) as $stale) {
            // One model at a time, not a query-builder delete, so core's
            // FieldModelObserver runs and removes the field's values from that
            // model too (core 2.7.0 detaches links the same way).
            $record->fieldModels()->where('model', $stale)->get()->each->delete();
        }
        foreach (array_diff($models, $existingModels) as $fresh) {
            FieldModel::create(['field_id' => $record->id, 'model' => $fresh]);
        }

        // Replace FieldOptions wholesale (Filament Repeater is array-of-arrays without IDs).
        $record->fieldOptions()->delete();
        foreach ($options as $option) {
            FieldOption::create([
                'field_id' => $record->id,
                'label' => $option['label'] ?? null,
                'value' => $option['value'] ?? null,
                'order' => $option['order'] ?? 0,
            ]);
        }

        return $record->refresh();
    }
}
