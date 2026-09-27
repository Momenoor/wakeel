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
     * In the same shape as a sender in config/mail_senders.php.
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
            'signature' => $this->signature,
        ];
    }
}
