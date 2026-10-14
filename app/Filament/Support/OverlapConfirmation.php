<?php

namespace App\Filament\Support;

use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

/**
 * A meeting made while another is still running asks first, in a danger
 * modal: "this meeting will be running during another one", with those
 * it overlaps, and Create anyway.
 *
 * The action is shown again as that question: on submit it finds the
 * overlap, keeps what was filled in (an argument of itself), and mounts
 * itself again with the question for its form; confirming runs it with
 * what was kept. configure() makes the action able to show either;
 * check() is what its handler asks first.
 */
class OverlapConfirmation
{
    /**
     * Call after the action's own schema, heading and width are set: they
     * are what it shows when it is not asking.
     */
    public static function configure(Action $action, string $heading, ?string $submitLabel = null, ?string $width = null): Action
    {
        // Its own form, as set (an array or a closure).
        $own = (fn () => $this->schema)->call($action);
        $asking = fn (array $arguments): bool => isset($arguments['overlap']);

        return $action
            ->schema(fn (array $arguments) => $asking($arguments)
                ? [Placeholder::make('overlap')->hiddenLabel()->content(fn () => new HtmlString(self::notice((array) $arguments['overlap'])))]
                : $action->evaluate($own))
            ->modalHeading(fn (array $arguments) => $asking($arguments) ? __('This meeting overlaps another one') : $heading)
            ->modalIcon(fn (array $arguments) => $asking($arguments) ? 'heroicon-o-exclamation-triangle' : null)
            ->modalIconColor('danger')
            ->modalSubmitActionLabel(fn (array $arguments) => $asking($arguments) ? __('Create it anyway') : $submitLabel)
            ->modalSubmitAction(fn (Action $submit, array $arguments) => $asking($arguments) ? $submit->color('danger') : $submit)
            ->modalWidth(fn (array $arguments) => $asking($arguments) ? 'lg' : $width);
    }

    /**
     * What the handler should act on: what was filled in — or, once the
     * question was answered yes, what was kept. Null when the question was
     * asked instead (the handler stops; the modal now asks).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function check(Action $action, array $data, ?CarbonInterface $start, ?CarbonInterface $end = null, ?int $exceptId = null): ?array
    {
        $kept = $action->getArguments()['overlap_data'] ?? null;

        if (is_array($kept)) {
            return $kept;
        }

        if (! $start) {
            return $data;
        }

        $clashes = SameDayMeetings::overlapping($start, $end, $exceptId);

        if ($clashes->isEmpty()) {
            return $data;
        }

        $livewire = $action->getLivewire();
        $livewire->replaceMountedAction(
            $action->getName(),
            [...$action->getArguments(), 'overlap_data' => $data, 'overlap' => SameDayMeetings::lines($clashes)],
            Arr::last($livewire->mountedActions)['context'] ?? [],
        );

        // Halted: replaced with the same name and context, it would be taken for the same and closed.
        $action->halt();
    }

    /**
     * @param  list<string>  $lines
     */
    private static function notice(array $lines): string
    {
        return '<div style="font-size: .9rem;"><p style="margin: 0 0 .5rem; font-weight: 600; color: rgb(220 38 38);">'
            .e(__('This meeting will be running during another one:')).'</p>'
            .'<ul style="margin: 0 0 .75rem; padding-inline-start: 1.1rem; list-style: disc;">'.collect($lines)->map(fn (string $line) => '<li>'.e($line).'</li>')->implode('').'</ul>'
            .'<p style="margin: 0;">'.e(__('Create it anyway?')).'</p></div>';
    }
}
