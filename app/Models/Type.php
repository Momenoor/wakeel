<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /** The letter templates offered for this type's matters. */
    public function letterTemplates(): BelongsToMany
    {
        return $this->belongsToMany(LetterTemplate::class, 'letter_template_type');
    }
}
