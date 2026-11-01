<?php

return [
    'anomalies' => [
        'duplicate_window_minutes' => (int) env('FLOOD_DUPLICATE_WINDOW_MINUTES', 30),
        'observation_deviation_minutes' => (int) env('FLOOD_OBSERVATION_DEVIATION_MINUTES', 15),
    ],
];
