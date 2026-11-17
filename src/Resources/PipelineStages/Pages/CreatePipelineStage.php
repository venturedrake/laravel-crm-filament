<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PipelineStages\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Services\PipelineStageService;
use VentureDrake\LaravelCrmFilament\Concerns\SurfacesServiceValidation;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStages\PipelineStageResource;

class CreatePipelineStage extends CreateRecord
{
    use SurfacesServiceValidation;

    protected static string $resource = PipelineStageResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /**
     * Through core 2.7.0's PipelineStageService, which appends the stage
     * after the pipeline's last (refusing past position 127) and rejects a
     * pipeline that no longer exists.
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var PipelineStage $stage */
        $stage = $this->surfacingServiceValidation(
            fn () => app(PipelineStageService::class)->create($data),
        );

        return self::saveExtraStageFields($stage, $data);
    }

    /**
     * The service writes name, description, pipeline and order only. The
     * panel's form also has these.
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveExtraStageFields(PipelineStage $stage, array $data): PipelineStage
    {
        $extra = array_intersect_key($data, array_flip(['color', 'pipeline_stage_probability_id']));

        if ($extra !== []) {
            $stage->forceFill($extra)->save();
        }

        return $stage;
    }
}
