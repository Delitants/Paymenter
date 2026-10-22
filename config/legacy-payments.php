<?php

return [
    'receipt_ingress_enabled' => filter_var(env('LEGACY_RECEIPT_INGRESS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'trust_path' => env('LEGACY_RECEIPT_TRUST_PATH'),
    'trust_reader_gid' => env('LEGACY_RECEIPT_TRUST_READER_GID') === null ? null : filter_var(env('LEGACY_RECEIPT_TRUST_READER_GID'), FILTER_VALIDATE_INT),
];
