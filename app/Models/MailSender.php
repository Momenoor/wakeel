<?php

namespace App\Models;

use App\Filament\Support\EmailSendFields;
use App\Services\MMS\MailboxFolderForwarder;
use App\Services\MMS\SenderMailer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mailbox letters and bulk mail can be sent from: a cPanel mailbox over
 * SMTP (host, port, username, password), or a Microsoft 365 mailbox sent
 * through Microsoft Graph with the app registration in .env
 * (MICROSOFT_GRAPH_TENANT_ID / CLIENT_ID / CLIENT_SECRET) — no password.
 */
#[Fillable('user_id', 'key', 'name', 'address', 'driver', 'host', 'port', 'encryption', 'username', 'password', 'signature', 'is_active', 'collects', 'sends', 'keep_all_inbox', 'files_forwards', 'inbox_folder', 'folders_only')]
class MailSender extends Model
{
    public const SMTP = 'smtp';

    public const MICROSOFT = 'microsoft';

    protected $hidden = ['password'];

    public function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'is_active' => 'boolean',
            'keep_all_inbox' => 'boolean',
            'files_forwards' => 'boolean',
            'collects' => 'boolean',
            'sends' => 'boolean',
            'folders_only' => 'boolean',
        ];
    }

    /**
     * The sender as the mailer takes it.
     *
     * @return array<string, mixed>
     */
    /** Whose own mailbox it is — none: one of the office's shared ones. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Its folders forwarded on, each to an address of its own (Settings → Mailbox folders). */
    public function folderForwards(): HasMany
    {
        return $this->hasMany(MailboxFolderForward::class);
    }

    /** The mailboxes are read once a request (SenderMailer::all()): a change, read again. */
    protected static function booted(): void
    {
        static::saved(fn () => SenderMailer::forget());
        static::deleted(fn () => SenderMailer::forget());
    }

    /**
     * A user's own mailbox kept as their work account says (Users → Edit): their Microsoft 365 account,
     * read and filed and/or sent from — on while either is; their signature, else the office's. The one place
     * it is made or changed: Settings → Mail senders only shows it.
     */
    public static function syncFor(User $user): void
    {
        $account = $user->msAccount();
        $sender = static::query()->firstWhere('user_id', $user->getKey());

        if ($account === null || ! ($user->mailbox_reads || $user->mailbox_sends)) {
            $sender?->update(['is_active' => false]);

            return;
        }

        $sender ??= new static(['user_id' => $user->getKey()]);
        $sender->fill([
            'key' => 'user-'.$user->getKey(),
            'name' => $user->display_name ?: $user->name,
            'address' => $account,
            'driver' => self::MICROSOFT,
            'is_active' => true,
            'collects' => $user->mailbox_reads,
            'sends' => $user->mailbox_sends,
            // What concerns a matter — or, as they chose, every email (the rest waiting for them alone).
            'keep_all_inbox' => $user->mailbox_reads && $user->mailbox_keeps_all,
            'signature' => filled(strip_tags((string) $user->mail_signature, '<img>'))
                ? $user->mail_signature
                : static::query()->whereNull('user_id')->where('key', Setting::get(EmailSendFields::DEFAULT_SENDER))->value('signature'),
        ])->save();
    }

    public function toSender(): array
    {
        return [
            'driver' => $this->driver,
            'name' => $this->name,
            'address' => $this->address,
            'username' => $this->username ?: $this->address,
            'password' => $this->password,
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption === 'none' ? null : $this->encryption,
            'signature' => self::leftToRight($this->signature),
            // Every email of this mailbox kept, not only those concerning a matter (MatterReplyCollector).
            'keep_all' => (bool) $this->keep_all_inbox,
            // The filing mailbox: what the office forwards to it, naming a matter, is filed as its original (ForwardedEmailFiler).
            'forwards' => (bool) ($this->files_forwards ?? false),
            // The folder it is read from over IMAP — one its own filter moves emails into; empty (or the module off): the Inbox (SentFolder::inbox()).
            'folder' => MailboxFolderForwarder::enabled() ? trim((string) $this->inbox_folder) : '',
            // A user's own (none: the office's shared one), and whether it is read and filed, and sent from.
            'owner' => $this->user_id !== null ? (int) $this->user_id : null,
            // A mailbox of Mailbox folders alone: never sent from nor shared — read for the matters' emails only
            // while the module is on.
            'folders_only' => (bool) $this->folders_only,
            'collects' => (bool) ($this->collects ?? true) && (! $this->folders_only || MailboxFolderForwarder::enabled()),
            'sends' => (bool) ($this->sends ?? true) && ! $this->folders_only,
        ];
    }

    /**
     * For showing an email's HTML inside the app: the page's own styles put every
     * image on a line of its own (display: block), so the logos of a signature that
     * sit side by side in the email stacked in the preview. Inside .wk-mail they sit
     * as they do in a mail program.
     */
    public static function previewCss(): string
    {
        return '<style>'
            .'.wk-mail img { display: inline-block !important; vertical-align: middle; max-width: none; }'
            .'</style>';
    }

    /**
     * A signature written left to right — and kept to the left — unless it says
     * its own direction as right to left: an office's signature is a card of
     * English lines and numbers (with the Arabic name in it), not a paragraph of
     * the email. In an Arabic email the text around it is right-aligned, which
     * a signature that says only dir="ltr" would inherit.
     */
    public static function leftToRight(?string $html): ?string
    {
        if (blank(strip_tags((string) $html, '<img>'))) {
            return $html;
        }

        // Its own outer element: a direction given, a side given?
        if (preg_match('/^(\s*<[a-z0-9]+\b)([^>]*)>/i', (string) $html, $m) && preg_match('/\sdir=/i', $m[2])) {
            if (! preg_match('/\sdir=["\']?ltr/i', $m[2]) || preg_match('/text-align\s*:/i', $m[2])) {
                return $html;
            }

            // Left to right, but not said to sit at the left: said.
            $attributes = preg_match('/\sstyle="([^"]*)"/i', $m[2])
                ? preg_replace('/\sstyle="([^"]*)"/i', ' style="text-align: left; $1"', $m[2], 1)
                : $m[2].' style="text-align: left;"';

            return $m[1].$attributes.'>'.substr((string) $html, strlen($m[0]));
        }

        return '<div dir="ltr" style="text-align: left;">'.$html.'</div>';
    }
}
