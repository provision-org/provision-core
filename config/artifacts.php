<?php

return [
    'max_per_agent' => (int) env('ARTIFACT_MAX_PER_AGENT', 20),
    'max_apps_per_agent' => (int) env('ARTIFACT_MAX_APPS_PER_AGENT', 5),
    'operations_per_minute' => (int) env('ARTIFACT_OPERATIONS_PER_MINUTE', 10),
    'lock_seconds' => (int) env('ARTIFACT_OPERATION_LOCK_SECONDS', 180),
    'lock_wait_seconds' => (int) env('ARTIFACT_OPERATION_LOCK_WAIT_SECONDS', 15),
];
