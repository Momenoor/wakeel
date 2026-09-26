<?php

namespace App\Models;

use App\Enums\BulkMailCampaignStatus;
use App\Services\MMS\BulkMailPlaceholders;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;

class BulkMailCampaign extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'subject',
        'body',
        'from_sender_key',
        'matter_id',
        'cc_emails',
        'bcc_emails',
        'has_attachment',
        'attachment_path',
        'attachment_disk',
        'daily_send_limit',
        'scheduled_at',
        'status',
        'placeholders',
        'total_recipients',
        'sent_count',
        'failed_count',
        'created_by',
    ];

    protected $casts = [
        'cc_emails' => 'array',
        'bcc_emails' => 'array',
        'placeholders' => 'array',
        'attachment_path' => 'array',
        'has_attachment' => 'boolean',
        'scheduled_at' => 'datetime',
        'status' => BulkMailCampaignStatus::class,
    ];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BulkMailRecipient::class, 'campaign_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BulkMailLog::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function senderConfig(): Attribute
    {
        return new Attribute(
            get: fn () => Config::get("mail_senders.senders.{$this->from_sender_key}")
        );
    }

    public function getRemainingDailyLimit(): int
    {
        $sentToday = $this->recipients()
            ->where('status', 'sent')
            ->whereDate('sent_at', now()->toDateString())
            ->count();

        return max(0, $this->daily_send_limit - $sentToday);
    }

    /**
     * The matter this campaign is about — its details fill the {{matter.*}}
     * placeholders. Null for a general mailing.
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function renderBody(BulkMailRecipient $recipient, array $recipientPlaceholders = []): string
    {
        $body = BulkMailPlaceholders::apply(
            (string) $this->body,
            [...BulkMailPlaceholders::for($this, $recipient), ...$recipientPlaceholders],
            escape: true,
        );

        $sender = $this->sender_config;
        if ($sender && isset($sender['signature'])) {
            $body .= '<br><br>'.$sender['signature'];
        }

        return $body;
    }

    public function renderSubject(BulkMailRecipient $recipient, array $recipientPlaceholders = []): string
    {
        return BulkMailPlaceholders::apply(
            (string) $this->subject,
            [...BulkMailPlaceholders::for($this, $recipient), ...$recipientPlaceholders],
        );
    }
}
