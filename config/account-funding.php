<?php

return [
    'runtime_acceptance_path' => env('ACCOUNT_FUNDING_RUNTIME_ACCEPTANCE_PATH'),
    'runtime_signature_path' => env('ACCOUNT_FUNDING_RUNTIME_SIGNATURE_PATH'),
    'runtime_trust_path' => env('ACCOUNT_FUNDING_RUNTIME_TRUST_PATH'),
    'runtime_reader_gid' => env('ACCOUNT_FUNDING_RUNTIME_READER_GID') === null ? null : filter_var(env('ACCOUNT_FUNDING_RUNTIME_READER_GID'), FILTER_VALIDATE_INT),
    'enabled' => filter_var(env('ACCOUNT_FUNDING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
