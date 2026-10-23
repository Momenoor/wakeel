<?php

namespace App\Http\Controllers;

use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use Illuminate\Http\Request;

class BulkMailController extends Controller
{
    /**
     * One recipient's email of a campaign, as it was (or will be) sent — for whoever may see the campaign, and
     * only a recipient of that campaign (as its PDF, bulk-mail.pdf).
     */
    public function __invoke(Request $request, BulkMailCampaign $campaign, BulkMailRecipient $recipient)
    {
        abort_unless($recipient->campaign?->is($campaign) && $request->user()->can('view', $campaign), 403);

        $subject = $campaign->renderSubject($recipient);
        $html = $campaign->renderBody($recipient);
        $sender = $campaign->sender_config;
        $sentAt = $recipient->sent_at ?? now();
        $attachments = $campaign->attachmentsFor($recipient);
        $cc = array_merge($campaign->cc_emails ?? [], $recipient->cc_emails ?? []);
        $bcc = $campaign->bcc_emails ?? [];

        return view('mails.bulk-mail-preview', compact(
            'subject', 'html', 'sender', 'recipient',
            'sentAt', 'attachments', 'cc', 'bcc', 'campaign'
        ));
    }
}
