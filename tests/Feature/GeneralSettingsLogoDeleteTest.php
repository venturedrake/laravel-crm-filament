<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use VentureDrake\LaravelCrmFilament\Pages\GeneralSettings;

/**
 * Removing the settings logo — core 2.4.1's `deleteLogo()`.
 *
 * There had been no way to remove a logo at all, only to replace it. Clearing
 * the upload field cannot mean "delete": `BaseFileUpload::hydrateFiles()`
 * silently blanks a path it cannot see on the disk, so an unrelated save would
 * wipe a `logo_file` that base had written somewhere else (which is why
 * `saveLogo()` treats a blank state as a no-op). Deletion has to be an explicit
 * intent, so it is an explicit button.
 *
 * The tests that matter here go through a real `mount()` rather than assigning
 * `$page->data` by hand, and that is the whole point rather than thoroughness
 * for its own sake. `hydrateFiles()` — the very method named above — rewrites
 * the seeded string path into a uuid-keyed map, and that is the shape the
 * mounted component and every Livewire round-trip actually carry. Asserting
 * against the pre-hydration string tested a state the running page never has,
 * and passed while the button was inert: `$pending !== $saved` compared an
 * array with a string, was therefore always true, and every "remove" took the
 * discard branch and left the logo in place.
 */
function logoSettingsPage(): GeneralSettings
{
    return new class extends GeneralSettings
    {
        // Bypasses the settings-page permission gate, which has its own tests;
        // what is under test here is what deleteLogo() does once allowed.
        public static function canEditCrmSettings(): bool
        {
            return true;
        }
    };
}

/**
 * A page mounted the way a request mounts it, with the logo actually present on
 * the disk so `hydrateFiles()` keeps it rather than silently blanking it.
 */
function mountedLogoSettingsPage(string $path = 'laravel-crm/brand.png'): GeneralSettings
{
    Storage::fake('public');
    Storage::disk('public')->put($path, 'PNGDATA');

    $settings = app('laravel-crm.settings');
    $settings->set('logo_file', $path, 'Logo file');
    $settings->set(GeneralSettings::LOGO_FILE_NAME_KEY, basename($path), 'Logo file name');
    $settings->forgetCache();

    $page = logoSettingsPage();
    $page->mount();

    return $page;
}

it('hydrates the uploader into the uuid-keyed shape, not the seeded string', function () {
    $page = mountedLogoSettingsPage();

    // Guards the assumption the rest of this file rests on. If Filament ever
    // stops rewriting the state, these tests should say so rather than quietly
    // going back to testing a shape the page does not have.
    expect($page->data['logo_upload'])->toBeArray()
        ->and(array_values($page->data['logo_upload']))->toBe(['laravel-crm/brand.png'])
        ->and($page->data['logo_file'])->toBe('laravel-crm/brand.png');
});

it('clears the stored logo keys and the field state, from a real mount', function () {
    $page = mountedLogoSettingsPage();

    expect($page->hasLogo())->toBeTrue();

    // No pending upload: the user opened the page and clicked Remove.
    $page->deleteLogo();

    $settings = app('laravel-crm.settings');
    $settings->forgetCache();

    expect((string) $settings->get('logo_file'))->toBe('')
        ->and((string) $settings->get(GeneralSettings::LOGO_FILE_NAME_KEY))->toBe('')
        ->and($page->data['logo_file'])->toBeNull()
        ->and($page->hasLogo())->toBeFalse();
});

it('still clears the logo when the state is a bare string', function () {
    // The pre-hydration shape is real too — it is what mount() seeds before
    // fill() runs — so both have to resolve to the same path.
    $settings = app('laravel-crm.settings');
    $settings->set('logo_file', 'laravel-crm/brand.png', 'Logo file');
    $settings->forgetCache();

    $page = logoSettingsPage();
    $page->data['logo_file'] = 'laravel-crm/brand.png';
    $page->data['logo_upload'] = 'laravel-crm/brand.png';

    $page->deleteLogo();

    $settings->forgetCache();

    expect((string) $settings->get('logo_file'))->toBe('')
        ->and($page->hasLogo())->toBeFalse();
});

it('discards a pending upload back to the saved logo rather than deleting it', function () {
    $page = mountedLogoSettingsPage('laravel-crm/saved.png');

    // A file just picked and not yet saved, in the shape the uploader holds it.
    $page->data['logo_upload'] = ['a-fresh-uuid' => 'laravel-crm/just-picked.png'];

    $page->deleteLogo();

    $settings = app('laravel-crm.settings');
    $settings->forgetCache();

    // The artwork still printing on every PDF is not what the user was pointing
    // at when they clicked remove on a file they had just chosen.
    expect(array_values($page->data['logo_upload']))->toBe(['laravel-crm/saved.png'])
        ->and($settings->get('logo_file'))->toBe('laravel-crm/saved.png');
});

/**
 * The uploader's third state, and the one that bites hardest.
 *
 * Until `saveUploadedFiles()` runs on dehydration, a file the user has just
 * picked sits in the state as an object, not a path — `BaseFileUpload` types
 * every entry `TemporaryUploadedFile | string`. So resolving the state to a
 * path answers null for a pending upload, and a `deleteLogo()` that asked "is
 * anything pending?" of that resolved path would read the field as empty and
 * fall straight through to deleting the saved logo — destroying exactly the
 * artwork the discard branch exists to protect, and doing it at the moment the
 * user was least expecting it.
 */
it('discards a freshly-picked file without touching the saved logo', function () {
    $page = mountedLogoSettingsPage();

    // An object, as the uploader holds it before the file is stored. (An
    // UploadedFile stands in for TemporaryUploadedFile: what the code sees is
    // a non-string entry, which is the distinction under test.)
    $page->data['logo_upload'] = ['pending-uuid' => UploadedFile::fake()->image('new.png')];

    expect($page->hasLogo())->toBeTrue();

    $page->deleteLogo();

    $settings = app('laravel-crm.settings');
    $settings->forgetCache();

    expect($settings->get('logo_file'))->toBe('laravel-crm/brand.png')
        ->and(array_values($page->data['logo_upload']))->toBe(['laravel-crm/brand.png']);
});

it('still shows the remove button while only a pending upload is on screen', function () {
    $page = logoSettingsPage();

    $page->data['logo_file'] = null;
    $page->data['logo_upload'] = ['pending-uuid' => UploadedFile::fake()->image('new.png')];

    // Resolving the state to a path first would answer null here and hide the
    // button mid-upload.
    expect($page->hasLogo())->toBeTrue();
});

it('is a no-op worth showing only when there is a logo to remove', function () {
    $page = logoSettingsPage();

    $page->data['logo_file'] = null;
    $page->data['logo_upload'] = null;
    expect($page->hasLogo())->toBeFalse();

    // An emptied uploader is `[]`, not null, once the component has run.
    $page->data['logo_upload'] = [];
    expect($page->hasLogo())->toBeFalse();

    $page->data['logo_upload'] = ['some-uuid' => 'laravel-crm/pending.png'];
    expect($page->hasLogo())->toBeTrue();

    $page->data['logo_upload'] = 'laravel-crm/pending.png';
    expect($page->hasLogo())->toBeTrue();
});

it('refuses to remove the logo without edit rights', function () {
    $page = new class extends GeneralSettings
    {
        public static function canEditCrmSettings(): bool
        {
            return false;
        }
    };

    $page->data['logo_file'] = 'laravel-crm/brand.png';
    $page->data['logo_upload'] = ['some-uuid' => 'laravel-crm/brand.png'];

    expect(fn () => $page->deleteLogo())
        ->toThrow(HttpException::class);
});

it('leaves the image file on disk, clearing only the reference', function () {
    $source = (string) file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    // The filename may be one base wrote, or shared with another install
    // pointed at the same storage. Unlinking bytes on the strength of a
    // settings row is not recoverable; clearing the reference is.
    $start = (int) strpos($source, 'public function deleteLogo');
    // Bounded to the method body: unbounded, this ran to the end of the file
    // and caught the row deletes in syncPhones()/syncEmails().
    $end = (int) strpos($source, "\n    protected function", $start);
    $deleteLogo = substr($source, $start, $end - $start);

    expect($deleteLogo)->not->toContain('Storage::delete')
        ->not->toContain('->delete(')
        ->not->toContain('unlink');
});
