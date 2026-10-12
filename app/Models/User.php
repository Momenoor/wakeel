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
    ];

    protected $with = [
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
        return Cache::remember('users.online', LiveRefresh::SECONDS, fn (): array => static::query()
            ->where('last_seen_at', '>', now()->subSeconds(self::ONLINE_WITHIN_SECONDS))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    public function isOnline(): bool
    {
        return (bool) $this->last_seen_at?->gt(now()->subSeconds(self::ONLINE_WITHIN_SECONDS));
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
