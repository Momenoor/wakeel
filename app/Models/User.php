<?php

namespace App\Models;

use App\Support\Branding;
use App\Support\LiveRefresh;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use FilamentInbox\Concerns\HasInbox;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property-read ChatConversationUser|null $pivot Only set when loaded through a chat conversation's participants() relation.
 */
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** Seen within this many seconds counts as online (see isOnline()). */
    // Twice the stamp's interval (TrackUserLastSeen, 15 s): someone with the
    // app open never flickers off; gone, they show offline within it.
    public const ONLINE_WITHIN_SECONDS = 30;

    /** Seen within this many seconds counts as online: at least two chat checks, so an open page never flickers off. */
    public static function onlineWithinSeconds(): int
    {
        return max(self::ONLINE_WITHIN_SECONDS, LiveRefresh::seconds() * 2 + 5);
    }

    use HasFactory, HasInbox, HasRoles, Notifiable;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    protected $fillable = [
        'name',
        'password',
        'language',
        'email',
        'display_name',
        'font_size',
        'notify_by_email',
        'notify_by_whatsapp',
        'profile_photo_path',
        'last_seen_at',
        'theme_id',
        'ms_account',
        'mailbox_reads',
        'mailbox_keeps_all',
        'mailbox_sends',
        'shared_senders',
        'shared_mailboxes',
        'unfiled_all',
        'mail_signature',
    ];

    protected $with = [
    ];

    /** As the table makes them: a new user may send from the shared mailboxes, has no mailbox of their own on. */
    protected $attributes = [
        'shared_senders' => true,
        'mailbox_reads' => false,
        'mailbox_keeps_all' => false,
        'mailbox_sends' => false,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'hashed',
        'email_verified_at' => 'datetime',
        'ui_preferences' => 'array',
        'font_size' => 'integer',
        'notify_by_email' => 'boolean',
        'notify_by_whatsapp' => 'boolean',
        'last_seen_at' => 'datetime',
        'mailbox_reads' => 'boolean',
        'mailbox_keeps_all' => 'boolean',
        'mailbox_sends' => 'boolean',
        'shared_senders' => 'boolean',
        'shared_mailboxes' => 'array',
        'unfiled_all' => 'boolean',
    ];

    /**
     * "Online" is a 30-second window on last_seen_at, which TrackUserLastSeen
     * stamps (at most every 15 seconds) on page loads and on the panels'
     * polling requests. With Pusher configured the chat widget overrides
     * this live from the "online" presence channel; this is the fallback.
     */
    /** The super administrator (filament-shield's role): allowed what others aren't. */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Utils::getSuperAdminName());
    }

    /**
     * Everyone online now — read once every couple of seconds for all who
     * ask (each chat's check): one small query, however many are open.
     *
     * @return list<int>
     */
    public static function onlineIds(): array
    {
        return Cache::remember('users.online', LiveRefresh::seconds(), fn (): array => static::query()
            ->where('last_seen_at', '>', now()->subSeconds(self::onlineWithinSeconds()))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    public function isOnline(): bool
    {
        return (bool) $this->last_seen_at?->gt(now()->subSeconds(self::onlineWithinSeconds()));
    }

    /**
     * "Online", "Last seen 5 minutes ago" or "Never seen", in the
     * interface's language.
     */
    public function lastSeenText(): string
    {
        if ($this->isOnline()) {
            return __('Online');
        }

        return $this->last_seen_at
            ? __('Last seen :time', ['time' => $this->last_seen_at->diffForHumans()])
            : __('Never seen');
    }

    /**
     * The Party record this user acts as (assistant, expert, etc.).
     *
     * @return HasOne<Party, $this>
     */
    public function party(): HasOne
    {
        return $this->hasOne(Party::class);
    }

    /** The work account's fields: a change to any keeps their own mailbox and OneDrive in step. */
    /**
     * Whether they see every unfiled email of the shared mailboxes, or only those that may be about their own
     * matters (Unfiled emails): as set on their page — else all for whoever sees every matter. One kept from the
     * shared mailboxes never sees all.
     */
    public function seesAllUnfiled(): bool
    {
        return $this->shared_senders && ($this->unfiled_all ?? $this->can('ViewAny:Matter'));
    }

    /**
     * Whether they may use this shared mailbox of the office — send from it, see its unfiled emails: one of those
     * ticked on their page (none ticked ever: all of them, any added later too); none when kept from them.
     */
    public function mayUseSharedMailbox(?string $key): bool
    {
        return $this->shared_senders && ($this->shared_mailboxes === null || in_array((string) $key, $this->shared_mailboxes, true));
    }

    public const WORK_ACCOUNT = ['email', 'ms_account', 'mailbox_reads', 'mailbox_keeps_all', 'mailbox_sends', 'mail_signature', 'display_name', 'name'];

    protected static function booted(): void
    {
        // Only when the work account changed: a user is saved often (last seen) — then nothing is read.
        static::saved(function (User $user): void {
            if (! $user->wasRecentlyCreated && ! $user->wasChanged(self::WORK_ACCOUNT)) {
                return;
            }

            MailSender::syncFor($user);

            // An assistant's OneDrive is in the same account: set once, here. Read, not kept as the relation
            // (a party linked to them later must be found then).
            $party = $user->party()->first();
            if ($party?->isAssistant() && $party->onedrive_email !== $user->msAccount()) {
                $party->update(['onedrive_email' => $user->msAccount()]);
            }
        });
    }

    /**
     * Their Microsoft 365 account — their OneDrive, calendar and own mailbox: as set, else the email they sign
     * in with.
     */
    public function msAccount(): ?string
    {
        $account = trim((string) ($this->ms_account ?: $this->email));

        return filter_var($account, FILTER_VALIDATE_EMAIL) ? mb_strtolower($account) : null;
    }

    /** Their own mailbox, as a mail sender (MailSender::syncFor()). */
    public function ownMailSender(): HasOne
    {
        return $this->hasOne(MailSender::class);
    }

    /**
     * The assistants whose matters they handle with them: their matters theirs to open and work on, and
     * what happens on them told to them too.
     *
     * @return BelongsToMany<Party, $this>
     */
    public function administeredAssistants(): BelongsToMany
    {
        return $this->belongsToMany(Party::class, 'assistant_administrators')->withTimestamps();
    }

    /**
     * Whose matters are theirs, as parties on them: their own party, and the assistants they administer.
     * Read once a request.
     *
     * @return list<int>
     */
    public function ownMatterPartyIds(): array
    {
        return once(fn (): array => array_values(array_unique(array_map('intval', array_filter([
            $this->party?->getKey(),
            ...$this->administeredAssistants()->pluck('parties.id')->all(),
        ])))));
    }

    /** The theme they chose (App\Support\ThemeManager decides whether it is used). */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function incentiveCalculations(): HasMany
    {
        return $this->hasMany(IncentiveCalculation::class, 'created_by');
    }

    /**
     * @return BelongsToMany<ChatConversation, $this, ChatConversationUser, 'pivot'>
     */
    public function chatConversations(): BelongsToMany
    {
        return $this->belongsToMany(ChatConversation::class, 'chat_conversation_user')
            ->using(ChatConversationUser::class)
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /**
     * Impersonating is its own permission, not a side effect of seeing the
     * users list.
     */
    public function canImpersonate(): bool
    {
        return $this->can('Impersonate:User');
    }

    /**
     * Only a super admin may become a super admin.
     */
    public function canBeImpersonated(): bool
    {
        $superAdmin = Utils::getSuperAdminName();

        return ! $this->hasRole($superAdmin) || (auth()->user()?->hasRole($superAdmin) ?? false);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'pms') {
            return $this->can('Access:MultipleSystems');
        }

        return true;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        if (blank($this->profile_photo_path)) {
            return Branding::defaultAvatarUrl();
        }

        return Storage::disk('public')->url($this->profile_photo_path);
    }

    public function phone(): Attribute
    {
        return new Attribute(
            get: fn () => $this->party?->phone ?? '',
        );
    }
}
