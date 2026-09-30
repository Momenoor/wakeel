<?php

namespace App\Models;

use App\Enums\LeaveBalanceEntryKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One movement of an employee's annual-leave balance. The balance is the
 * sum of these (LeaveBalanceService).
 */
class LeaveBalanceEntry extends Model
{
    use LogsActivity;

    protected $fillable = [
        'party_id',
        'kind',
        'days',
        'entry_date',
        'year',
        'leave_request_period_id',
        'note',
        'created_by',
    ];

    protected $casts = [
        'kind' => LeaveBalanceEntryKind::class,
        'days' => 'decimal:1',
        'entry_date' => 'date',
        'year' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<LeaveRequestPeriod, $this>
     */
    public function leaveRequestPeriod(): BelongsTo
    {
        return $this->belongsTo(LeaveRequestPeriod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
