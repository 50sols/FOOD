<?php

return [
    'idempotency_header_invalid' => 'A valid Idempotency-Key header is required.',
    'payment_method_not_configured' => 'The selected payment method is not configured.',
    'customer_required_field' => 'This field is required for Customer Flash checkout.',
    'invalid_base_quantity' => 'Resolved base quantity must be greater than zero.',
    'commercial_policy_rejected' => 'Flash reservation is not available under the current purchase policy.',
    'confirmed_request_mismatch' => 'Flash reservation is already attached to a different checkout request.',
    'idempotency_reuse_conflict' => 'Idempotency key was already used for a different checkout request.',
];
