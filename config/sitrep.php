<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Adapted SITREP document (D-45 amendment, D-147)
    |--------------------------------------------------------------------------
    |
    | Office metadata for the PDRRMO template letterhead and routing lines.
    | The issuing officer confirms these at issue time; the values here are
    | the defaults recorded on the issuance row, never recomputed on read.
    |
    */

    'office' => [
        'country' => 'Republic of the Philippines',
        'province' => 'Province of Pampanga',
        'municipality' => 'MUNICIPALITY OF APALIT',
        'unit' => 'Municipal Disaster Risk Reduction and Management Office',
        'address' => 'Municipal Hall Compound, San Juan, Apalit, Pampanga',
        'email' => 'r3.pam.mdrrm.apalit@gmail.com',
        'thru' => 'GLENN J. DANTING',
        'signatory' => 'CESAR M. CARLOS',
        'signatory_title' => 'Municipal DRRM Officer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled preparation identity (D-44, D-147)
    |--------------------------------------------------------------------------
    |
    | The account the 4:00 AM / 3:00 PM jobs prepare as, via the per-request
    | database identity. When unset, a scheduled attempt records a failed run
    | with the reason instead of preparing silently.
    |
    */

    'scheduler_user_id' => env('SITREP_SCHEDULER_USER_ID') !== null
        ? (int) env('SITREP_SCHEDULER_USER_ID')
        : null,

];
