<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Mime\MimeTypes;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Support\AttachmentUpload;

/**
 * The panel's single path for writing a CRM attachment.
 *
 * Core 2.7.1 is a security release: attachments could land on a public disk
 * and the legacy uploader took any file type. Core now routes every attachment
 * through {@see AttachmentUpload} — a content-sniffed MIME check *and* a
 * separate extension check, a 10 MB cap, a hashed filename, and a write to the
 * private `laravel-crm.attachments.disk`. The panel's Filament `FileUpload`
 * fields bypassed all of that, so both files relation managers now hand the
 * temporary upload to this class instead of letting Filament store it.
 *
 * The Filament field still carries `acceptedFileTypes()` and `maxSize()`, but
 * only as hints for the browser: {@see self::storeFor()} re-runs core's rules
 * server-side and is the check that counts.
 */
final class AttachmentStore
{
    /**
     * Core's validation rules for one attachment.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return AttachmentUpload::rules();
    }

    /**
     * The MIME types matching core's allowed extensions, for FilePond's
     * client-side filter.
     *
     * Filament also turns this list into a server-side `mimetypes:` rule, so
     * it is every alias Symfony knows for each extension rather than one
     * canonical type each: core's `mimes:` rule maps a detected MIME type back
     * to an extension, and a narrower list here would reject a file core
     * accepts (a CSV sniffed as `text/plain`, say).
     *
     * @return array<int, string>
     */
    public static function acceptedMimeTypes(): array
    {
        $mimeTypes = MimeTypes::getDefault();

        return collect(AttachmentUpload::EXTENSIONS)
            ->flatMap(fn (string $extension): array => $mimeTypes->getMimeTypes($extension))
            // Symfony's table carries Windows-registry placeholders such as
            // `zz-application/zz-winassoc-doc`; no browser reports those.
            ->reject(fn (string $type): bool => str_starts_with($type, 'zz-'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Validate an upload against core's rules, store it through core, and
     * attach the resulting File row to the owner.
     *
     * `$errorKey` is the state path of the field the upload came from, so a
     * failure renders under that field rather than as a generic error.
     *
     * @param  array<string, mixed>  $extra  Panel-side attributes (`title`, `format`).
     *
     * @throws ValidationException
     */
    public static function storeFor(Model $owner, mixed $upload, array $extra, string $errorKey): File
    {
        $upload = self::validate($upload, $errorKey);

        $attributes = AttachmentUpload::store($upload, $owner, $errorKey);

        $format = trim((string) ($extra['format'] ?? ''));

        try {
            /** @var File $file */
            $file = $owner->files()->create([
                ...$attributes,
                'external_id' => Uuid::uuid4()->toString(),
                'title' => $extra['title'] ?? null,
                'format' => $format !== ''
                    ? $format
                    : (strtolower(pathinfo($upload->getClientOriginalName(), PATHINFO_EXTENSION)) ?: null),
                'user_created_id' => auth()->id(),
            ]);
        } catch (\Throwable $exception) {
            // The bytes are already on the private disk; without a row nothing
            // can reach them, so do not leave them behind.
            Storage::disk($attributes['disk'])->delete($attributes['file']);

            throw $exception;
        }

        $upload->delete();

        return $file;
    }

    /**
     * @throws ValidationException
     */
    public static function validate(mixed $upload, string $errorKey): TemporaryUploadedFile
    {
        $validator = Validator::make(['file' => $upload], ['file' => self::rules()]);

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                $errorKey => $validator->errors()->get('file'),
            ]);
        }

        // The rules accept any UploadedFile; core's store() needs Livewire's.
        // A plain path string (client-controllable form state) fails `file`
        // above, so this only trips on a programming error.
        if (! $upload instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages([
                $errorKey => ucfirst(__('laravel-crm::lang.file_upload_failed')),
            ]);
        }

        return $upload;
    }
}
