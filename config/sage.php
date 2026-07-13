<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Municipality identity for imports
    |--------------------------------------------------------------------------
    |
    | The Sage Evolution company database is imported into a single O-Billing
    | municipality, keyed on this code. Set these to match the council whose
    | Sage database the `sage` connection points at. Defaults to Plumtree Town
    | Council for backward compatibility.
    |
    */

    'municipality' => [
        'code' => env('SAGE_MUNI_CODE', 'PTC'),
        'name' => env('SAGE_MUNI_NAME', 'Plumtree Town Council'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Posting billing runs back to Sage (AR batches)
    |--------------------------------------------------------------------------
    |
    | `sage:post-run` stages a completed O-Billing billing run as an UNPOSTED
    | Sage Evolution debtors batch (_etblARAPBatches + _etblARAPBatchLines) on
    | the `sage_write` connection. A Sage operator then reviews and posts the
    | batch inside Evolution, so Sage's own engine writes the double entry
    | (PostAR/PostGL/tax) and the books stay balanced.
    |
    | - invoice_tr_code: the AR transaction type used for the debit lines. Its
    |   TrCode carries the debtors-control and tax accounts.
    | - revenue_accounts: GL account CODE credited per ledger service token
    |   (the contra on each line). Tokens without a mapping are staged with no
    |   contra and flagged, so the operator fills them in the batch grid.
    | - tax_type_id: the Sage output-tax type applied to lines that carry tax.
    | - currency_id: the Sage currency of the debtor accounts (USD).
    |
    */

    'posting' => [
        'batch_prefix' => 'OB',
        'invoice_tr_code' => env('SAGE_POST_TRCODE', 'IN-P1SP4'),
        'tax_type_id' => (int) env('SAGE_POST_TAXTYPE', 1), // Output Tax 15.5%
        'currency_id' => (int) env('SAGE_POST_CURRENCY', 1), // USD
        'revenue_accounts' => [
            'ASSR' => 'P1SP4-1131000004', // Assessment rates - Residential
            'ASS' => 'P1SP4-1131000004',
            'DEVC' => 'P1SP4-1131000008', // Development Levy - Communal
            'DEVR' => 'P1SP4-1131000010', // Development Levy - Residential
            'DEVM' => 'P1SP4-1131000009', // Development Levy - Mines
            'LEAR' => 'P1SP4-1415000011', // Trading Site Lease
            'LIC' => 'P1SP4-1145210017', // Other trading licences
            'LICR' => 'P1SP4-1145210017',
        ],
    ],

];
