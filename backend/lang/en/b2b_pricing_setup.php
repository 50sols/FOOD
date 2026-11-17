<?php

return [
    'title' => 'Wholesale pricing mode setup',
    'toggle' => 'Enable negotiated price tiers',
    'purpose' => 'Choose whether approved Wholesale customer tiers or the product base price controls B2B pricing. This does not approve discounts, margin floors or credit.',
    'readiness' => 'Configuration readiness',
    'mode_base' => 'Base-price mode: tier pricing is disabled. Product prices and credit policies still need independent verification.',
    'mode_missing' => 'Action required: tiers are enabled but no active price rule exists for this Wholesale store. Do not assume customer pricing is configured.',
    'mode_partial' => 'Partial: active tier rules exist, but customer-tier assignment and every product, cost, margin and credit policy remain unverified.',
    'help' => 'Purpose, data source, example and permissions',
    'source' => 'Source: use an approved customer pricing agreement and Finance/Commercial pricing policy. Configure tiers and prices in the existing Pricing & Approvals workspace.',
    'example' => 'Illustrative only: a contracted Wholesale buyer might use a named tier. Do not copy an example price or treat it as an approved tariff.',
    'default' => 'Safe default: Off. Without an explicit setting, Wholesale pricing uses each product’s existing base price. No default discount or margin is invented.',
    'validation' => 'Validation: enabled is a required boolean; only Save or Test is accepted. Active price rules are store-scoped and checked on the server.',
    'permissions' => 'Authorization: an active Wholesale operator with settings.manage in the main B2B store. Retail roles cannot change this choice. Pricing and Finance approvals remain separate.',
    'test_help' => 'Test checks the selected mode and existence of an active store price rule. It makes no settings or audit writes. This precheck is not full ERP/module readiness.',
    'test' => 'Test without saving',
    'save' => 'Save pricing mode',
    'test_base' => 'Precheck: base-price mode selected; no setting changed. Product prices, cost and credit require separate verification.',
    'test_missing' => 'Precheck blocked: no active Wholesale tier price rule exists. Nothing was saved.',
    'test_partial' => 'Precheck partial: active Wholesale tier price rules exist, but customer/product coverage, margin, credit and approvals are not certified. Nothing was saved.',
];
