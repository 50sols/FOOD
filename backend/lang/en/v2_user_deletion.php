<?php

return [
    'preview' => 'Review permanent-deletion eligibility',
    'title' => 'User deletion impact review',
    'description' => 'Permanent deletion is permitted only for an inactive identity with no retained audit, financial, operational, session, role, SSO, or other relationships. Existing historical records are never cascaded.',
    'allowed' => 'No retained relationships were found. Permanent deletion is available after confirmation.',
    'blocked' => 'Permanent deletion is blocked. Keep this identity archived until its retention and relationship obligations are resolved.',
    'active' => 'Deactivate the account first using the existing authorized archive workflow.',
    'self' => 'You cannot delete your own account.',
    'protected' => 'Platform Owner and Super Admin identities cannot be permanently deleted.',
    'references' => 'Retained records or account relationships prevent deletion. They must not be cascaded or silently removed.',
    'unverified' => 'The complete relationship inventory could not be verified. Deletion is unavailable.',
    'reason' => 'Reason for permanent deletion',
    'reason_hint' => 'Explain why the account may be deleted under your retention policy (at least 8 characters).',
    'confirm_email' => 'Type the account email exactly',
    'confirm_delete' => 'Type DELETE to confirm',
    'review' => 'I reviewed the impact and confirm this account has no retention or legal hold.',
    'submit' => 'Permanently delete eligible user',
    'deleted' => 'The eligible user was permanently deleted and the action was audited.',
    'confirmation_mismatch' => 'The confirmation email does not match the current account.',
    'back' => 'Back to users',
];
