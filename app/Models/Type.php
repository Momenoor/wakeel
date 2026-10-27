<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Type extends Model
{
    use HasFactory;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    /** withoutIncentiveConfig()'s cache: cleared when a type or an incentive configuration changes. */
    public const WITHOUT_INCENTIVE_CACHE = 'types.without-incentive-config';

    protected static function booted(): void
    {
        $forget = fn () => Cache::forget(self::WITHOUT_INCENTIVE_CACHE);
        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * The active types linked to no incentive configuration — the calculation skips their matters' fees — by name:
     * read once and kept, since the admins' pop-up asks on every page.
     *
     * @return array<int, string> name by id
     */
    public static function withoutIncentiveConfig(): array
    {
        return Cache::rememberForever(self::WITHOUT_INCENTIVE_CACHE, fn (): array => static::query()
            ->where('active', true)
            ->whereNull('incentive_config_id')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all());
    }

    protected $casts = [
        'active' => 'boolean',
        'allow_current_status_import' => 'boolean',
        'exclude_from_incentive_count' => 'boolean',
        'incentive_config_id' => 'integer',
        'party_capacities' => 'array',
    ];

    /** The sides of a matter, as its parties are typed. */
    public const SIDES = ['plaintiff', 'defendant', 'implicate-litigant'];

    /**
     * Common names for the two sides, to pick from — plaintiff | defendant.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const CAPACITY_PRESETS = [
        ['المدعي', 'المدعى عليه'],
        ['المتنازع', 'المتنازع ضدها'],
        ['الشاكي', 'المشكو في حقه'],
        ['الطاعن', 'المطعون عليه'],
        ['طالب التنفيذ', 'المنفذ ضده'],
        ['المستأنف', 'المستأنف ضده'],
        ['المتظلم', 'المتظلم ضده'],
        ['طالب الإجراء', 'المطلوب ضده الإجراء'],
    ];

    protected $fillable = [
        'name',
        'active',
        'incentive_trigger_type',
        'allow_current_status_import',
        'exclude_from_incentive_count',
        'incentive_config_id',
        'party_capacities',
        'onedrive_subfolders',
        'onedrive_received_emails',
        'onedrive_sent_emails',
        'onedrive_signed_minutes',
    ];

    /**
     * What a side is called in this type's matters (in Arabic), or null
     * for the usual name.
     */
    public function capacity(?string $side): ?string
    {
        $name = trim((string) (($this->party_capacities ?? [])[$side] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * A side's name on screen: the matter type's own, otherwise the usual
     * one in the interface's language.
     */
    public static function sideLabel(?self $type, ?string $side): string
    {
        return $type?->capacity($side) ?? match ($side) {
            'plaintiff' => __('Plaintiff'),
            'defendant' => __('Defendant'),
            'implicate-litigant' => __('Implicate Litigant'),
            default => __(ucfirst(str_replace('-', ' ', (string) $side))),
        };
    }

    /**
     * The sides to choose from for a party of a matter of this type.
     *
     * @return array<string, string>
     */
    /**
     * A type by id, read once a request — the matter form asks for it for
     * every party row and again on each save (eleven queries for one).
     */
    public static function remembered(mixed $id): ?self
    {
        if (blank($id)) {
            return null;
        }

        $key = 'type_remembered_'.$id;
        $request = request();

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, static::find($id));
        }

        return $request->attributes->get($key);
    }

    public static function sideOptions(?self $type): array
    {
        return collect(self::SIDES)->mapWithKeys(fn (string $side) => [$side => self::sideLabel($type, $side)])->all();
    }

    /**
     * @return HasMany<Matter, $this>
     */
    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function incentiveConfig(): BelongsTo
    {
        return $this->belongsTo(MatterTypeIncentiveConfig::class, 'incentive_config_id');
    }

    public function fieldDefinitions(): BelongsToMany
    {
        return $this->belongsToMany(MatterFieldDefinition::class, 'matter_field_definition_type');
    }

    /** Its own OneDrive folders (Settings → OneDrive Folders): empty — the default one. */
    public const FOLDER_FIELDS = ['onedrive_subfolders', 'onedrive_received_emails', 'onedrive_sent_emails', 'onedrive_signed_minutes'];

    /**
     * Its OneDrive folders as one value, to compare: types alike in all four are a group.
     *
     * @param  array<string, mixed>|null  $values  as given, else as it is
     */
    public function folderKey(?array $values = null): ?string
    {
        $folders = array_map(fn (string $field): string => trim((string) (($values ?? $this->getAttributes())[$field] ?? '')), self::FOLDER_FIELDS);

        return implode('', $folders) === '' ? null : md5(implode("\0", $folders));
    }

    /**
     * The types with OneDrive folders of their own, in groups of those alike — read at once.
     *
     * @return \Illuminate\Support\Collection<string, Collection<int, Type>>
     */
    public static function folderGroups(): \Illuminate\Support\Collection
    {
        return static::query()->orderBy('name')->get(['id', 'name', ...self::FOLDER_FIELDS])
            ->filter(fn (Type $type): bool => $type->folderKey() !== null)
            ->groupBy(fn (Type $type): string => (string) $type->folderKey());
    }

    /**
     * The other types whose OneDrive folders are the same as this one's (as saved): its group.
     *
     * @return Collection<int, Type>
     */
    public function folderGroupmates(): Collection
    {
        // Read once a request (a form asks for it more than once), from what was saved.
        return once(function (): Collection {
            $key = $this->folderKey($this->getOriginal());

            return $key === null ? new Collection : static::query()->whereKeyNot($this->getKey())->get(['id', 'name', ...self::FOLDER_FIELDS])
                ->filter(fn (Type $type): bool => $type->folderKey() === $key)
                ->values();
        });
    }

    /** The letter templates linked to this type — offered for it, or (`excluded`) left out for it. */
    public function letterTemplates(): BelongsToMany
    {
        return $this->belongsToMany(LetterTemplate::class, 'letter_template_type')->withPivot('excluded');
    }

    /** The letter templates offered for this type's matters (linked to it). */
    public function offeredLetterTemplates(): BelongsToMany
    {
        return $this->letterTemplates()->wherePivot('excluded', false);
    }

    /** The letter templates that are for every type except this one (and others). */
    public function excludedLetterTemplates(): BelongsToMany
    {
        return $this->letterTemplates()->wherePivot('excluded', true);
    }

    /**
     * Sets which letter templates are offered for this type and which are
     * left out for it. Without $detach the others stay as they are.
     *
     * @param  list<int|string>  $offered
     * @param  list<int|string>  $excluded
     */
    public function assignLetterTemplates(array $offered, array $excluded = [], bool $detach = true): void
    {
        $offered = array_map('intval', $offered);
        // Offered wins when a template is in both.
        $excluded = array_values(array_diff(array_map('intval', $excluded), $offered));

        $this->letterTemplates()->{$detach ? 'sync' : 'syncWithoutDetaching'}(
            array_fill_keys($offered, ['excluded' => false]) + array_fill_keys($excluded, ['excluded' => true]),
        );
    }
}
