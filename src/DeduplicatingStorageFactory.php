<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use DateInterval;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;

/**
 * Assembles {@see DeduplicatingStorage}, or explains why it cannot.
 *
 * Deduplication is the one thing in this family an application opts into by
 * overriding `StorageInterface` in its own config — core binds the base facade,
 * and a second vendor package binding the same key is the `Duplicate key` error
 * everything else here is arranged to avoid. So the wiring happens in the
 * application layer, and this factory exists so that it is three lines rather
 * than a dozen.
 *
 * The checks matter more than the assembly. Both preconditions fail silently
 * if nobody looks: a store without atomic put-if-absent corrupts data under
 * concurrency, and a ledger on a different connection than the repository makes
 * `commit()` two transactions instead of one — which is the same as having no
 * transaction at all. Neither shows up in a single-process test.
 *
 * @api
 */
final readonly class DeduplicatingStorageFactory
{
    public function __construct(
        private StorageInterface $unique,
        private StoreRegistry $stores,
        private DbRepository $repository,
        private BlobLedgerInterface $ledger,
        private MimeTypeDetectorInterface $mimeTypeDetector,
        private IdGeneratorInterface $idGenerator,
        private PolicyRegistry $policies,
        private ClockInterface $clock,
        private ?FileScopeProviderInterface $scopes = null,
        private ?ContentAddressedKeyGeneratorInterface $keys = null,
    ) {}

    /**
     * @param non-empty-string|null $storeName Defaults to the registry's default store.
     *
     * @throws InvalidConfigException When the store cannot deduplicate safely.
     */
    public function create(
        DedupScope $scope = DedupScope::TenantGroup,
        ?string $storeName = null,
        int $dedupMaxBytes = 104_857_600,
        DateInterval $reservationTtl = new DateInterval('PT15M'),
        DateInterval $deleteGracePeriod = new DateInterval('PT1H'),
        string $defaultGroup = 'common',
    ): DeduplicatingStorage {
        $store = $this->stores->get($storeName);

        if (!$store instanceof ContentAddressableStoreInterface) {
            throw new InvalidConfigException(
                "Store \"{$store->name()}\" cannot deduplicate: it does not implement "
                . ContentAddressableStoreInterface::class . '. Sharing bytes between files needs atomic '
                . 'put-if-absent, which not every adapter can promise — with Flysystem, bind '
                . 'FlysystemContentAddressableStore and declare AdapterSemantics. Without it, keep the base '
                . 'StorageInterface binding: unique storage is fully functional, just not deduplicating',
            );
        }

        if (!$this->ledger instanceof DbBlobLedger) {
            throw new InvalidConfigException(
                'Deduplication needs the transactional ' . DbBlobLedger::class . '. The ledger currently bound is '
                . $this->ledger::class . ', which cannot promise that a file row and its blob reference commit '
                . 'together',
            );
        }

        if (!$this->ledger->sharesConnectionWith($this->repository)) {
            throw new InvalidConfigException(
                'The blob ledger and the file repository are on different database connections, so committing a '
                . 'file row and its blob reference would be two transactions rather than one — a crash between '
                . 'them leaves a row with no reference, or a reference with no row. Bind one '
                . 'Yiisoft\\Db\\Connection\\ConnectionInterface and give it to both',
            );
        }

        return new DeduplicatingStorage(
            unique: $this->unique,
            ledger: $this->ledger,
            store: $store,
            keys: $this->keys ?? new Sha256KeyGenerator(),
            mimeTypeDetector: $this->mimeTypeDetector,
            idGenerator: $this->idGenerator,
            policies: $this->policies,
            clock: $this->clock,
            scope: $scope,
            dedupMaxBytes: $dedupMaxBytes,
            reservationTtl: $reservationTtl,
            deleteGracePeriod: $deleteGracePeriod,
            defaultGroup: $defaultGroup,
            scopes: $this->scopes,
        );
    }
}
