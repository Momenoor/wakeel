<?php

namespace App\Console\Commands;

use App\Models\Party;
use App\Services\MMS\PartyMerger;
use Illuminate\Console\Command;

/**
 * The parties entered more than once — the same name however typed (PartyNames::key()), at the same level — as a
 * CSV to review and give back to parties:merge-plan: each group's party kept first ("keep": one with a user
 * account, else an English name, else the most phones and emails, else the oldest) with the name it will have
 * ("final_name": the fullest of theirs), the others "merge"; "skip"
 * where it may be two people of one name (a one-word name, or two user accounts) and "check" says why to look.
 */
class FindDuplicateParties extends Command
{
    protected $signature = 'parties:duplicates {file : Where to write the CSV}';

    protected $description = 'List the parties entered more than once as a CSV to review, for parties:merge-plan';

    public function handle(): int
    {
        $groups = Party::query()
            ->whereNotNull('name_key')->where('name_key', '!=', '')
            ->orderBy('id')
            ->get(['id', 'name', 'name_key', 'name_en', 'parent_id', 'phone', 'email', 'role', 'user_id', 'created_at'])
            ->groupBy(fn (Party $p): string => $p->name_key.'|'.($p->parent_id ?? ''))
            ->filter(fn ($group): bool => $group->count() > 1)
            ->sortBy(fn ($group): string => (string) $group->first()->name_key)
            ->values();

        $out = fopen((string) $this->argument('file'), 'w');
        fwrite($out, "\u{FEFF}");
        fputcsv($out, ['group', 'action', 'id', 'name', 'final_name', 'name_en', 'roles', 'belongs_to', 'phones', 'emails', 'user_account', 'created', 'check'], escape: '');

        foreach ($groups as $i => $group) {
            $keep = $group->sortBy(fn (Party $p): array => [blank($p->user_id), blank($p->name_en), -(count(Party::values($p->phone)) + count(Party::values($p->email))), $p->id])->first();
            $accounts = $group->pluck('user_id')->filter()->unique()->count();
            // Its words as written, a company's form among them: "سبيكون ش.ذ.م.م" is a company, "مدين" may be anyone.
            $words = $group->max(fn (Party $p): int => count(explode(' ', (string) $p->name)));
            $reasons = array_filter([$words <= 1 ? 'one-word name' : null, $accounts > 1 ? 'two user accounts' : null]);
            $action = $reasons === [] ? 'merge' : 'skip';
            // What the kept party is named after the merge: the fullest of theirs (to change here, on its row).
            $final = PartyMerger::fullest($group->pluck('name')->all());

            foreach ($group->sortBy(fn (Party $p): array => [$p->isNot($keep), $p->id]) as $party) {
                fputcsv($out, [
                    $i + 1,
                    $party->is($keep) ? 'keep' : $action,
                    $party->id,
                    $party->name,
                    $party->is($keep) ? $final : '',
                    $party->name_en,
                    $party->roleLabel(),
                    $party->parent_id,
                    implode(' / ', Party::values($party->phone)),
                    implode(' / ', Party::values($party->email)),
                    $party->user_id,
                    $party->created_at?->toDateString(),
                    $party->is($keep) ? implode('; ', $reasons) : '',
                ], escape: '');
            }
        }

        fclose($out);
        $this->info($groups->count().' groups of the same party, '.($groups->sum->count() - $groups->count()).' to merge into the one kept.');

        return self::SUCCESS;
    }
}
