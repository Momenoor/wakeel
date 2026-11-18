<?php

namespace App\Services\MMS;

use App\Models\Matter;
use App\Models\MatterEmail;
use App\Support\Addresses;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A matter's emails already in OneDrive put where, and named as, its type's email folders and names say now
 * (Matter types → Edit → "Apply to existing matters", RefileTypeEmails): each worked out as a new one would be —
 * a received email by its sender's side (general, plaintiff, defendant, creditor) into that side's received-emails
 * folder, under that side's name, the files it came with into that side's attachments folder; a sent one into the
 * sent-emails folder; a first mail to a creditor into the first mails folder, under the first mails' name — its
 * PDF, original and the folder of its files moved and renamed together, and its record with them
 * (MatterOneDriveFolders::moveEmailWithin()). One where it should be already, named as it should be, is left
 * alone — so it can be run again. An answer in a numbered folder ({{serial}}) goes beside the email it answers.
 *
 * Left as they are: emails found in OneDrive (put there by hand), those moved into a periodic report's folder, and
 * the copies saved in a sender's other capacities' folders. Sent emails keep their names — they have no name of
 * their own to set.
 */
class ExistingEmailsRefiling
{
    public function __construct(private readonly MatterOneDriveFolders $folders) {}

    /**
     * @return array{moved: int, renamed: int, failed: int}
     */
    public function refile(Matter $matter): array
    {
        $done = ['moved' => 0, 'renamed' => 0, 'failed' => 0];

        if (MatterOneDriveFolders::folderFor($matter) === null) {
            return $done;
        }

        $emails = $matter->emails()->with('parent')->orderBy('at')->orderBy('id')->get()
            ->filter(fn (MatterEmail $email): bool => $email->matched_by !== MatterEmail::BY_ONEDRIVE
                && (((array) $email->onedrive)['report'] ?? null) === null
                && filled(((array) $email->onedrive)['folder'] ?? null)
                && pathinfo($email->pdfName(), PATHINFO_FILENAME) !== '');

        // Each address's side and name, asked once.
        $sides = [];
        $parties = [];

        foreach ($emails as $email) {
            try {
                $plan = $this->plan($email, $matter, $sides, $parties);

                if ($plan === null) {
                    continue;
                }

                [$target, $name, $apart, $folderMoves] = $plan;

                if ($this->folders->moveEmailWithin($email, $matter, $target, [], $apart, $name)) {
                    $done[$folderMoves ? 'moved' : 'renamed']++;
                }
            } catch (Throwable $e) {
                Log::warning('An email was not refiled', ['email' => $email->getKey(), 'error' => $e->getMessage()]);
                $done['failed']++;
            }
        }

        return $done;
    }

    /**
     * Where the email goes and under what name: [folder, name or null to keep it, attachments folder or null to
     * leave them, whether it changes folder] — null when it is where, and named as, it should be.
     *
     * @param  array<string, string>  $sides
     * @param  array<string, string>  $parties
     * @return array{0: string, 1: ?string, 2: ?string, 3: bool}|null
     */
    private function plan(MatterEmail $email, Matter $matter, array &$sides, array &$parties): ?array
    {
        $at = $email->at ?? $email->created_at;
        $where = MatterOneDriveFolders::emailFolder($email, $matter);
        $base = (string) pathinfo($email->pdfName(), PATHINFO_FILENAME);
        $apartWanted = null;

        if ($email->first_mail) {
            $under = MatterOneDriveFolders::firstToCreditorsFolderFor($matter, $at);
            $next = fn (): string => $under;
            $name = CreditorFirstMails::name($matter, CreditorFirstMails::whom($email), (string) $email->subject, $at, (string) (((array) $email->to)[0] ?? ''));
        } elseif ($email->direction === MatterEmail::RECEIVED) {
            $from = MatterEmail::addressOf((string) $email->from);
            $side = $sides[$from] ??= ReceivedEmailFiling::side($matter, $from);
            $party = $parties[$from] ??= MatterReplyCollector::partyName($from) ?? (trim(Str::before((string) $email->from, '<')) ?: $from);
            // Without the number ({{serial}}): one already under it stays in its numbered folder.
            $under = MatterOneDriveFolders::receivedEmailsFolderFor($matter, $at, $side, $from, nextSerial: false);
            $thread = MatterOneDriveFolders::threadFolder($email->parent, $matter, $under);
            $next = fn (): string => $thread ?? MatterOneDriveFolders::receivedEmailsFolderFor($matter, $at, $side, $from);
            $name = ReceivedEmailFiling::name($matter, $side, $party, (string) $email->subject, $at, $email->parent_id !== null, $from);
            $apartWanted = $thread !== null && str_contains(ReceivedEmailFiling::attachmentsTemplate($matter, $side), '{{serial')
                ? $thread
                : MatterOneDriveFolders::attachmentsFolderFor($matter, $at, $side, $from, $party);
        } else {
            $to = Addresses::emails([...(array) $email->to, ...(array) $email->cc]);
            $under = MatterOneDriveFolders::sentEmailsFolderFor($matter, $at, $to, nextSerial: false);
            $thread = MatterOneDriveFolders::threadFolder($email->parent, $matter, $under);
            $next = fn (): string => $thread ?? MatterOneDriveFolders::sentEmailsFolderFor($matter, $at, $to);
            // A sent email's name has no setting: kept.
            $name = null;
        }

        if ($under === '') {
            return null;
        }

        $folderOk = str_starts_with(mb_strtolower($where).'/', mb_strtolower($under).'/');
        $nameOk = $name === null || mb_strtolower(MatterOneDriveFolders::clean($name)) === mb_strtolower(MatterOneDriveFolders::clean($base));
        $currentApart = (string) (((array) $email->onedrive)['attachments_folder'] ?? '');
        $apart = $apartWanted !== null && mb_strtolower($currentApart) !== mb_strtolower($apartWanted) && $currentApart !== '' ? $apartWanted : null;

        if ($folderOk && $nameOk && $apart === null) {
            return null;
        }

        return [$folderOk ? $where : $next(), $nameOk ? null : $name, $apart, ! $folderOk];
    }
}
