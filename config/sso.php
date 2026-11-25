<?php

return [
    'allowed_google_domains' => array_values(array_filter(array_map(
        static fn (string $domain): string => strtolower(trim($domain)),
        explode(',', (string) env('SSO_ALLOWED_GOOGLE_DOMAINS', '')),
    ))),

    'allowed_microsoft_tenant_ids' => array_values(array_filter(array_map(
        static fn (string $tenantId): string => strtolower(trim($tenantId)),
        explode(',', (string) env('SSO_ALLOWED_MICROSOFT_TENANT_IDS', '')),
    ))),

    'jit_provision_enabled' => (bool) env('SSO_JIT_PROVISION_ENABLED', false),

    'default_department_slug' => (string) env('SSO_DEFAULT_DEPARTMENT_SLUG', 'general'),
];
