<?php

/**
 * This package ships no CSS.
 *
 * Filament's compiled stylesheet (`filament/filament/dist/theme.css`) is built
 * from Filament's own source and contains only its `fi-*` classes — it carries
 * no general Tailwind utility set at all, not even `flex` or `text-sm`. A host
 * has no reason to add this package's views to a Tailwind content path, and the
 * standalone panel the installer publishes has no build step of its own.
 *
 * So a `class="grid grid-cols-3 gap-4"` in a package Blade file resolves to
 * nothing whatsoever, and the page renders as a stack of unstyled text. That is
 * not a subtle degradation — it is the difference between a settings screen and
 * a wall of words, and it is invisible in a test suite that only asserts on
 * behaviour.
 *
 * Hence this guard. Everything visual must come from a Filament Blade
 * component, an `fi-*` class, or an inline style.
 */
beforeEach(function () {
    $this->themeCss = dirname(__DIR__, 2) . '/vendor/filament/filament/dist/theme.css';
});

it('confirms the compiled Filament theme really does ship no utility classes', function () {
    // The premise of every assertion below. If Filament ever starts shipping a
    // utility layer this test fails first and explains why the rest can relax.
    $css = (string) file_get_contents($this->themeCss);

    foreach (['.flex{', '.grid{', '.gap-4{', '.text-sm{', '.rounded-lg{', '.w-full{'] as $utility) {
        expect($css)->not->toContain($utility);
    }

    // …while its own classes are present, which is what the views may rely on.
    expect($css)->toContain('.fi-sc-form')
        ->toContain('.fi-grid')
        ->toContain('.fi-btn');
});

it('uses no Tailwind utility classes in any package Blade view', function () {
    $offenders = [];

    foreach (crmBladeViews() as $path) {
        $classes = crmBladeClassNames($path);

        $utilities = array_values(array_filter(
            $classes,
            fn (string $class): bool => crmClassIsUtility($class),
        ));

        if ($utilities !== []) {
            $offenders[crmRelativeViewPath($path)] = $utilities;
        }
    }

    // auth/profile.blade.php predates this guard and is a known, separate piece
    // of work — it is listed rather than silently skipped so it stays visible.
    $known = ['resources/views/auth/profile.blade.php'];

    expect(array_diff_key($offenders, array_flip($known)))->toBe([]);
});

it('actually catches the classes that broke, and leaves the safe ones alone', function () {
    // A guard that flags nothing is worse than no guard. These are the exact
    // classes that rendered as nothing on the Templates and Updates screens.
    foreach (['grid-cols-3', 'sm:grid-cols-2', 'gap-4', 'space-y-6', 'text-sm', 'bg-gray-100', 'rounded-xl', 'w-full', 'flex', 'items-center', 'font-mono', 'underline', 'text-primary-600'] as $utility) {
        expect(crmClassIsUtility($utility))->toBeTrue($utility . ' should be flagged');
    }

    foreach (['fi-btn', 'fi-sc-form', 'sm:fi-grid-cols', 'crm-task-card', 'crm-card-badge--related', 'mono'] as $safe) {
        expect(crmClassIsUtility($safe))->toBeFalse($safe . ' should not be flagged');
    }

    // …and the known-offender allowlist is not vacuous: profile.blade.php is on
    // it because it genuinely trips the guard today.
    $profile = dirname(__DIR__, 2) . '/resources/views/auth/profile.blade.php';

    expect(array_filter(crmBladeClassNames($profile), fn ($c) => crmClassIsUtility($c)))
        ->not->toBeEmpty();
});

it('spaces stacked page content with fi-sc-form rather than space-y-6', function () {
    // .fi-page-content is itself a grid with a row gap, so a wrapper element is
    // only needed when it is a <form>. space-y-6 does not exist in the compiled
    // theme, so those wrappers rendered their sections flush against each other.
    foreach (crmBladeViews() as $path) {
        expect(crmBladeClassNames($path))->not->toContain('space-y-6', crmRelativeViewPath($path));
    }
});

/**
 * @return array<int, string>
 */
function crmBladeViews(): array
{
    $paths = [];
    $root = dirname(__DIR__, 2) . '/resources/views';

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths);

    return $paths;
}

function crmRelativeViewPath(string $path): string
{
    return ltrim(str_replace(dirname(__DIR__, 2), '', $path), '/');
}

/**
 * Every class name appearing in a `class="..."` attribute in $path.
 *
 * Blade comments are stripped first: the views explaining this rule quote the
 * very markup it forbids, and a naive scan reads those examples as real.
 *
 * @return array<int, string>
 */
function crmBladeClassNames(string $path): array
{
    $source = (string) file_get_contents($path);
    $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    preg_match_all('/\bclass="([^"]*)"/', $source, $matches);

    $classes = [];

    foreach ($matches[1] as $attribute) {
        // Drop Blade interpolation — `{{ $x }}` inside a class attribute is a
        // runtime value, not a literal class name.
        $attribute = preg_replace('/\{\{.*?\}\}/s', ' ', $attribute);

        // …and Blade directives, e.g. `class="a @if ($x) b @endif"`.
        $attribute = preg_replace('/@\w+(\s*\([^)]*\))?/', ' ', $attribute);

        foreach (preg_split('/\s+/', trim($attribute)) as $class) {
            if ($class !== '') {
                $classes[] = $class;
            }
        }
    }

    return array_values(array_unique($classes));
}

/**
 * Whether a class name is a Tailwind utility.
 *
 * A denylist of utility shapes rather than an allowlist of known-good classes:
 * the package's own `crm-*` namespace and Filament's `fi-*` both pass without
 * enumeration, while the exact thing that broke — `grid-cols-3`, `text-sm`,
 * `bg-gray-100`, `space-y-6` — is caught. Asking "is this class defined
 * somewhere?" sounds stricter but drags in every partial whose styling lives in
 * its parent view, and answers a question nobody is asking.
 */
function crmClassIsUtility(string $class): bool
{
    // Strip a responsive/state prefix: `sm:grid-cols-2` is still a utility.
    $bare = str_contains($class, ':') ? substr($class, strrpos($class, ':') + 1) : $class;

    foreach (['fi-', 'crm-'] as $namespace) {
        if (str_starts_with($bare, $namespace)) {
            return false;
        }
    }

    $utilityPatterns = [
        // Layout and spacing.
        '/^(flex|grid|block|inline|hidden|contents)$/',
        '/^(grid|col|row)-(cols|span|start|end|auto)-/',
        '/^(gap|space)-[xy]?-?[0-9.]+$/',
        '/^-?(m|p)[trblxy]?-[0-9.]+$/',
        '/^(w|h|min-w|min-h|max-w|max-h)-/',
        '/^(items|justify|content|self|place)-/',
        '/^(flex|order)-(1|auto|none|wrap|nowrap|col|row|initial)/',
        // Typography.
        '/^text-(xs|sm|base|lg|[0-9]?xl)$/',
        '/^font-(thin|light|normal|medium|semibold|bold|black|sans|serif|mono)$/',
        '/^(leading|tracking|whitespace|break|truncate|underline|uppercase|lowercase|capitalize)/',
        // Colour, border, effects.
        '/^(bg|text|border|ring|divide|from|via|to|fill|stroke|shadow|outline)-[a-z]+(-[0-9]{2,3})?(\/[0-9]+)?$/',
        '/^(rounded|border|ring|shadow|opacity|overflow|cursor|transition|duration|z)(-|$)/',
        '/^(absolute|relative|fixed|sticky|static)$/',
        '/^(prose|sr-only|antialiased)/',
    ];

    foreach ($utilityPatterns as $pattern) {
        if (preg_match($pattern, $bare)) {
            return true;
        }
    }

    return false;
}

it('never puts callout body text in the default slot, which Filament drops', function () {
    // `x-filament::callout` — the same file in Filament v4 and v5 — renders its
    // `icon`, `heading`, `description`, `footer` and `controls` and nothing
    // else. `$slot` is never echoed, so a sentence written between the tags
    // vanishes silently: the callout still draws its border, icon and controls,
    // which is exactly why this survived review twice. Body text belongs in
    // `description`.
    $calloutSource = dirname(__DIR__, 2) . '/vendor/filament/support/resources/views/components/callout.blade.php';

    // The premise. If Filament ever starts echoing $slot, this fails first.
    expect(file_exists($calloutSource))->toBeTrue();
    expect((string) file_get_contents($calloutSource))->not->toContain('{{ $slot }}');

    $offenders = [];

    foreach (crmBladeViews() as $file) {
        // Comments stripped from the whole file first, not just from the slot:
        // a docblock that names `<x-filament::callout>` in prose — as the
        // banner's does — otherwise reads as an unclosed opening tag and
        // swallows the rest of the file.
        $contents = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file));

        // Everything between an opening <x-filament::callout ...> and its
        // closing tag. A self-closing callout has no slot to get wrong.
        preg_match_all('/<x-filament::callout\b[^>]*(?<!\/)>(.*?)<\/x-filament::callout>/s', $contents, $matches);

        foreach ($matches[1] as $slot) {
            // Two things are legitimately allowed between the tags, because
            // neither lands in the default slot: <x-slot> blocks (named slots
            // *are* rendered) and the control directives that wrap them.
            // Whatever survives both is body text, and body text here is
            // dropped on the floor.
            $stripped = preg_replace('/<x-slot\b.*?<\/x-slot>/s', '', $slot);
            $stripped = preg_replace('/@[a-zA-Z]+(\s*\((?:[^()]|\([^()]*\))*\))?/', '', (string) $stripped);

            if (trim((string) $stripped) !== '') {
                $offenders[] = crmRelativeViewPath($file);
            }
        }
    }

    expect($offenders)->toBe([]);
});
