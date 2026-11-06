<?php

namespace App\Filament\Support;

use App\Filament\Mms\Resources\Types\Schemas\TypeForm;
use App\Services\MMS\PeriodicReports;
use App\Services\MMS\ReceivedEmailFiling;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Where a matter's emails and files are saved in its OneDrive folder, and under what name — the settings (Settings →
 * OneDrive Folders) and a matter type's own, laid out alike: grouped, each row a folder and its file name side by
 * side (one with none in its own column only), a short line on top of each section and the placeholders said once,
 * the inputs written in the interface's direction (dir()).
 */
final class FolderSettingsLayout
{
    /**
     * The sections: received emails (a group a side: its emails' folder and name, its files' folder), sent emails,
     * and the other folders.
     *
     * @param  TextInput  $receivedEmails  the general received emails folder (the setting's, or the type's)
     * @param  TextInput  $sentEmails  the sent emails folder
     * @param  TextInput  $signedMinutes  the signed minutes folder
     * @return list<Section>
     */
    public static function sections(bool $forType, TextInput $receivedEmails, TextInput $sentEmails, TextInput $signedMinutes): array
    {
        $received = [];
        foreach ([ReceivedEmailFiling::GENERAL, ...ReceivedEmailFiling::APART] as $side) {
            $creditor = $side === ReceivedEmailFiling::CREDITOR;
            $received[] = Fieldset::make(match ($side) {
                ReceivedEmailFiling::PLAINTIFF => __('The plaintiff side'),
                ReceivedEmailFiling::DEFENDANT => __('The defendant side'),
                ReceivedEmailFiling::CREDITOR => __('Creditors'),
                default => __('Anyone else'),
            })
                ->columns(['default' => 1, 'md' => 2])
                // A type's creditors' only for a type that has them.
                ->visible(fn (Get $get): bool => ! $forType || ! $creditor || (bool) $get('has_creditors'))
                ->schema([
                    ...self::row($side === ReceivedEmailFiling::GENERAL ? $receivedEmails : TypeForm::receivedSideFolderField($side, $forType), TypeForm::receivedNameField($side, $forType)),
                    ...self::row(TypeForm::attachmentsFolderField($side, $forType), null),
                ]);
        }

        $empty = $forType ? __('Leave a field empty for the setting (Settings → OneDrive Folders).') : __('Leave a side\'s field empty to use the general one; leave the general one empty not to save them in OneDrive.');

        return [
            Section::make(__('Received emails'))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->description(new HtmlString(
                    e(__('Where each sender\'s emails, and the files they came with, are saved inside the matter\'s folder, and the name of each email\'s PDF. Folders are separated by "/".')).' '.e($empty)
                    .self::legend()
                ))
                ->columnSpanFull()
                ->schema($received),
            Section::make(__('Sent emails'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->description(__('Where the emails sent from the matter are saved: all of them, those to a creditor, and the first mails to creditors with their name.').' '.($forType ? $empty : __('Leave the creditors\' ones empty to save them with the other sent emails.')))
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    ...self::row($sentEmails, null),
                    ...self::row(TypeForm::sentToCreditorsField($forType), null),
                    ...self::row(TypeForm::firstToCreditorsField($forType), TypeForm::firstMailNameField($forType)),
                ]),
            Section::make(__('Signed minutes and periodic reports'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->description(__('Where the signed minutes are saved, and where a periodic report\'s files are moved.').($forType ? ' '.$empty : ''))
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    ...self::row($signedMinutes, null),
                    ...self::row(TypeForm::periodicReportsField($forType ? PeriodicReports::COLUMN : PeriodicReports::SETTING, $forType), null),
                ]),
        ];
    }

    /**
     * The way a folder is written and shown: the interface's — right to left in Arabic, left to right in English — whatever
     * the language of the folder's own name, so every path reads alike.
     */
    public static function dir(): string
    {
        return __('filament-panels::layout.direction') === 'rtl' ? 'rtl' : 'ltr';
    }

    /**
     * A row: a folder in the first column and its file name in the second — one missing leaves its column empty.
     * Nothing under them (the section says what they are); written in the interface's direction (dir()).
     *
     * @return list<TextInput>
     */
    private static function row(?TextInput $folder, ?TextInput $name): array
    {
        $row = [];

        foreach ([1 => $folder, 2 => $name] as $column => $field) {
            if ($field !== null) {
                $row[] = $field->helperText(null)
                    ->columnStart(['default' => 1, 'md' => $column])
                    ->extraInputAttributes(['dir' => self::dir(), 'style' => 'text-align: start']);
            }
        }

        return $row;
    }

    /** Every placeholder the folders and names take, in one list. */
    private static function legend(): string
    {
        $placeholders = [
            '{{matter.number}} {{matter.phase}} {{matter.custom.…}}' => __('the matter\'s details (cut short: {{matter.plaintiffs,c:30}}, {{matter.plaintiffs,w:3}})'),
            '{{day}} {{month}} {{year}}' => __('the day it came or was sent'),
            '{{serial}} {{serial.2}}' => __('the email\'s number under its folder: 1, 2, 3 — or 01, 02, 03, an email and its replies together'),
            '{{side}} {{party}}' => __('the side\'s name, and who sent it'),
            '{{creditor}} {{creditor.number}}' => __('the creditor\'s name, and its number (001)'),
            '{{date}} {{time}} {{kind}} {{subject}}' => __('in a file name: when, incoming or reply, and what about'),
            '{{name}} {{email}}' => __('in a first mail\'s name: whom it went to, and their address'),
            '{{number}}' => __('in a periodic report\'s folder: the report\'s number'),
        ];

        // Lines, not a list: the description is a paragraph.
        return '<span style="display: block; margin-top: .5rem;"></span>'.collect($placeholders)
            ->map(fn (string $meaning, string $codes): string => '<span style="display: block; margin-top: .2rem;"><code dir="ltr">'.e($codes).'</code> — '.e($meaning).'</span>')
            ->implode('');
    }
}
