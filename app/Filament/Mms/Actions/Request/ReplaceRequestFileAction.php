<?php

namespace App\Filament\Mms\Actions\Request;

use App\Filament\Support\AttachmentFields;
use App\Models\Attachment;
use App\Models\MatterRequest;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * A request's file replaced by the super admin: another file in its place — at the same step of the request (it
 * keeps the one it replaces' date, which says the step: RequestHistory::stageOf), in the same category. The one
 * replaced is deleted the way a request's files are (for good: only financial evidence is kept hidden), and both
 * are on the history.
 * Nobody is told: the file is corrected, the request is not sent again.
 */
class ReplaceRequestFileAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'replaceFile';
    }

    public function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('Replace'))
            ->tooltip(__('Replace this file'))
            ->icon('heroicon-o-arrow-path')
            ->iconButton()
            ->color('gray')
            ->visible(fn (): bool => self::allowed())
            ->modalHeading(fn (Attachment $record): string => __('Replace :name', ['name' => $record->name]))
            ->modalDescription(__('The file chosen takes its place, at the same step of the request. The one replaced is deleted, and nobody is notified.'))
            ->modalSubmitActionLabel(__('Replace'))
            ->action(function (Attachment $record, array $data): void {
                abort_unless(self::allowed(), 403);

                $new = self::replace($record, (string) ($data['file'] ?? ''));

                if ($new !== null) {
                    Notification::make()->success()->title(__(':old replaced by :new', ['old' => $record->name, 'new' => $new->name]))->send();
                }
            });
    }

    public function getSchema(Schema $schema): Schema
    {
        return $schema->components([
            AttachmentFields::upload('file')->label(__('File'))->multiple(false),
        ]);
    }

    /**
     * The upload kept on the request in the file's place: the new attachment — null when it was refused (a kind
     * not allowed: said by AttachmentFields), the old one then left as it was.
     */
    public static function replace(Attachment $old, string $path): ?Attachment
    {
        $request = $old->attachable;

        if (! $request instanceof MatterRequest || $path === '') {
            return null;
        }

        return DB::transaction(function () use ($old, $request, $path): ?Attachment {
            $new = AttachmentFields::attach($request, [$path], $old->category, 'user', $old->description)->first();

            if ($new === null) {
                return null;
            }

            // At the step of the one it replaces: that step is told by the file's date.
            $new->forceFill(['created_at' => $old->created_at])->saveQuietly();
            $old->delete();

            return $new;
        });
    }

    private static function allowed(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
