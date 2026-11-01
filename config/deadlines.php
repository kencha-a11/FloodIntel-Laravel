<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Consolidation window (D-24)
    |--------------------------------------------------------------------------
    |
    | The operational window runs from one cutoff to the next. "Pending" means
    | a report whose window instant falls inside the current window and whose
    | status is still submitted. The client receives these values from the
    | deadline endpoint rather than hard-coding them.
    |
    */

    'timezone' => env('DEADLINES_TIMEZONE', 'Asia/Manila'),

    'cutoffs' => ['04:00', '15:00'],

];
