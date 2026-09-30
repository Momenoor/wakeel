<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every relation manager, tab and page now has its own permission, and the
 * four resources that had no policy (email templates, mail senders, letter
 * items, letterheads) now have one. Nothing new may take away what anyone
 * could already see: each new permission goes to whoever — role or user —
 * holds the permission that used to decide it.
 */
return new class extends Migration
{
    private const ABILITIES = ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'Restore', 'ForceDelete', 'ForceDeleteAny', 'RestoreAny', 'Replicate', 'Reorder'];

    public function up(): void
    {
        $grants = [];

        foreach (ScreenPermissions::grantedFrom() as $permission => $sources) {
            $grants[$permission] = $sources;
        }

        // Pages that were open to anyone, or borrowed another permission.
        $grants['View:FlightTickets'] = ['View:PayrollRun'];
        $grants['View:AuditDashboard'] = ['ViewAny:Activity'];

        foreach (['ActivityStatsWidget', 'ActivityChartWidget', 'ActivityHeatmapWidget', 'LatestActivityWidget'] as $widget) {
            $grants["View:{$widget}"] = ['ViewAny:Activity'];
        }

        // Resources that had no policy, from their closest neighbour.
        foreach (['EmailTemplate' => 'BulkMailCampaign', 'MailSender' => 'BulkMailCampaign', 'LetterItem' => 'LetterTemplate', 'Letterhead' => 'LetterTemplate'] as $model => $from) {
            foreach (self::ABILITIES as $ability) {
                $grants["{$ability}:{$model}"] = ["{$ability}:{$from}"];
            }
        }
        $grants['DeleteAny:LetterItem'] = ['DeleteAny:LetterTemplate'];

        foreach ($grants as $permission => $sources) {
            $this->grant($permission, $sources);
        }

        // Chat and the two "My" reports were open to everyone: every role.
        foreach (['View:Chat', 'View:MyIncentiveReport', 'View:MyMattersReport'] as $permission) {
            $id = $this->permissionId($permission);

            DB::table('roles')->pluck('id')->each(fn (int $roleId) => DB::table('role_has_permissions')
                ->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]));
        }

        $this->grantFinancialTabs();

        // System Updates stays super-admin only (they hold everything).
        $this->permissionId('View:SystemUpdates');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The permissions stay; removing them would only lock people out.
    }

    /**
     * The Financial Configuration tabs follow the page's old rule: the
     * incentive part for whoever manages incentive settings, the payroll
     * part for whoever manages payroll — and both for a role with neither.
     */
    private function grantFinancialTabs(): void
    {
        $incentiveTabs = [
            ScreenPermissions::FINANCIAL_INCENTIVE_TAB,
            ScreenPermissions::FINANCIAL_INCENTIVE_RATES_TAB,
            ScreenPermissions::FINANCIAL_TYPE_CONFIGS_TAB,
            ScreenPermissions::FINANCIAL_EXTRA_RULES_TAB,
            ScreenPermissions::FINANCIAL_META_ADJUSTMENTS_TAB,
        ];

        foreach ($this->holders(['View:FinancialConfiguration']) as [$table, $key, $id]) {
            $held = $this->heldBy($table, $key, $id);
            $incentive = in_array('ViewAny:MatterTypeIncentiveConfig', $held, true) || in_array('ViewAny:IncentiveExtraRule', $held, true);
            $payroll = in_array('ViewAny:PayrollRun', $held, true) || in_array('ViewAny:EmployeeProfile', $held, true);

            if ($incentive || ! $payroll) {
                foreach ($incentiveTabs as $permission) {
                    $this->attach($table, $key, $id, $this->permissionId($permission));
                }
            }

            if ($payroll || ! $incentive) {
                $this->attach($table, $key, $id, $this->permissionId(ScreenPermissions::FINANCIAL_PAYROLL_TAB));
            }
        }
    }

    /**
     * @param  list<string>  $sources
     */
    private function grant(string $permission, array $sources): void
    {
        $id = $this->permissionId($permission);

        foreach ($this->holders($sources) as [$table, $key, $id2]) {
            $this->attach($table, $key, $id2, $id);
        }
    }

    /**
     * Roles and users holding any of these permissions.
     *
     * @param  list<string>  $permissions
     * @return list<array{0: string, 1: array<string, mixed>, 2: int}>
     */
    private function holders(array $permissions): array
    {
        if ($permissions === []) {
            return [];
        }

        $ids = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');
        $holders = [];

        foreach (DB::table('role_has_permissions')->whereIn('permission_id', $ids)->distinct()->pluck('role_id') as $roleId) {
            $holders[] = ['role_has_permissions', ['role_id' => $roleId], (int) $roleId];
        }

        foreach (DB::table('model_has_permissions')->whereIn('permission_id', $ids)->select(['model_type', 'model_id'])->distinct()->get() as $row) {
            $holders[] = ['model_has_permissions', ['model_type' => $row->model_type, 'model_id' => $row->model_id], (int) $row->model_id];
        }

        return $holders;
    }

    /**
     * @param  array<string, mixed>  $key
     * @return list<string>
     */
    private function heldBy(string $table, array $key, int $id): array
    {
        return DB::table($table)
            ->join('permissions', 'permissions.id', '=', "{$table}.permission_id")
            ->where($key)
            ->pluck('permissions.name')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private function attach(string $table, array $key, int $id, int $permissionId): void
    {
        DB::table($table)->insertOrIgnore([...$key, 'permission_id' => $permissionId]);
    }

    private function permissionId(string $name): int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

        return (int) ($id ?? DB::table('permissions')->insertGetId([
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
};
