<?php

return [
    'receipt_ingress_enabled' => filter_var(env('LEGACY_RECEIPT_INGRESS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'trust_path' => env('LEGACY_RECEIPT_TRUST_PATH'),
    'trust_reader_gid' => env('LEGACY_RECEIPT_TRUST_READER_GID') === null ? null : filter_var(env('LEGACY_RECEIPT_TRUST_READER_GID'), FILTER_VALIDATE_INT),
    'opening_membership' => [
        'membership_path' => env('LEGACY_OPENING_MEMBERSHIP_PATH'),
        'acceptance_path' => env('LEGACY_OPENING_ACCEPTANCE_PATH'),
        'signature_path' => env('LEGACY_OPENING_SIGNATURE_PATH'),
        'trust_path' => env('LEGACY_OPENING_TRUST_PATH'),
        'reader_gid' => env('LEGACY_OPENING_READER_GID') === null ? null : filter_var(env('LEGACY_OPENING_READER_GID'), FILTER_VALIDATE_INT),
    ],
    'opening_exclusion' => [
        'directory' => env('LEGACY_OPENING_EXCLUSION_DIRECTORY'),
        'trust_path' => env('LEGACY_OPENING_EXCLUSION_TRUST_PATH'),
        'reader_gid' => env('LEGACY_OPENING_EXCLUSION_READER_GID') === null ? null : filter_var(env('LEGACY_OPENING_EXCLUSION_READER_GID'), FILTER_VALIDATE_INT),
    ],
];
