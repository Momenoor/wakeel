<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MatterTypeIncentiveConfig extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'calculation_type',
        'fixed_percentage',
        'assistant_rate',
    ];

    protected $casts = [
        'fixed_percentage' => 'decimal:2',
        'assistant_rate' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    /**
     * The ways an incentive is worked out, by name.
     *
     * @return array<string, string>
     */
    public static function calculationTypes(): array
    {
        return [
            'tiered' => __('Tiered — % by working days & difficulty'),
            'fixed' => __('Fixed — fixed % for all fees'),
            'committee' => __('Committee — tiered ± 2%'),
        ];
    }

    public function calculationTypeLabel(): string
    {
        return self::calculationTypes()[$this->calculation_type] ?? (string) $this->calculation_type;
    }

    public function matterTypes(): HasMany
    {
        return $this->hasMany(Type::class, 'incentive_config_id');
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(MatterTypeIncentiveTier::class, 'config_id');
    }
}
