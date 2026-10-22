<?php

namespace App\Models;

use App\Models\Concerns\AnnouncesChanges;
use App\Services\MMS\MatterEmailSentNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Throwable;

/**
 * An email of a matter: sent from it (a letter, minutes, bulk mail), or one
 * collected from the mailbox (MatterReplyCollector) — a reply to one, or any
 * other email from one of its parties. An incoming email whose matter is not
 * certain waits (PENDING, its candidates) for a person to choose.
 */
class MatterEmail extends Model
{
    use AnnouncesChanges;
    use LogsActivity;

    /** Sent, received, filed or moved: on the matter's history — not what is kept of it in OneDrive. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['matter_id', 'direction', 'subject'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    public const SENT = 'sent';

    public const RECEIVED = 'received';

    /** An incoming email waiting for a person to choose its matter. */
    public const PENDING = 'pending';

    public const FILED = 'filed';

    /** Not about any matter, a person said. */
    public const DISMISSED = 'dismissed';

    /** A file entry that is the original email (.eml), not one it came with. */
    public const SOURCE = 'source';

    /** How an incoming email's matter was known. */
    public const BY_SUBJECT = 'subject_reference';

    public const BY_BODY = 'body_reference';

    public const BY_PARTY_NAME = 'party_name';

    public const BY_ADDRESS = 'address';

    public const BY_HAND = 'hand';

    protected $fillable = ['matter_id', 'direction', 'status', 'parent_id', 'source_type', 'source_id', 'sender_key', 'message_id', 'subject', 'from', 'to', 'cc', 'files', 'onedrive', 'candidates', 'matched_by', 'at', 'user_id', 'was_unread'];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'files' => 'array',
            'onedrive' => 'array',
            'candidates' => 'array',
            'at' => 'datetime',
            'was_unread' => 'boolean',
        ];
    }

    /**
     * An email sent — from a matter, or a bulk mail campaign with none —
     * remembered for its replies to be found. Never stops the send it
     * records.
     *
     * @param  list<string>  $to  everyone it went to, copied in too
     */
    public static function recordSent(?int $matterId, ?Model $source, string $senderKey, ?string $messageId, string $subject, array $to, ?int $userId): ?self
    {
        try {
            return tap(static::create([
                'matter_id' => $matterId,
                'direction' => self::SENT,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'sender_key' => $senderKey,
                'message_id' => self::messageId($messageId),
                'subject' => mb_substr($subject, 0, 500),
                'to' => array_values(array_unique(array_map('mb_strtolower', array_filter($to)))),
                'at' => now(),
                'user_id' => $userId,
                // The matter's people told — once the request is done.
            ]), fn (self $email) => MatterEmailSentNotifier::note($email));
        } catch (Throwable $e) {
            Log::warning('Matter email not recorded: '.$e->getMessage());

            return null;
        }
    }

    /**
     * What was kept of an email that was sent: its PDF (where, and whether it is in the matter's
     * OneDrive) and how many files it went with.
     *
     * @param  array{name: string, path: ?string, inOneDrive: bool, folder?: ?string}  $pdf
     */
    public function noteKept(array $pdf, int $attachmentsSent): void
    {
        try {
            $this->update([
                'files' => filled($pdf['path'] ?? null) ? [['name' => $pdf['name'], 'path' => $pdf['path']]] : [],
                'onedrive' => ['pdf' => (bool) ($pdf['inOneDrive'] ?? false), 'sent_attachments' => $attachmentsSent, 'folder' => $pdf['folder'] ?? null, 'checked_at' => now()->toDateTimeString()],
            ]);
        } catch (Throwable $e) {
            Log::warning('What was kept of a sent email was not noted: '.$e->getMessage());
        }
    }

    /**
     * The files an email came with: not its PDF (the first kept) nor its original (.eml).
     *
     * @return list<array<string, mixed>>
     */
    public function attachmentFiles(): array
    {
        return collect((array) ($this->files ?? []))
            ->skip(1)
            ->filter(fn ($file): bool => ($file['kind'] ?? null) !== self::SOURCE)
            ->values()
            ->all();
    }

    /**
     * The link to a file kept in the system (the public disk): each part of its path encoded, so
     * a file named with a "?", "#" or "%" in it opens instead of being cut at that character.
     */
    public static function fileUrl(string $path): string
    {
        $disk = Storage::disk('public');
        $plain = $disk->url('x');

        return substr($plain, 0, -1).implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Whether the email's PDF (`pdf`) or its original (`eml`) is in the matter's
     * OneDrive — null when that was never checked.
     */
    public function inOneDrive(string $what): ?bool
    {
        $value = ((array) $this->onedrive)[$what] ?? null;

        return $value === null ? null : (bool) $value;
    }

    /** How many of the files it came with are in the matter's OneDrive — null when never checked. */
    public function attachmentsInOneDrive(): ?int
    {
        $value = ((array) $this->onedrive)['attachments'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * What became of the files it came with: `none` (it came with none), `saved`,
     * `partly`, `not_saved` in OneDrive, or `unknown` when never checked.
     */
    public function attachmentsState(): string
    {
        $has = count($this->attachmentFiles());
        $in = $this->attachmentsInOneDrive();

        return match (true) {
            $has === 0 => 'none',
            $in === null => 'unknown',
            $in >= $has => 'saved',
            $in > 0 => 'partly',
            default => 'not_saved',
        };
    }

    /**
     * What to say of an email that came in as to its OneDrive copies — `pdf`, `eml` or
     * `attachments`: Saved / Not saved (and, for the files it came with, Without
     * attachment or Partly saved); Not checked when never read from OneDrive. Null
     * for one that went out.
     */
    public function oneDriveLabel(string $what): ?string
    {
        // An email that went out: its PDF is kept, and it went with some files or none — which are in
        // the PDF's list of them, not kept apart. It has no original (.eml).
        if ($this->direction === self::SENT) {
            if ($what === 'eml') {
                return null;
            }

            if ($what === 'attachments') {
                $sent = ((array) $this->onedrive)['sent_attachments'] ?? null;

                return $sent === null
                    ? __('Not checked')
                    : ((int) $sent > 0 ? trans_choice(':count attachment|:count attachments', (int) $sent, ['count' => (int) $sent]) : __('Without attachment'));
            }
        }

        if ($what === 'attachments') {
            return match ($this->attachmentsState()) {
                'none' => __('Without attachment'),
                'saved' => __('Saved'),
                'partly' => __('Partly saved'),
                'not_saved' => __('Not saved'),
                default => __('Not checked'),
            };
        }

        return match ($this->inOneDrive($what)) {
            true => __('Saved'),
            false => __('Not saved'),
            default => __('Not checked'),
        };
    }

    public static function matchedByLabel(?string $how): string
    {
        return match ($how) {
            self::BY_SUBJECT => __('the matter number in its subject'),
            self::BY_BODY => __('the matter number in its text'),
            self::BY_PARTY_NAME => __("a party's name in it"),
            self::BY_ADDRESS => __("the sender's only matter"),
            self::BY_HAND => __('chosen by hand'),
            default => '—',
        };
    }

    /**
     * Incoming emails waiting for a person to choose their matter — those
     * that may be about one of these matters, when given.
     *
     * @param  list<int>|null  $matterIds
     * @param  list<string>  $mailboxes  and those waiting in these mailboxes, whatever they may be about (one's own)
     * @return Builder<self>
     */
    public static function pending(?array $matterIds = null, array $mailboxes = []): Builder
    {
        return static::query()
            ->where('direction', self::RECEIVED)
            ->where('status', self::PENDING)
            ->when($matterIds !== null, fn (Builder $q) => $q->where(function (Builder $q) use ($matterIds, $mailboxes) {
                $q->whereRaw('1 = 0');
                foreach ($matterIds as $id) {
                    $q->orWhereJsonContains('candidates', (int) $id);
                }
                if ($mailboxes !== []) {
                    $q->orWhereIn('sender_key', $mailboxes);
                }
            }));
    }

    /** The address in a "from" as kept: "Name <a@b>" or "a@b". */
    public static function addressOf(string $from): string
    {
        return mb_strtolower(trim(preg_match('/<([^<>]+)>\s*$/', $from, $m) ? $m[1] : $from));
    }

    /** "<abc@host>" and "abc@host" alike. */
    public static function messageId(?string $id): ?string
    {
        $id = trim((string) $id, " <>\t\r\n");

        return $id === '' ? null : mb_substr($id, 0, 500);
    }

    /**
     * The replies to what went to a bulk mail recipient, newest first.
     *
     * @return Builder<self>
     */
    public static function repliesTo(Model $source): Builder
    {
        return static::query()
            ->where('direction', self::RECEIVED)
            ->whereIn('parent_id', static::query()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->select('id'))
            ->latest('at');
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
