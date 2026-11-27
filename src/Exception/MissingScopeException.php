<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Exception;

use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use RuntimeException;

/**
 * A scope provider is bound, and it returned no scope.
 *
 * Bound means the installation is multi-tenant. A null from it is a tenant that
 * did not resolve — an anonymous route, a failed lookup, a background job
 * started without its context — and quietly dropping the tenant predicate there
 * turns every statement into one over all tenants: `find()` reads another
 * tenant's file by a leaked id, `delete()` removes it, and new rows land with no
 * scope at all. So it is refused.
 *
 * Global access is an explicit opt-in for maintenance entry points
 * (`DbRepository(allowGlobalAccess: true)`), or the provider is left unbound.
 *
 * @api
 */
final class MissingScopeException extends RuntimeException implements FilestorageException {}
