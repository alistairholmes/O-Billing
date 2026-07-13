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

];
