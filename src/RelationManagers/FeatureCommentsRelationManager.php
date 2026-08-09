<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\FeatureComment;
use VentureDrake\LaravelCrm\Services\FeatureService;
use VentureDrake\LaravelCrmFilament\Concerns\ChecksCrmPermissions;

class FeatureCommentsRelationManager extends RelationManager
{
    use ChecksCrmPermissions;

    /**
     * Permission required to post or amend a comment. Core CRM ships no
     * FeatureCommentPolicy (and no FeatureComment model before 2.2), so the
     * comment actions are gated on the owning feature's permissions instead
     * of a policy — Filament v4 actions carry no implicit authorization.
     */
    public const CREATE_PERMISSION = 'edit crm features';

    public const DELETE_PERMISSION = 'delete crm features';

    /** @var list<string> Roles allowed to moderate other users' comments. */
    public const MODERATOR_ROLES = ['Owner', 'Admin'];

    protected static string $relationship = 'comments';

    protected static ?string $title = 'Comments';

    /**
     * Anyone who may edit features may add a comment.
     */
    public function canCreateFeatureComment(): bool
    {
        return static::userHasCrmPermission(static::CREATE_PERMISSION);
    }

    /**
     * Editing a comment additionally requires that it is the acting user's own
     * comment, unless they hold a moderator role.
     */
    public function canEditFeatureComment(?Model $record): bool
    {
        return $this->canCreateFeatureComment()
            && $this->isFeatureCommentAuthorOrAdmin($record);
    }

    public function canDeleteFeatureComment(?Model $record): bool
    {
        return static::userHasCrmPermission(static::DELETE_PERMISSION)
            && $this->isFeatureCommentAuthorOrAdmin($record);
    }

    /**
     * Whether the acting user is a CRM moderator.
     *
     * Also handed to FeatureService::comment() as its is_admin_reply flag, so
     * the "team" badge on a comment and the moderation buttons beside it are
     * decided by one predicate rather than two.
     */
    public function userIsFeatureModerator(): bool
    {
        $user = auth()->user();

        return $user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole(static::MODERATOR_ROLES);
    }

    /**
     * A comment belongs to the user recorded in `user_created_id` (set by
     * FeatureService::comment()); everyone else needs a moderator role.
     */
    protected function isFeatureCommentAuthorOrAdmin(?Model $record): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $authorId = $record?->getAttribute('user_created_id');

        if (filled($authorId) && (string) $authorId === (string) $user->getAuthIdentifier()) {
            return true;
        }

        return $this->userIsFeatureModerator();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Textarea::make('body')
                ->label(__('laravel-crm-filament::labels.fields.message'))
                ->required()
                ->rows(4)
                ->columnSpanFull(),
            Forms\Components\Select::make('parent_id')
                ->label(__('laravel-crm-filament::labels.fields.parent'))
                ->options(fn (RelationManager $livewire) => $livewire->getOwnerRecord()
                    ->comments()
                    ->orderBy('created_at', 'desc')
                    ->limit(100)
                    ->get()
                    ->mapWithKeys(fn (FeatureComment $c) => [
                        $c->id => str(strip_tags($c->body))->limit(60)->toString(),
                    ])
                    ->all())
                ->searchable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                Tables\Columns\TextColumn::make('createdByUser.name')
                    ->label(__('laravel-crm-filament::labels.fields.by'))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('body')
                    ->label(__('laravel-crm-filament::labels.fields.message'))
                    ->limit(80)
                    ->tooltip(fn (FeatureComment $record): ?string => $record->body)
                    ->wrap(),
                Tables\Columns\IconColumn::make('is_admin_reply')
                    ->label(__('laravel-crm-filament::labels.fields.admin_reply'))
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.timestamp'))
                    ->dateTime()
                    ->since()
                    ->tooltip(fn (FeatureComment $record): ?string => optional($record->created_at)->toDateTimeString())
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Actions\CreateAction::make()
                    ->authorize(fn (): bool => $this->canCreateFeatureComment())
                    ->schema([
                        Forms\Components\Textarea::make('body')
                            ->label(__('laravel-crm-filament::labels.fields.message'))
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('parent_id')
                            ->label(__('laravel-crm-filament::labels.fields.parent'))
                            ->options(fn (RelationManager $livewire) => $livewire->getOwnerRecord()
                                ->comments()
                                ->orderBy('created_at', 'desc')
                                ->limit(100)
                                ->get()
                                ->mapWithKeys(fn (FeatureComment $c) => [
                                    $c->id => str(strip_tags($c->body))->limit(60)->toString(),
                                ])
                                ->all())
                            ->searchable(),
                    ])
                    ->using(function (array $data, RelationManager $livewire): FeatureComment {
                        $feature = $livewire->getOwnerRecord();

                        // core 2.4.0 gave comment() an optional 4th param for
                        // the admin-reply flag. This RM already decides who is
                        // a moderator for its own UI, so pass that decision in
                        // rather than letting core re-derive it and risk the
                        // badge disagreeing with the buttons beside it.
                        $comment = app(FeatureService::class)->comment(
                            $feature,
                            auth()->user(),
                            $data['body'],
                            $livewire->userIsFeatureModerator(),
                        );

                        if (! empty($data['parent_id'])) {
                            $comment->forceFill(['parent_id' => $data['parent_id']])->save();
                        }

                        return $comment;
                    })
                    ->after(function () {
                        Notification::make()
                            ->title('Comment added')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->authorize(fn (?Model $record): bool => $this->canEditFeatureComment($record)),
                Actions\DeleteAction::make()
                    ->authorize(fn (?Model $record): bool => $this->canDeleteFeatureComment($record))
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([]);
    }
}
