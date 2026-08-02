<?php

use Filament\Auth\Pages\EditProfile;
use VentureDrake\LaravelCrmFilament\Auth\Login;
use VentureDrake\LaravelCrmFilament\Auth\Profile;
use VentureDrake\LaravelCrmFilament\Resources\ChatWidgets\ChatWidgetResource;
use VentureDrake\LaravelCrmFilament\Resources\ChatWidgets\Pages\ViewChatWidget;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\Concerns\HasInvoicePortalAction;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ViewInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns\HasQuotePortalAction;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ViewQuote;

it('registers a View page on the ChatWidget resource', function () {
    expect(ChatWidgetResource::getPages())->toHaveKey('view');
    expect((new ReflectionClass(ViewChatWidget::class))->getStaticPropertyValue('resource'))
        ->toBe(ChatWidgetResource::class);
});

it('renders the chat embed snippet and iframe preview Blade view', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/chat-widgets/embed.blade.php');
    expect($blade)->toContain('Copy to clipboard');
    expect($blade)->toContain('navigator.clipboard.writeText');
    expect($blade)->toContain('<iframe');
    expect($blade)->toContain('Embed snippet');
    expect($blade)->toContain('Live preview');
});

it('exposes the chat embed and iframe URLs from ViewChatWidget', function () {
    $source = file_get_contents((new ReflectionClass(ViewChatWidget::class))->getFileName());
    expect($source)->toContain("'laravel-crm.portal.chat.embed'");
    expect($source)->toContain("'laravel-crm.portal.chat.widget'");
    expect($source)->toContain('public_key');
});

it('promotes the Quote portal action as a primary "Preview portal" header action', function () {
    $source = file_get_contents((new ReflectionClass(HasQuotePortalAction::class))->getFileName());
    expect($source)->toContain('actions.preview_portal');
    expect($source)->toContain("->color('primary')");
    expect($source)->toContain('openUrlInNewTab');
    expect($source)->toContain("PortalUrl::for('laravel-crm.portal.quotes.show'");

    expect(class_uses_recursive(ViewQuote::class))->toContain(HasQuotePortalAction::class);
});

it('promotes the Invoice portal action as a primary "Preview portal" header action', function () {
    $source = file_get_contents((new ReflectionClass(HasInvoicePortalAction::class))->getFileName());
    expect($source)->toContain('actions.preview_portal');
    expect($source)->toContain("->color('primary')");
    expect($source)->toContain('openUrlInNewTab');
    expect($source)->toContain("PortalUrl::for('laravel-crm.portal.invoices.show'");

    expect(class_uses_recursive(ViewInvoice::class))->toContain(HasInvoicePortalAction::class);
});

it('declares Login class extending Filament\'s login page', function () {
    expect(is_subclass_of(Login::class, Filament\Auth\Pages\Login::class))->toBeTrue();
});

it('Login pulls organization_name and logo_file from SettingService', function () {
    $source = file_get_contents((new ReflectionClass(Login::class))->getFileName());
    expect($source)->toContain("'organization_name'");
    expect($source)->toContain("'logo_file'");
    expect($source)->toContain('laravel-crm.settings');
    expect($source)->toContain('#05b3a9');
});

it('declares Profile class extending Filament\'s EditProfile page', function () {
    expect(is_subclass_of(Profile::class, EditProfile::class))->toBeTrue();
});

it('Profile renders avatar upload + notification preferences section linking to Reminders', function () {
    $source = file_get_contents((new ReflectionClass(Profile::class))->getFileName());
    expect($source)->toContain("FileUpload::make('avatar')");
    expect($source)->toContain('Notification preferences');
    expect($source)->toContain('Reminders::getUrl');
});

it('CrmPanelProvider stub wires the branded Login and Profile pages', function () {
    $stub = file_get_contents(__DIR__ . '/../../stubs/CrmPanelProvider.php.stub');
    expect($stub)->toContain('use VentureDrake\\LaravelCrmFilament\\Auth\\Login;');
    expect($stub)->toContain('use VentureDrake\\LaravelCrmFilament\\Auth\\Profile;');
    expect($stub)->toContain('->login(Login::class)');
    expect($stub)->toContain('->profile(Profile::class)');
    expect($stub)->toContain("'organization_name'");
    expect($stub)->toContain("'logo_file'");
    expect($stub)->toContain('#05b3a9');
});
