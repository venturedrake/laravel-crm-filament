<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Forms;

use Filament\Forms\Components\Select;
use VentureDrake\LaravelCrm\Support\PdfTemplateRegistry;

/**
 * The per-record PDF template picker, shared by all five document forms.
 *
 * A record with no `pdf_template` follows Settings → Templates, so the blank
 * option is not "none" — it is "track the default", and its label names the
 * template that currently resolves to.
 */
class PdfTemplateSelect
{
    /**
     * @param  string  $docType  one of PdfTemplateRegistry::DOC_TYPES — the
     *                           hyphenated spelling (`purchase-order`), not the
     *                           plugin's storage-directory spelling
     */
    public static function make(string $docType): Select
    {
        return Select::make('pdf_template')
            ->label(__('laravel-crm-filament::labels.money.pdf_template'))
            ->options(collect(PdfTemplateRegistry::all())
                ->map(fn (array $template): string => $template['label'])
                ->all())
            ->placeholder(PdfTemplateRegistry::defaultOptionLabel($docType))
            /*
             * This line is the entire ''-vs-null contract; do not "clean it up".
             *
             * Filament submits null for a cleared Select. FormPayload hands that
             * to Fluent, and core's PdfTemplateRegistry::resolveUpdate(null,
             * $current) *keeps* $current — deliberately, because every other
             * writer (the REST API, the legacy controllers, the multi-purchase-
             * order split) submits nothing at all for this field and must not
             * silently clear a record's pinned template.
             *
             * So a form that means "go back to following settings" has to say so
             * explicitly, and '' is how core spells that: resolveUpdate('')
             * routes through sanitize('') => null => the column is cleared.
             * Without this, the user can never un-pin a template.
             */
            ->dehydrateStateUsing(fn ($state) => $state ?? '')
            ->native(false);
    }
}
