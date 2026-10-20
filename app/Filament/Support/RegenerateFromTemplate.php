<?php

namespace App\Filament\Support;

use App\Models\MatterLetter;
use App\Models\MatterMinutes;
use App\Services\MMS\Letters\DocumentRegenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Throwable;

/**
 * "Regenerate from template" on a letter or minutes, for a super admin: after its template was changed, the
 * document takes the template's current wording and format, its data kept (DocumentRegenerator); and "Undo
 * regeneration" to put back what it was.
 *
 *   ->recordActions([..., RegenerateFromTemplate::action(), RegenerateFromTemplate::undoAction()])
 */
final class RegenerateFromTemplate
{
    public static function action(): Action
    {
        return Action::make('regenerateFromTemplate')
            ->label(__('Regenerate from template'))
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('warning')
            ->visible(fn (MatterLetter|MatterMinutes $record): bool => DocumentRegenerator::allowed(auth()->user()) && $record->template !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (MatterLetter|MatterMinutes $record): string => __('Regenerate from ":template"', ['template' => $record->template?->name]))
            ->modalDescription(fn (MatterLetter|MatterMinutes $record): string => $record instanceof MatterLetter
                ? __('The letter takes the template\'s current wording and format. Its data stays as it is: the filled-in fields, recipients, date, reference and attention line. Any change made to this letter\'s own wording is replaced. Copies already sent stay as they were sent. You can undo it.')
                : __('The minutes take the template\'s current wording and format. Their data stays as it is: attendees, questions and answers, opening and closing. Finalised minutes get their PDF made again. Copies already sent stay as they were sent. You can undo it.'))
            ->schema([
                Toggle::make('template_letterhead')
                    ->label(__('Use the template\'s letterhead too'))
                    ->helperText(__('Off: the letterhead it has now is kept.'))
                    ->default(false),
            ])
            ->modalSubmitActionLabel(__('Regenerate'))
            ->action(function (MatterLetter|MatterMinutes $record, array $data): void {
                $regenerator = app(DocumentRegenerator::class);

                try {
                    $record instanceof MatterLetter
                        ? $regenerator->letter($record, (bool) ($data['template_letterhead'] ?? false), auth()->id())
                        : $regenerator->minutes($record, (bool) ($data['template_letterhead'] ?? false), auth()->id());
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title(__('It could not be regenerated'))->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('Regenerated from the template'))->body(__('Its data is as it was. Undo is in the same menu.'))->send();
            });
    }

    public static function undoAction(): Action
    {
        return Action::make('undoRegeneration')
            ->label(__('Undo regeneration'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (MatterLetter|MatterMinutes $record): bool => DocumentRegenerator::allowed(auth()->user()) && DocumentRegenerator::last($record) !== null)
            ->requiresConfirmation()
            ->modalDescription(__('It goes back to the wording and format it had before it was regenerated.'))
            ->action(function (MatterLetter|MatterMinutes $record): void {
                app(DocumentRegenerator::class)->undo($record, auth()->id());
                Notification::make()->success()->title(__('Put back as it was'))->send();
            });
    }
}
