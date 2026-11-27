<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\LayoutCompatibility;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;

/**
 * The two ways a content-key generator can be wrong for a deduplicating store,
 * checked where the live path and the migration command combine them.
 *
 * 1. Its keys do not fit the store's layout — a flat `ab/cd/<sha>.ext` key
 *    under `DirectoryLayout` makes deleting one blob delete its whole fan-out
 *    bucket. Core's {@see LayoutCompatibility} decides that.
 * 2. It drops the tenant from the key — `FlatSha256KeyGenerator` with a
 *    prefix — while a provider is bound and the scope keeps tenants apart.
 *    Every tenant would then share one pool, which is the content-existence
 *    oracle {@see DedupScope} documents.
 *
 * Shared by {@see DeduplicatingStorageFactory} and
 * {@see Command\DeduplicateCommand}, which must refuse the same pairs.
 *
 * @internal
 */
final readonly class ContentKeyCheck
{
    private const string PROBE_GROUP = 'probe';
    private const string PROBE_DIGEST = 'abcd0000000000000000000000000000000000000000000000000000000000ef';

    private function __construct() {}

    /**
     * @throws InvalidConfigException
     */
    public static function assert(
        ContentAddressedKeyGeneratorInterface $keys,
        StoreInterface $store,
        DedupScope $scope,
        bool $tenantBound,
    ): void {
        LayoutCompatibility::assertContentKeys($keys, $store);

        if (!$tenantBound || $scope === DedupScope::Global) {
            return;
        }

        try {
            $first = $keys->generate($scope->keyFor(self::PROBE_GROUP, 'tenant-a'), self::PROBE_DIGEST, 'jpg');
            $second = $keys->generate($scope->keyFor(self::PROBE_GROUP, 'tenant-b'), self::PROBE_DIGEST, 'jpg');
        } catch (InvalidArgumentException $e) {
            throw new InvalidConfigException(
                'Content-addressed key generator ' . $keys::class . " refused a probe key: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if ($first === $second) {
            throw new InvalidConfigException(sprintf(
                'Content-addressed key generator %s ignores the tenant: two tenants\' identical bytes both get '
                    . '"%s", so the "%s" scope would share them across tenants. Use a generator that keeps the '
                    . 'scope in the key, or choose the "%s" scope to share across tenants on purpose',
                $keys::class,
                $first,
                $scope->value,
                DedupScope::Global->value,
            ));
        }
    }
}
