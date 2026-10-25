<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'Property management settings',
    'intro' => 'The policy figures the property system calculates with: the VAT rate for mixed-use units, the estimated attestation fees on quotations, and the bounced cheque penalty. Find them under "Settings → Master Data → PMS Settings".',
    'actions' => [
        [
            'id' => 'settings',
            'title' => 'Set the tax, the attestation fees and the penalty',
            'description' => 'Three sections: "Tax" (the VAT rate for mixed use, as a decimal such as 0.05), "Attestation Fee Estimate" (Sharjah residential and commercial as a percentage of the rent, Dubai\'s Ejari fee per contract, and a fee per contract for the other emirates), and "Quotations & Instalments" (the "Bounced Cheque Penalty (AED)" that is added when an instalment is marked bounced).',
            'roles' => [$admin, $withPermission],
            'permissions' => [],
            'steps' => [
                ['text' => 'Open "PMS Settings", change the figures you need, then click "Save". They apply to new quotations and leases.', 'shot' => 'page-1'],
            ],
            'tips' => ['The attestation fees here are an estimate shown on the quotation. The actual government fee is confirmed when the lease is signed.'],
        ],
    ],
];
