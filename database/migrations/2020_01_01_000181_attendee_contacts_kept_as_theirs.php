<?php

use App\Models\MatterMinutes;
use App\Models\Party;
use App\Models\Setting;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Minutes kept the phone and email typed for an attendee on the party they attended for as well — an
 * agent's or an employee's number on the company, on the matter's Contacts and its send screen. Now they
 * are the attendee's only; here, those copied before are taken off the party they attended for.
 *
 * A value is taken off only when it came there as a copy: it reached that party within seconds of
 * reaching the attendee's own (the parties' activity log says when), or — no log to go by — the attendee's
 * own party has it too. One the party had before stays. What is taken off is kept in a setting
 * (minutes_attendee_contacts_moved), to put back by hand should one have been the party's after all.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moved = [];

        MatterMinutes::query()->whereNotNull('attendees')->orderBy('id')->each(function (MatterMinutes $minutes) use (&$moved): void {
            foreach ((array) $minutes->attendees as $attendee) {
                if (! is_array($attendee) || blank($attendee['represents'] ?? null) || blank($attendee['party_id'] ?? null)
                    || (int) $attendee['represents'] === (int) $attendee['party_id']) {
                    continue;
                }

                $main = Party::find($attendee['represents']);
                $own = Party::find($attendee['party_id']);
                if (! $main || ! $own) {
                    continue;
                }

                foreach (['phone' => $attendee['phone'] ?? null, 'email' => $attendee['email'] ?? null] as $field => $value) {
                    $value = trim((string) $value);
                    if ($value === '' || ! $this->has($main, $field, $value) || ! $this->has($own, $field, $value)) {
                        continue;
                    }

                    $mainAt = $this->firstSeen($main, $field, $value);
                    $ownAt = $this->firstSeen($own, $field, $value);
                    $copied = $mainAt === null || $ownAt === null || abs($mainAt->diffInSeconds($ownAt)) <= 30;

                    if ($copied) {
                        $main->forceFill([$field => array_values(array_filter(
                            Party::values($main->{$field}),
                            fn (string $v): bool => $this->key($field, $v) !== $this->key($field, $value),
                        ))])->save();
                        $moved[] = ['party_id' => $main->getKey(), 'field' => $field, 'value' => $value, 'attendee_party_id' => $own->getKey(), 'minutes_id' => $minutes->getKey()];
                    }
                }
            }
        });

        if ($moved !== []) {
            Setting::set('minutes_attendee_contacts_moved', $moved, 'mail', 'json');
        }
    }

    public function down(): void
    {
        // Put back what was taken off.
        foreach ((array) Setting::get('minutes_attendee_contacts_moved', []) as $item) {
            $party = Party::find($item['party_id'] ?? null);
            if ($party) {
                $item['field'] === 'phone' ? $party->addContact($item['value'], null) : $party->addContact(null, $item['value']);
            }
        }
    }

    private function has(Party $party, string $field, string $value): bool
    {
        return collect(Party::values($party->{$field}))->contains(fn (string $v): bool => $this->key($field, $v) === $this->key($field, $value));
    }

    /** A phone by its digits (as WhatsApp reads it), an email whatever its case. */
    private function key(string $field, string $value): string
    {
        return $field === 'email'
            ? mb_strtolower(trim($value))
            : (WhatsAppService::formatWhatsAppNumber($value) ?? (preg_replace('/\D+/', '', $value) ?? ''));
    }

    /** When the value first reached the party, by its activity log — null when the log does not say. */
    private function firstSeen(Party $party, string $field, string $value): ?Carbon
    {
        $rows = DB::table('activity_log')
            ->where('subject_type', $party->getMorphClass())
            ->where('subject_id', $party->getKey())
            ->orderBy('id')
            ->get(['properties', 'attribute_changes', 'created_at']);

        foreach ($rows as $row) {
            $changes = json_decode((string) ($row->attribute_changes ?: $row->properties), true) ?: [];
            $new = Party::values($changes['attributes'][$field] ?? []);

            if (collect($new)->contains(fn (string $v): bool => $this->key($field, $v) === $this->key($field, $value))) {
                return Carbon::parse($row->created_at);
            }
        }

        return null;
    }
};
