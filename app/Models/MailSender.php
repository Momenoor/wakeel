<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A mailbox letters and bulk mail can be sent from: a cPanel mailbox over
 * SMTP (host, port, username, password), or a Microsoft 365 mailbox sent
 * through Microsoft Graph with the app registration in .env
 * (MICROSOFT_GRAPH_TENANT_ID / CLIENT_ID / CLIENT_SECRET) — no password.
 */
#[Fillable('key', 'name', 'address', 'driver', 'host', 'port', 'encryption', 'username', 'password', 'signature', 'is_active')]
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
        ];
    }

    /**
     * The sender as the mailer takes it.
     *
     * @return array<string, mixed>
     */
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
        ];
    }

    /**
     * For showing an email's HTML inside the app: the page's own styles put every
     * image on a line of its own (display: block), so the logos of a signature that
     * sit side by side in the email stacked in the preview. Inside .wk-mail they sit
     * as they do in a mail program. Also for the signature's editor (.wk-signature).
     */
    public static function previewCss(): string
    {
        return '<style>'
            .'.wk-mail img, .wk-signature .tiptap img { display: inline-block !important; vertical-align: middle; max-width: none; }'
            .'.wk-signature .tiptap [data-resize-container], .wk-signature .tiptap [data-resize-wrapper], .wk-signature .tiptap [data-resize-state] { display: inline-block !important; vertical-align: middle; }'
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
