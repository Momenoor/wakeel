{{-- A report printed on its own: landscape, the panel around it hidden (PrintableReport). --}}
<style>
    @media print {
        /* Reports always print landscape — their tables are wide. */
        @page {
            size: A4 landscape;
            margin: 12mm 10mm 12mm 10mm;
        }

        body {
            background: #ffffff !important;
            color: #111827 !important;
            font-size: 10pt !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        body * {
            visibility: hidden;
        }

        .report-print-container,
        .report-print-container * {
            visibility: visible !important;
        }

        .report-print-container {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #ffffff !important;
        }

        .report-print-header {
            display: block !important;
            visibility: visible !important;
        }

        aside,
        nav,
        header,
        .fi-sidebar,
        .fi-main-sidebar,
        .fi-topbar,
        .fi-breadcrumbs,
        .fi-header,
        .fi-page-header,
        .fi-header-actions-ctn,
        .fi-layout-sidebar-toggle-btn-ctn,
        .fi-sidebar-close-overlay,
        .fi-ta-header-toolbar,
        .fi-ta-filters,
        .fi-ta-filters-trigger-action-ctn,
        .fi-ta-actions,
        .fi-ta-col-wrp-actions,
        .fi-ta-selection-cell,
        .fi-ta-selection-checkbox,
        .fi-ta-selection-checkbox-column,
        .fi-ta-filter-indicators,
        .fi-pagination,
        .fi-announcement,
        .fi-wi-chart,
        #chat-widget,
        .fi-todo-note,
        .no-print {
            display: none !important;
        }

        .fi-ta-ctn {
            box-shadow: none !important;
            border: none !important;
            background: transparent !important;
            overflow: visible !important;
        }

        .fi-ta-content {
            overflow: visible !important;
        }

        table {
            width: 100% !important;
            border-collapse: collapse !important;
            page-break-inside: auto !important;
        }

        thead {
            display: table-header-group !important;
        }

        tr {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        th, td {
            border: 1px solid #d1d5db !important;
            padding: 4px 6px !important;
            color: #111827 !important;
            background-color: transparent !important;
        }

        th {
            background-color: #f3f4f6 !important;
            font-weight: 700 !important;
        }

        .fi-badge {
            border: 1px solid #9ca3af !important;
            background: transparent !important;
            color: #111827 !important;
            box-shadow: none !important;
        }
    }
</style>
