<?php

namespace App\Services\MMS;

use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterFieldDefinition;
use App\Models\Setting;
use App\Models\Type;
use Illuminate\Support\Facades\DB;

/**
 * A custom field that held the phase of a matter (a dropdown: first, second, third) made the phases of its types
 * instead — once, from the matter types' list: its options their phases, in the order given and named as given; each
 * matter's value its phase; {{matter.custom.<field>}} in the folders and letters {{matter.phase}}; the field taken off
 * its types. The values stay in the matters' custom fields, untouched.
 */
final class MatterPhases
{
    /** The settings a folder structure or name is kept in (Settings → OneDrive Folders). */
    public static function folderSettings(): array
    {
        return [
            MatterOneDriveFolders::SUBFOLDERS, MatterOneDriveFolders::SIGNED_MINUTES, MatterOneDriveFolders::SENT_EMAILS, MatterOneDriveFolders::RECEIVED_EMAILS,
            ...array_values(ReceivedEmailFiling::FOLDER_SETTINGS), ...array_values(ReceivedEmailFiling::NAME_SETTINGS), PeriodicReports::SETTING,
        ];
    }

    /**
     * The field's options — key and name — as they are offered to be made phases, in their order.
     *
     * @return list<array{option: string, phase: string}>
     */
    public static function optionsOf(MatterFieldDefinition $field): array
    {
        return collect((array) $field->options)
            ->map(fn ($label, $key): array => ['option' => (string) $key, 'phase' => trim((string) $label) !== '' ? trim((string) $label) : (string) $key])
            ->values()
            ->all();
    }

    /**
     * The field made the phases of every type it is on. How many types and matters.
     *
     * @param  list<array{option: string, phase: string}>  $phases  each option's phase, in the phases' order
     * @return array{types: int, matters: int}
     */
    public static function fromField(MatterFieldDefinition $field, array $phases): array
    {
        $phases = array_values(array_filter($phases, fn (array $row): bool => trim((string) ($row['phase'] ?? '')) !== ''));
        $names = array_values(array_unique(array_map(fn (array $row): string => trim((string) $row['phase']), $phases)));
        // A value as matters keep it — the option's key, any case, or its name — to its phase.
        $phaseOf = [];
        foreach ($phases as $row) {
            foreach ([(string) $row['option'], (string) (((array) $field->options)[$row['option']] ?? '')] as $value) {
                if (trim($value) !== '') {
                    $phaseOf[mb_strtolower(trim($value))] = trim((string) $row['phase']);
                }
            }
        }

        return DB::transaction(function () use ($field, $names, $phaseOf): array {
            $types = $field->types()->pluck('types.id')->all();
            Type::query()->whereKey($types)->update(['has_phases' => true, 'phases' => json_encode($names, JSON_UNESCAPED_UNICODE)]);

            // Each phase's matters in one go — not one save a matter (its custom fields stay as they are).
            $matters = Matter::withTrashed()->whereIn('type_id', $types)->whereNotNull('custom_fields')->get(['id', 'custom_fields'])->toBase()
                ->groupBy(fn (Matter $matter): string => $phaseOf[mb_strtolower(trim((string) (($matter->custom_fields ?? [])[$field->label] ?? '')))] ?? '')
                ->except(['']);
            foreach ($matters as $phase => $inPhase) {
                Matter::withTrashed()->whereKey($inPhase->map(fn (Matter $matter): int => (int) $matter->getKey())->all())->toBase()->update(['phase' => $phase]);
            }

            // {{matter.custom.<field>}} — however spaced — is {{matter.phase}} now: in every folder and letter.
            $pattern = '/\{\{\s*matter\.custom\.'.preg_quote($field->label, '/').'\s*\}\}/u';
            $replace = fn (?string $text): ?string => $text === null ? null : preg_replace($pattern, '{{matter.phase}}', $text);

            foreach (Type::query()->get(['id', ...Type::FOLDER_FIELDS]) as $type) {
                $type->forceFill(collect(Type::FOLDER_FIELDS)->mapWithKeys(fn (string $column): array => [$column => $replace($type->{$column})])->all())->saveQuietly();
            }
            foreach (self::folderSettings() as $key) {
                if (($value = Setting::get($key)) !== null && is_string($value) && preg_match($pattern, $value)) {
                    Setting::set($key, $replace($value), 'onedrive');
                }
            }
            LetterTemplate::query()->where(fn ($q) => $q->where('body', 'like', '%matter.custom.%')->orWhere('subject', 'like', '%matter.custom.%'))->get()
                ->each(fn (LetterTemplate $template) => $template->update(['subject' => $replace($template->subject), 'body' => $replace($template->body)]));

            $field->types()->detach();

            return ['types' => count($types), 'matters' => $matters->sum(fn ($inPhase): int => $inPhase->count())];
        });
    }
}
