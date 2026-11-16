<?php

return [
    // Intentionally empty by default. Only an administrator-approved,
    // independently authenticated trust-key source may populate this map.
    // Never accept a public key supplied by an untrusted certificate or ZIP.
    // No live stock title becomes certified without a trusted matching key.
    'trusted_public_keys' => [],
];
