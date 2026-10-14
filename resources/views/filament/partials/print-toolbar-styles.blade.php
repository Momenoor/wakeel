@media print {
    /* Nothing to click on paper. */
    .no-print { display: none !important; }

    tr { break-inside: avoid; }
}

.no-print {
    margin-bottom: 16px;
}

.no-print button {
    font: inherit;
    padding: 6px 14px;
    border: 1px solid #111;
    background: #111;
    color: #fff;
    cursor: pointer;
}
