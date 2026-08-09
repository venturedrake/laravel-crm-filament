<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use VentureDrake\LaravelCrmFilament\Concerns\Imports\Importer;

/**
 * Adds an inline CSV import header action — analogous to ExportsCsv.
 *
 * Resources opt in by passing an Importer subclass to ImportsCsv::action(),
 * which returns a Filament header Action that opens a modal with:
 *   - file upload (CSV)
 *   - header-row toggle
 *   - column-mapping selects (destination => CSV header)
 *   - duplicate-detection field select
 *   - chunk size
 *   - a "Download sample CSV" footer action serving a UTF-8-BOM template
 *
 * Submission reads the uploaded CSV via fgetcsv() and processes rows in
 * batches of chunk_size, calling Importer::importRow() per row. Rows are
 * deduped (lowercased + trimmed dedupe field) both within the batch and
 * via the importer's own per-row guard. Duplicates are skipped silently.
 *
 *   ImportsCsv::action(PersonImporter::class)
 */
class ImportsCsv
{
    /**
     * @param  class-string<Importer>  $importerClass
     * @param  Closure|null  $visible  an extra visibility condition ANDed with the
     *                                 importer's permission check — never a
     *                                 replacement for it
     */
    public static function action(string $importerClass, ?string $label = null, ?Closure $visible = null): Action
    {
        $importer = new $importerClass;
        $label ??= 'Import CSV';

        $permission = $importer->permission();

        return Action::make('importCsv')
            ->label($label)
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading($label)
            ->modalSubmitActionLabel('Import')
            ->modalWidth('3xl')
            ->extraModalFooterActions([
                self::sampleDownloadAction($importer),
            ])
            ->schema(self::schema($importer))
            // Hiding the button is presentation; the abort_unless inside the
            // action is the authorization. Both, because a Livewire action is
            // callable by anyone who can reach the page whether or not the
            // button ever rendered.
            ->visible(fn (): bool => self::allows($permission) && ($visible === null || $visible()))
            ->action(function (array $data) use ($importer, $permission): void {
                abort_unless(self::allows($permission), 403);

                self::runImport($importer, $data);
            });
    }

    /**
     * Whether the current user holds $permission.
     *
     * Deliberately not ChecksCrmPermissions: that trait fails *open* on a
     * missing permission row, which is defensible for a settings page and is
     * not defensible for bulk user creation.
     */
    protected static function allows(?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        $user = auth()->user();

        return $user !== null && $user->can($permission);
    }

    /**
     * Sample CSV download — streams a UTF-8-BOM file with headers + one
     * example row to seed the user's template.
     */
    public static function sampleDownloadAction(Importer $importer): Action
    {
        return Action::make('downloadSample')
            ->label(__('laravel-crm-filament::labels.actions.download_sample_csv'))
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->action(fn (): StreamedResponse => self::streamSample($importer));
    }

    public static function streamSample(Importer $importer): StreamedResponse
    {
        $columns = $importer->columns();
        $headers = array_values($columns);

        $sample = $importer->sampleRow();
        $row = [];
        foreach (array_keys($columns) as $dest) {
            $row[] = $sample[$dest] ?? '';
        }

        $filename = $importer->sampleFilename() . '.csv';

        $response = new StreamedResponse(function () use ($headers, $row): void {
            $out = fopen('php://output', 'w');
            fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, $headers);
            fputcsv($out, $row);
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        return $response;
    }

    /**
     * Modal schema. The mapping selects' options are reactively populated
     * from the CSV header row when the file is uploaded.
     *
     * @return array<int, mixed>
     */
    protected static function schema(Importer $importer): array
    {
        $mappingFields = [];
        foreach ($importer->columns() as $dest => $label) {
            $mappingFields[] = Select::make("mapping.{$dest}")
                ->label($label)
                ->options(function (Get $get) use ($label): array {
                    $headers = $get('csv_headers') ?: [];
                    $options = [];
                    foreach ($headers as $h) {
                        $options[$h] = $h;
                    }
                    // ensure the default label is always selectable, even
                    // before a file is uploaded.
                    if (! isset($options[$label])) {
                        $options[$label] = $label;
                    }

                    return $options;
                })
                ->default($label)
                ->searchable()
                ->native(false);
        }

        $dedupeOptions = [];
        foreach ($importer->columns() as $dest => $label) {
            $dedupeOptions[$dest] = $label;
        }

        return [
            FileUpload::make('file')
                ->label(__('laravel-crm-filament::labels.import.csv_file'))
                ->required()
                ->disk('local')
                ->directory('crm-imports')
                ->visibility('private')
                ->acceptedFileTypes([
                    'text/csv',
                    'text/plain',
                    'application/csv',
                    'application/vnd.ms-excel',
                ])
                ->live()
                ->afterStateUpdated(function ($state, Set $set) use ($importer): void {
                    $headers = self::peekHeaders($state);
                    $set('csv_headers', $headers);

                    if (! empty($headers)) {
                        foreach ($importer->columns() as $dest => $label) {
                            $set("mapping.{$dest}", self::guessHeader($label, $headers));
                        }
                    }
                }),
            Hidden::make('csv_headers')->default([]),
            Toggle::make('has_headers')
                ->label(__('laravel-crm-filament::labels.import.csv_has_header_row'))
                ->default(true),
            TextInput::make('chunk_size')
                ->label(__('laravel-crm-filament::labels.import.chunk_size'))
                ->numeric()
                ->minValue(1)
                ->maxValue(5000)
                ->default(500),
            Select::make('dedupe_field')
                ->label(__('laravel-crm-filament::labels.import.skip_duplicates_by'))
                ->options($dedupeOptions)
                ->default($importer->dedupeField())
                ->required()
                ->native(false),
            Section::make('Column mapping')->heading(__('laravel-crm-filament::labels.sections.column_mapping'))
                ->description('Match each CRM field to a column in your CSV.')
                ->schema($mappingFields)
                ->columns(2),
        ];
    }

    /**
     * Parse just the header row from the freshly-uploaded file. Filament
     * passes either a TemporaryUploadedFile, a stored path string, or null.
     *
     * @return array<int, string>
     */
    protected static function peekHeaders(mixed $state): array
    {
        $file = is_array($state) ? reset($state) : $state;

        if ($file === null || $file === false || $file === '') {
            return [];
        }

        $path = null;
        if ($file instanceof TemporaryUploadedFile) {
            $path = $file->getRealPath();
        } elseif (is_string($file)) {
            $path = Storage::disk('local')->path($file);
        }

        if (! $path || ! is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }

        // strip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            rewind($handle);
        }

        $row = fgetcsv($handle);
        fclose($handle);

        if (! $row) {
            return [];
        }

        return array_map(fn ($cell) => (string) $cell, $row);
    }

    /**
     * Auto-match a destination's label against CSV headers (case-insensitive,
     * spaces / underscores fuzzed).
     *
     * @param  array<int, string>  $headers
     */
    protected static function guessHeader(string $label, array $headers): ?string
    {
        $normalise = fn (string $s): string => preg_replace('/[^a-z0-9]+/', '', strtolower($s));
        $needle = $normalise($label);

        foreach ($headers as $h) {
            if ($normalise($h) === $needle) {
                return $h;
            }
        }

        return null;
    }

    /**
     * Stream the uploaded CSV file, mapping rows by header => destination,
     * processing in chunks, deduping silently.
     */
    protected static function runImport(Importer $importer, array $data): void
    {
        $file = $data['file'] ?? null;
        $file = is_array($file) ? reset($file) : $file;

        if (! $file) {
            Notification::make()->title('No file uploaded')->danger()->send();

            return;
        }

        $path = Storage::disk('local')->path($file);

        if (! is_readable($path)) {
            Notification::make()->title('Uploaded file is not readable')->danger()->send();

            return;
        }

        $chunkSize = max(1, (int) ($data['chunk_size'] ?? 500));
        $hasHeaders = (bool) ($data['has_headers'] ?? true);
        $mapping = $data['mapping'] ?? [];

        $handle = fopen($path, 'r');
        if (! $handle) {
            Notification::make()->title('Could not open uploaded file')->danger()->send();

            return;
        }

        // strip BOM
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            rewind($handle);
        }

        $headers = [];
        if ($hasHeaders) {
            $headerRow = fgetcsv($handle);
            if ($headerRow) {
                $headers = array_map(fn ($c) => (string) $c, $headerRow);
            }
        }

        // build dest_key => column index map
        $columnIndex = [];
        foreach ($importer->columns() as $dest => $label) {
            $chosen = $mapping[$dest] ?? $label;
            if ($hasHeaders) {
                $idx = array_search($chosen, $headers, true);
                if ($idx === false) {
                    // try fuzzy match
                    $fuzzy = self::guessHeader($label, $headers);
                    $idx = $fuzzy !== null ? array_search($fuzzy, $headers, true) : false;
                }
                $columnIndex[$dest] = $idx === false ? null : $idx;
            } else {
                // no headers: treat the mapping value as a 0-indexed column number
                $columnIndex[$dest] = ctype_digit((string) $chosen) ? (int) $chosen : null;
            }
        }

        $imported = 0;
        $skipped = 0;
        $batchSeenKeys = [];
        $buffer = [];

        $flush = function () use (&$buffer, &$imported, &$skipped, &$batchSeenKeys, $importer): void {
            foreach ($buffer as $row) {
                $key = $importer->dedupeValue($row);
                if ($key !== null && isset($batchSeenKeys[$key])) {
                    $skipped++;

                    continue;
                }
                if ($key !== null) {
                    $batchSeenKeys[$key] = true;
                }

                try {
                    if ($importer->importRow($row)) {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable) {
                    $skipped++;
                }
            }
            $buffer = [];
        };

        while (($raw = fgetcsv($handle)) !== false) {
            if ($raw === [null] || $raw === false) {
                continue;
            }

            $row = [];
            foreach ($columnIndex as $dest => $idx) {
                $value = $idx !== null && isset($raw[$idx]) ? $raw[$idx] : null;
                $row[$dest] = is_string($value) ? trim($value) : $value;
            }

            $buffer[] = $row;

            if (count($buffer) >= $chunkSize) {
                $flush();
            }
        }

        if (! empty($buffer)) {
            $flush();
        }

        fclose($handle);

        // tidy up the uploaded file
        try {
            Storage::disk('local')->delete($file);
        } catch (\Throwable) {
            // best-effort cleanup
        }

        Notification::make()
            ->title('Import complete')
            ->body("Imported: {$imported}, skipped: {$skipped}")
            ->success()
            ->send();
    }
}
