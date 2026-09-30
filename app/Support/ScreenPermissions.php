<?php

namespace App\Support;

/**
 * The permissions for parts of a screen that Shield does not generate on
 * its own: each relation manager and each tab has one, so a role can be
 * given a record without every table and tab that hangs off it.
 *
 * Listed here once. config/filament-shield.php declares them all as custom
 * permissions (the role editor SYNCS a role's permissions on save, so an
 * undeclared one would be stripped), and the migration that introduced
 * them granted each to the roles that could already see that part — the
 * `grantedFrom` permissions below.
 */
final class ScreenPermissions
{
    // ── Relation managers ────────────────────────────────────────────────
    public const BULK_MAIL_RECIPIENTS = 'View:BulkMailCampaignRecipientsRelation';

    public const COURT_MATTERS = 'View:CourtMattersRelation';

    public const LOAN_INSTALLMENTS = 'View:EmployeeLoanInstallmentsRelation';

    public const EMPLOYEE_SALARY = 'View:EmployeeSalaryComponentsRelation';

    public const EMPLOYEE_LEAVE_BALANCE = 'View:EmployeeLeaveBalanceRelation';

    public const EMPLOYEE_FLIGHT_TICKETS = 'View:EmployeeFlightTicketsRelation';

    public const MATTER_LETTERS = 'View:MatterLettersRelation';

    public const PARTY_MATTERS = 'View:PartyMattersRelation';

    public const PAYROLL_PAYSLIPS = 'View:PayrollRunPayslipsRelation';

    public const TYPE_FIELD_DEFINITIONS = 'View:TypeFieldDefinitionsRelation';

    public const CONDITION_TEMPLATE_ITEMS = 'View:ConditionTemplateItemsRelation';

    public const PRINT_TEMPLATE_PAGES = 'View:LeasePrintTemplatePagesRelation';

    public const LEASE_INSTALLMENTS = 'View:LeaseInstallmentsRelation';

    public const OWNER_GROUP_PROPERTIES = 'View:OwnerGroupPropertiesRelation';

    public const PROPERTY_UNITS = 'View:PropertyUnitsRelation';

    // ── Matter page tabs ─────────────────────────────────────────────────
    public const MATTER_OVERVIEW_TAB = 'View:MatterOverviewTab';

    public const MATTER_SESSIONS_TAB = 'View:MatterSessionsTab';

    public const MATTER_FEES_TAB = 'View:MatterFeesTab';

    public const MATTER_REQUESTS_TAB = 'View:MatterRequestsTab';

    public const MATTER_FILES_TAB = 'View:MatterFilesTab';

    public const MATTER_LETTERS_TAB = 'View:MatterLettersTab';

    // ── Matters list tabs ────────────────────────────────────────────────
    public const MATTERS_ALL_TAB = 'View:MattersAllTab';

    public const MATTERS_IN_PROGRESS_TAB = 'View:MattersInProgressTab';

    public const MATTERS_INITIAL_TAB = 'View:MattersInitialPreparedTab';

    public const MATTERS_FINAL_TAB = 'View:MattersFinalSubmittedTab';

    public const MATTERS_DELETED_TAB = 'View:MattersDeletedTab';

    // ── Parties list tabs ────────────────────────────────────────────────
    public const PARTIES_ALL_TAB = 'View:PartiesAllTab';

    public const PARTIES_PARTIES_TAB = 'View:PartiesPartiesTab';

    public const PARTIES_REPRESENTATIVES_TAB = 'View:PartiesRepresentativesTab';

    public const PARTIES_EXPERTS_TAB = 'View:PartiesExpertsTab';

    public const PARTIES_EMPLOYEES_TAB = 'View:PartiesEmployeesTab';

    // ── Financial Configuration tabs ─────────────────────────────────────
    public const FINANCIAL_INCENTIVE_TAB = 'View:FinancialIncentiveTab';

    public const FINANCIAL_INCENTIVE_RATES_TAB = 'View:FinancialIncentiveRatesTab';

    public const FINANCIAL_TYPE_CONFIGS_TAB = 'View:FinancialTypeConfigurationsTab';

    public const FINANCIAL_EXTRA_RULES_TAB = 'View:FinancialExtraRulesTab';

    public const FINANCIAL_META_ADJUSTMENTS_TAB = 'View:FinancialMetaAdjustmentsTab';

    public const FINANCIAL_PAYROLL_TAB = 'View:FinancialPayrollTab';

    // ── System Settings tabs ─────────────────────────────────────────────
    public const SETTINGS_MAINTENANCE_TAB = 'View:SystemSettingsMaintenanceTab';

    public const SETTINGS_GENERAL_TAB = 'View:SystemSettingsGeneralTab';

    public const SETTINGS_EMAIL_TAB = 'View:SystemSettingsEmailTab';

    public const SETTINGS_NOTIFICATIONS_TAB = 'View:SystemSettingsNotificationsTab';

    /**
     * Each permission, and the existing permissions whose holders could
     * already see that part — any one of them is enough.
     *
     * @return array<string, list<string>>
     */
    public static function grantedFrom(): array
    {
        $matterViewers = ['View:Matter', 'ViewOwn:Matter'];
        $matterListers = ['ViewAny:Matter', 'ViewOwn:Matter'];

        return [
            self::BULK_MAIL_RECIPIENTS => ['View:BulkMailCampaign'],
            self::COURT_MATTERS => ['View:Court'],
            self::LOAN_INSTALLMENTS => ['View:EmployeeLoan'],
            self::EMPLOYEE_SALARY => ['View:PayrollRun'],
            self::EMPLOYEE_LEAVE_BALANCE => ['View:PayrollRun'],
            self::EMPLOYEE_FLIGHT_TICKETS => ['View:PayrollRun'],
            self::MATTER_LETTERS => $matterViewers,
            self::PARTY_MATTERS => ['View:Party'],
            self::PAYROLL_PAYSLIPS => ['View:PayrollRun'],
            self::TYPE_FIELD_DEFINITIONS => ['View:Type'],
            self::CONDITION_TEMPLATE_ITEMS => ['View:ConditionTemplate'],
            self::PRINT_TEMPLATE_PAGES => ['View:LeasePrintTemplate'],
            self::LEASE_INSTALLMENTS => ['View:Lease'],
            self::OWNER_GROUP_PROPERTIES => ['View:OwnerGroup'],
            self::PROPERTY_UNITS => ['View:Property'],

            self::MATTER_OVERVIEW_TAB => $matterViewers,
            self::MATTER_SESSIONS_TAB => $matterViewers,
            self::MATTER_FEES_TAB => $matterViewers,
            self::MATTER_REQUESTS_TAB => $matterViewers,
            self::MATTER_FILES_TAB => $matterViewers,
            self::MATTER_LETTERS_TAB => $matterViewers,

            self::MATTERS_ALL_TAB => $matterListers,
            self::MATTERS_IN_PROGRESS_TAB => $matterListers,
            self::MATTERS_INITIAL_TAB => $matterListers,
            self::MATTERS_FINAL_TAB => $matterListers,
            self::MATTERS_DELETED_TAB => ['ViewTrashed:Matter'],

            self::PARTIES_ALL_TAB => ['ViewAny:Party'],
            self::PARTIES_PARTIES_TAB => ['ViewAny:Party'],
            self::PARTIES_REPRESENTATIVES_TAB => ['ViewAny:Party'],
            self::PARTIES_EXPERTS_TAB => ['ViewAny:Party'],
            self::PARTIES_EMPLOYEES_TAB => ['ViewAny:Party'],

            // The Financial Configuration tabs are granted by the migration's
            // own rule (the page's old per-cluster check), not a simple list.
            self::FINANCIAL_INCENTIVE_TAB => [],
            self::FINANCIAL_INCENTIVE_RATES_TAB => [],
            self::FINANCIAL_TYPE_CONFIGS_TAB => [],
            self::FINANCIAL_EXTRA_RULES_TAB => [],
            self::FINANCIAL_META_ADJUSTMENTS_TAB => [],
            self::FINANCIAL_PAYROLL_TAB => [],

            self::SETTINGS_MAINTENANCE_TAB => ['View:SystemSettings'],
            self::SETTINGS_GENERAL_TAB => ['View:SystemSettings'],
            self::SETTINGS_EMAIL_TAB => ['View:SystemSettings'],
            self::SETTINGS_NOTIFICATIONS_TAB => ['View:SystemSettings'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::grantedFrom());
    }

    public static function can(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }
}
