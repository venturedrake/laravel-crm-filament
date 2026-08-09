<?php

use Illuminate\Support\Arr;

/**
 * Every `laravel-crm-filament::labels.*` key referenced from src/ or
 * resources/views/ must exist, in all three locales.
 *
 * A missing key does not fail — Laravel returns the key string verbatim — so it
 * ships as a column header reading
 * "laravel-crm-filament::labels.fields.expires". That is exactly how
 * `labels.fields.expires` reached the pending-invitations table: the key
 * existed, but under `money`, and no test looked.
 *
 * The scan is deliberately over the source text rather than over rendered
 * pages: interpolated keys are rare here and every literal one is cheap to
 * check, which is the coverage a per-page test cannot give.
 */
function pluginRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return array<string, array<int, string>> key => the files referencing it
 */
function referencedLabelKeys(): array
{
    $keys = [];

    $directories = [
        pluginRoot() . '/src',
        pluginRoot() . '/resources/views',
    ];

    foreach ($directories as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            preg_match_all(
                '/laravel-crm-filament::labels\.([a-z0-9_]+(?:\.[a-z0-9_]+)+)/i',
                $contents,
                $matches,
            );

            foreach ($matches[1] as $key) {
                // A trailing underscore means the literal was a prefix that the
                // source concatenates a variable onto — e.g.
                // `labels.templates.doc_type_' . $docType`. Those are checked
                // by the tests that own them, not from here.
                if (str_ends_with($key, '_')) {
                    continue;
                }

                $keys[$key][] = str_replace(pluginRoot() . '/', '', $file->getPathname());
            }
        }
    }

    ksort($keys);

    return $keys;
}

/**
 * @return array<string, mixed>
 */
function labelsFor(string $locale): array
{
    return require pluginRoot() . '/resources/lang/' . $locale . '/labels.php';
}

it('finds label keys to check at all', function () {
    // Guards the regex: a scan that silently matches nothing would make every
    // assertion below vacuously true.
    expect(count(referencedLabelKeys()))->toBeGreaterThan(200);
});

it('resolves every referenced label key in every locale', function (string $locale) {
    $labels = labelsFor($locale);

    $missing = [];

    foreach (referencedLabelKeys() as $key => $files) {
        if (! Arr::has($labels, $key)) {
            $missing[] = $key . '  (' . implode(', ', array_unique($files)) . ')';
        }
    }

    expect($missing)->toBe([], "Missing in {$locale}:\n" . implode("\n", $missing));
})->with(['en', 'es', 'fr']);

it('keeps the three locales on identical key sets', function () {
    $flatten = function (array $labels, string $prefix = '') use (&$flatten): array {
        $flat = [];

        foreach ($labels as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat = array_merge($flat, $flatten($value, $path));

                continue;
            }

            $flat[] = $path;
        }

        return $flat;
    };

    $en = $flatten(labelsFor('en'));
    sort($en);

    foreach (['es', 'fr'] as $locale) {
        $other = $flatten(labelsFor($locale));
        sort($other);

        expect($other)->toBe($en, "{$locale} has drifted from en");
    }
});
