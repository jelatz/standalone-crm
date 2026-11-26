<?php

return [
    'root_path' => env('CRM_SOPS_PATH', base_path('context/sops')),
    'max_bytes' => (int) env('CRM_SOPS_MAX_BYTES', 524288),
];
