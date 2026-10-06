<?php

return [
    'enabled' => filter_var(env('ACCOUNT_FUNDING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
