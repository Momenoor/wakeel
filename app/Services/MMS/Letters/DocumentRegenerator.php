<?php

namespace App\Services\MMS\Letters;

use App\Models\DocumentRegeneration;
use App\Models\MatterLetter;
use App\Models\MatterMinutes;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A letter or minutes made again from their template's CURRENT wording and format — after the template was
 * changed — with their data as it is: a letter's filled-in fields, recipients, date, reference and attention
 * line; minutes' attendees, questions and answers, opening and closing. Only the copy of the wording each keeps
 * is replaced (and, when asked, the letterhead by the template's), then it is rendered again; finalised minutes
 * get their PDF made again. What it was is kept (DocumentRegeneration), and undo() puts it back.
 *
 * Copies already emailed or sent stay as they were sent.
 */
class DocumentRegenerator
{
    /** Whether this user may do it: a super admin only. */
    public static function allowed(?User $user): bool
    {
        return (bool) $user?->isSuperAdmin();
    }

    public function letter(MatterLetter $letter, bool $templateLetterhead = false, ?int $userId = null): MatterLetter
    {
        $template = $letter->template ?? throw new RuntimeException(__('This letter has no template to make it again from.'));

        return DB::transaction(function () use ($letter, $template, $templateLetterhead, $userId): MatterLetter {
            self::remember($letter, $template->getKey(), $userId, $letter->only(['body', 'subject', 'rendered_html', 'letterhead_id', 'locale']));

            $letter->update([
                'body' => SignatureLayouts::freeze(LetterComposer::normalizeMergeTags((string) $template->body)),
                'locale' => $template->locale ?: $letter->locale,
                ...($templateLetterhead && $template->letterhead_id ? ['letterhead_id' => $template->letterhead_id] : []),
            ]);

            return $this->renderLetter($letter);
        });
    }

    public function minutes(MatterMinutes $minutes, bool $templateLetterhead = false, ?int $userId = null): MatterMinutes
    {
        $template = $minutes->template ?? throw new RuntimeException(__('These minutes have no template to make them again from.'));

        return DB::transaction(function () use ($minutes, $template, $templateLetterhead, $userId): MatterMinutes {
            self::remember($minutes, $template->getKey(), $userId, $minutes->only(['body', 'letterhead_id']));

            $minutes->update([
                'body' => SignatureLayouts::freeze(LetterComposer::normalizeMergeTags((string) $template->body)),
                ...($templateLetterhead && $template->letterhead_id ? ['letterhead_id' => $template->letterhead_id] : []),
            ]);

            return $this->renderMinutes($minutes, $userId);
        });
    }

    /** The last regeneration of a document not undone, if any. */
    public static function last(MatterLetter|MatterMinutes $document): ?DocumentRegeneration
    {
        return DocumentRegeneration::query()
            ->where('document_type', $document::class)
            ->where('document_id', $document->getKey())
            ->whereNull('undone_at')
            ->latest('id')
            ->first();
    }

    /** The document put back as it was before its last regeneration. */
    public function undo(MatterLetter|MatterMinutes $document, ?int $userId = null): MatterLetter|MatterMinutes
    {
        $regeneration = self::last($document) ?? throw new RuntimeException(__('There is nothing to undo.'));

        return DB::transaction(function () use ($document, $regeneration, $userId): MatterLetter|MatterMinutes {
            $document->update($regeneration->previous);
            $regeneration->update(['undone_at' => now()]);

            return $document instanceof MatterLetter ? $document->fresh() : $this->renderMinutes($document, $userId);
        });
    }

    private static function remember(MatterLetter|MatterMinutes $document, ?int $templateId, ?int $userId, array $previous): void
    {
        DocumentRegeneration::create([
            'document_type' => $document::class,
            'document_id' => $document->getKey(),
            'letter_template_id' => $templateId,
            'user_id' => $userId,
            'previous' => $previous,
        ]);
    }

    /** A letter's text and subject as its new wording gives them, from its own data. */
    private function renderLetter(MatterLetter $letter): MatterLetter
    {
        $letter = $letter->fresh(['template', 'matter', 'letterhead', 'recipients']);
        $composer = LetterIssuer::composerFor($letter);
        $letter->update(['subject' => $composer->subject(), 'rendered_html' => $composer->bodyHtml()]);

        return $letter->fresh();
    }

    /** Finalised minutes: their PDF made again, in place of the old one. Drafts follow their template anyway. */
    private function renderMinutes(MatterMinutes $minutes, ?int $userId): MatterMinutes
    {
        $minutes = $minutes->fresh(['template', 'matter', 'letterhead', 'attachment']);

        if ($minutes->isFinal()) {
            MinutesService::storePdf($minutes, $userId);
        }

        return $minutes->fresh();
    }
}
