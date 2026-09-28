<?php

return [
    // Explicitly name the destination databases allowed to receive imported rows.
    'allowed_databases' => array_filter(explode(',', (string) env('BILLMANAGER_ALLOWED_DATABASES', ''))),
];
