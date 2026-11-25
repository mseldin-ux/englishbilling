<?php

return [
    // Enable after the allocation migration and staging report verification.
    'concurrent_stages' => env('BILLING_CONCURRENT_STAGES', false),
];
