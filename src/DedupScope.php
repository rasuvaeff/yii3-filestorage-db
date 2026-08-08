<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

/**
 * How widely identical bytes are allowed to be shared.
 *
 * Sharing is not free of information: whoever uploads a file learns, from the
 * write timing and from a storage-quota that does not move, whether that exact
 * content already existed. Between a tenant's own files that tells them nothing
 * they did not already know. Across tenants it is an oracle — upload a
 * suspected document, see whether it was "already there".
 *
 * So the scope is a deliberate choice with a safe default, not a performance
 * dial. `Global` saves the most space and is only appropriate when every tenant
 * is trusted with that inference — a single-customer deployment, or content
 * that is public anyway.
 *
 * @api
 */
enum DedupScope: string
{
    /** One pool per tenant per group. The default, and the only one safe for untrusted tenants. */
    case TenantGroup = 'tenant-group';

    /** One pool per tenant, shared across that tenant's groups. */
    case Tenant = 'tenant';

    /** One pool for everything. Discloses content existence across tenants. */
    case Global = 'global';

    /**
     * @param non-empty-string $groupName
     * @param non-empty-string|null $scopeId Null in a single-tenant application.
     *
     * @return non-empty-string A path-safe prefix for the content key.
     */
    public function keyFor(string $groupName, ?string $scopeId): string
    {
        // A tenant id comes from the application and may be an email, a UUID or
        // a slug, so it is hashed rather than trusted as a path segment. Short
        // is fine: this separates pools, it does not authenticate them.
        $tenant = $scopeId === null ? 'shared' : substr(hash('xxh128', $scopeId), 0, 16);

        return match ($this) {
            self::TenantGroup => "sha/{$tenant}/{$groupName}",
            self::Tenant => "sha/{$tenant}",
            self::Global => 'sha',
        };
    }
}
