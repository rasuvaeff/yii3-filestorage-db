<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use DateInterval;
use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\AddException;
use Rasuvaeff\Yii3Filestorage\Exception\BlobBusyException;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Exception\RemoveException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadSizeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Rasuvaeff\Yii3FilestorageDb\Exception\MissingScopeException;
use Throwable;

/**
 * Storage that lets identical uploads share one physical object.
 *
 * The same consumer API as the base facade, a different lifecycle underneath.
 * Every `add()` still produces its own `File` — its own id, group, description,
 * metadata and tenant scope — but two uploads of the same bytes end up pointing
 * at one object, and that object outlives the first row to be deleted.
 *
 * The protocol, and why each step is where it is:
 *
 * 1. **Hash first, within a limit.** The content key *is* the hash, so it
 *    cannot be chosen before the whole body has been read. Above
 *    `dedupMaxBytes` the upload takes the ordinary unique path instead — an
 *    unbounded second read of a multi-gigabyte upload is not a trade worth
 *    making for a deduplication that may not hit.
 * 2. **Reserve.** The ledger row is created or joined *before* any bytes are
 *    written, so a collector cannot remove the object between the write and
 *    the commit.
 * 3. **Publish.** `putIfAbsent()` writes immutable bytes, or reports that the
 *    same bytes were already there.
 * 4. **Commit.** One transaction turns the reservation into a committed
 *    reference and inserts the file row.
 * 5. **Release on any failure.** Which schedules the blob rather than deleting
 *    it: another writer may be holding the same bytes right now.
 *
 * `remove()` never deletes shared bytes either. It drops the row and the
 * reference; the collector does the rest, after a grace period. That asymmetry
 * — writes are eager, deletes are deferred — is the whole safety argument. A
 * row on the unique path (too large to hash, or in another store) owns its
 * object outright, and its bytes go with it, as with the base facade.
 *
 * @api
 */
final readonly class DeduplicatingStorage implements StorageInterface
{
    private const int CHUNK = 262_144;
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';

    /**
     * @param StorageInterface $unique The base facade. Handles everything this
     *        class does not share: reads, URLs, and uploads too large to hash.
     * @param int $dedupMaxBytes Above this an upload is stored uniquely. Zero
     *        would mean "hash everything", which is why it is not the default.
     * @param DateInterval $reservationTtl How long an uncommitted write keeps
     *        the blob alive. Long enough for the slowest upload you accept.
     *        Must be positive.
     * @param DateInterval $deleteGracePeriod How long an unreferenced blob
     *        waits before a collector may take it. Must be positive: the join
     *        of a blob that is losing its last reference is only safe against
     *        a concurrent claim while the two are apart in time.
     *
     * @param StoreRegistry|null $stores Where `remove()` deletes the object
     *        of a unique-path row. Without it only rows in this class's own
     *        store get their bytes deleted; the others are left to
     *        `filestorage:gc --orphans`.
     *
     * @throws \InvalidArgumentException When an interval is not positive.
     * @throws InvalidConfigException When the key generator's keys do not fit
     *         the store's layout, or — with a scope provider bound and a scope
     *         other than `Global` — leave the tenant out. Checked here, not
     *         only in {@see DeduplicatingStorageFactory}: built directly, such
     *         a pair writes blobs `filestorage:gc` can never collect, or pools
     *         every tenant without a signal.
     */
    public function __construct(
        private StorageInterface $unique,
        private BlobLedgerInterface $ledger,
        private ContentAddressableStoreInterface $store,
        private ContentAddressedKeyGeneratorInterface $keys,
        private MimeTypeDetectorInterface $mimeTypeDetector,
        private IdGeneratorInterface $idGenerator,
        private PolicyRegistry $policies,
        private ClockInterface $clock,
        private DedupScope $scope = DedupScope::TenantGroup,
        private int $dedupMaxBytes = 104_857_600,
        private DateInterval $reservationTtl = new DateInterval('PT15M'),
        private DateInterval $deleteGracePeriod = new DateInterval('PT1H'),
        private string $defaultGroup = 'common',
        private ?FileScopeProviderInterface $scopes = null,
        private ExtensionMap $extensions = new ExtensionMap(),
        private ?StoreRegistry $stores = null,
    ) {
        PositiveInterval::require($reservationTtl, 'reservationTtl');
        PositiveInterval::require($deleteGracePeriod, 'deleteGracePeriod');
        ContentKeyCheck::assert($keys, $store, $scope, tenantBound: $scopes !== null);
    }

    /**
     * The same decorator over a base facade minting proxy URLs through another
     * generator — core's {@see Storage::withProxyUrls()}, reachable when
     * `StorageInterface` resolves to this class. A link minted outside the
     * tenant's request (queue job, mail) needs one pinned to the file's tenant,
     * e.g. `-web`'s `HmacProxyUrlGenerator::forScope($tenantId)`. The ledger,
     * the store and every other collaborator are shared with this instance.
     *
     * @throws InvalidConfigException When the wrapped facade is neither core's
     *         `Storage` nor another `DeduplicatingStorage`: it cannot swap its
     *         generator, and minting through the unpinned one would hand out a
     *         link that answers 404 — so it is refused by name instead.
     */
    public function withProxyUrls(?ProxyUrlGeneratorInterface $proxyUrls): self
    {
        if (!$this->unique instanceof Storage && !$this->unique instanceof self) {
            throw new InvalidConfigException(
                'withProxyUrls() needs the wrapped facade to be ' . Storage::class . ' or another '
                . self::class . '; ' . $this->unique::class . ' cannot change its proxy URL generator',
            );
        }

        return new self(
            unique: $this->unique->withProxyUrls($proxyUrls),
            ledger: $this->ledger,
            store: $this->store,
            keys: $this->keys,
            mimeTypeDetector: $this->mimeTypeDetector,
            idGenerator: $this->idGenerator,
            policies: $this->policies,
            clock: $this->clock,
            scope: $this->scope,
            dedupMaxBytes: $this->dedupMaxBytes,
            reservationTtl: $this->reservationTtl,
            deleteGracePeriod: $this->deleteGracePeriod,
            defaultGroup: $this->defaultGroup,
            scopes: $this->scopes,
            extensions: $this->extensions,
            stores: $this->stores,
        );
    }

    #[Override]
    public function add(
        Upload $upload,
        ?string $groupName = null,
        ?string $storeName = null,
        ?string $description = null,
        array $metadata = [],
    ): File {
        $groupName ??= $this->defaultGroup;
        if ($groupName === '' || preg_match(self::NAME_PATTERN, $groupName) !== 1) {
            throw new \InvalidArgumentException("Invalid group name \"{$groupName}\"");
        }

        $scopeId = $this->scopeId();

        // A store name that is not this one means the caller wants a specific
        // physical target, and this class only knows how to share into its own.
        if ($storeName !== null && $storeName !== $this->store->name()) {
            return $this->unique->add($upload, $groupName, $storeName, $description, $metadata);
        }

        $policy = $this->policies->for($groupName);
        $mimeType = $this->mimeTypeDetector->detect($upload);
        $policy->assertAcceptable($upload, $mimeType);

        try {
            $digest = $this->digestWithinLimit($upload, $policy->maxBytes);
        } catch (UploadSizeException $e) {
            throw $e;
        } catch (Throwable $e) {
            // A body that cannot be read to the end, or rewound after it was,
            // is this add failing — and `add()` promises AddException for
            // that, not the stream's own exception.
            throw new AddException('Failed to read the upload while hashing it', 0, $e);
        }

        if ($digest === null) {
            // Too large to address by content. A unique object is the honest
            // outcome, and the caller cannot tell the difference — in this
            // store, named explicitly: left null, the base facade picks the
            // registry's default, which is a different physical target
            // whenever deduplication was configured for another store.
            return $this->unique->add($upload, $groupName, $this->store->name(), $description, $metadata);
        }

        [$hash, $size] = $digest;
        $blob = BlobId::create(
            $this->store->name(),
            // Positional on purpose: the generator is an injected interface, and
            // PHP binds named arguments to the implementation's parameter names.
            $this->keys->generate(
                $this->scope->keyFor($groupName, $scopeId),
                $hash,
                // what the base facade would name the same file — and what
                // `filestorage:deduplicate` derives from the type the row records
                $this->extensions->extensionFor($mimeType),
            ),
        );

        return $this->share(
            $upload,
            $blob,
            $hash,
            $size,
            $groupName,
            $mimeType,
            $description,
            $metadata,
            $policy->maxBytes,
        );
    }

    #[Override]
    public function remove(string $id): bool
    {
        try {
            $file = $this->unique->find($id);
            $released = $this->ledger->releaseFile($id, $this->clock->now()->add($this->deleteGracePeriod));
        } catch (Throwable $e) {
            throw new RemoveException("Failed to release file \"{$id}\"", 0, $e);
        }

        if (!$released || !$file instanceof File) {
            return $released;
        }

        try {
            // Decided on the path the row was read with. A shared row's path
            // is a ledger blob, and those bytes belong to the collector. A row
            // that `filestorage:deduplicate` repointed between the read and
            // the release was read at its old unique path — an orphan from
            // that moment on, so deleting it is what the sweep would have done
            // later.
            if ($this->ledger->find(BlobId::create($file->storeName, $file->relativePath)) !== null) {
                return true;
            }

            // inside the try: the row is already gone, so a ledger that cannot
            // be read, or a store the registry no longer knows, is this remove
            // failing, too
            $store = $this->stores?->get($file->storeName)
                ?? ($file->storeName === $this->store->name() ? $this->store : null);
            $store?->delete($file);
        } catch (Throwable $e) {
            throw new RemoveException(
                "Removed metadata for \"{$id}\" but could not delete \"{$file->relativePath}\" "
                . "from store \"{$file->storeName}\"; it is now an orphan for filestorage:gc",
                0,
                $e,
            );
        }

        return true;
    }

    #[Override]
    public function find(string $id): ?File
    {
        return $this->unique->find($id);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->unique->exists($file);
    }

    #[Override]
    public function url(File $file): ?string
    {
        return $this->unique->url($file);
    }

    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt): ?string
    {
        return $this->unique->temporaryUrl($file, $expiresAt);
    }

    #[Override]
    public function urlFor(File $file, ?DateTimeImmutable $expiresAt = null): ?string
    {
        return $this->unique->urlFor($file, $expiresAt);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->unique->stream($file);
    }

    #[Override]
    public function content(File $file): ?string
    {
        return $this->unique->content($file);
    }

    /**
     * The tenant the content key is built for: null only when no provider is
     * bound. A bound provider returning null is a tenant that did not resolve,
     * and keying its upload into the `shared` pool would join it to every
     * other unresolved upload's blobs.
     *
     * @return non-empty-string|null
     *
     * @throws MissingScopeException
     */
    private function scopeId(): ?string
    {
        if (!$this->scopes instanceof FileScopeProviderInterface) {
            return null;
        }

        return $this->scopes->currentScopeId() ?? throw new MissingScopeException(sprintf(
            '%s is bound but returned no scope, and the content key of a deduplicated upload is built from the '
            . 'tenant. Establish the tenant before adding files',
            FileScopeProviderInterface::class,
        ));
    }

    /**
     * @param non-empty-string $hash
     * @param int<0, max> $size Counted while hashing. `Upload::size()` is null
     *        for a body that will not declare its length, and reserving zero
     *        there while committing the real size makes every later add of the
     *        same content fail on the ledger's size check.
     * @param non-empty-string $groupName
     * @param array<array-key, mixed> $metadata Narrowed by `File::create()`, which validates it.
     */
    private function share(
        Upload $upload,
        BlobId $blob,
        string $hash,
        int $size,
        string $groupName,
        ?string $mimeType,
        ?string $description,
        array $metadata,
        int $maxBytes,
    ): File {
        $now = $this->clock->now();

        // Built before anything is reserved or written, so metadata the row
        // refuses — or an id generator or clock that fails — leaves no blob
        // behind (core's review A2, same ordering). Only what the store
        // answers is filled in after the write.
        $draft = File::create(
            id: $this->idGenerator->generate(),
            storeName: $blob->storeName,
            groupName: $groupName,
            relativePath: $blob->relativePath(),
            originalName: $upload->originalName,
            // The counted size, not the store's. On the reuse branch a
            // content-addressable store returns the *existing* object's size
            // and — when the upload declared none — skips the mismatch check,
            // so a previously truncated object would put its short size on
            // this row while the blob records the real one. Counting while
            // hashing is the invariant this package states; the reservation
            // goes out with this number.
            size: $size,
            createdAt: $now,
            mimeType: $mimeType,
            description: $description,
            contentHash: $hash,
            metadata: $metadata,
        );

        $reservation = $this->ledger->reserve(
            blob: $blob,
            contentHash: $hash,
            size: $size,
            expiresAt: $now->add($this->reservationTtl),
        );

        try {
            $result = $this->store->putIfAbsent($upload, new StoredObjectId($blob->relativePath()), $maxBytes);

            $file = $result->relativePath === $draft->relativePath && $result->externalId === null
                ? $draft
                : File::create(
                    id: $draft->id,
                    storeName: $draft->storeName,
                    groupName: $draft->groupName,
                    relativePath: $result->relativePath,
                    originalName: $draft->originalName,
                    size: $draft->size,
                    createdAt: $draft->createdAt,
                    externalId: $result->externalId,
                    mimeType: $draft->mimeType,
                    description: $draft->description,
                    contentHash: $draft->contentHash,
                    metadata: $draft->metadata,
                );

            $this->ledger->commit($reservation, $file);
        } catch (Throwable $e) {
            // Releasing, never deleting. The bytes at this content key may
            // belong to a writer that committed a microsecond ago, and the
            // grace period plus the collector are what tell the difference.
            //
            // A release that fails too must not replace the failure being
            // reported — on MariaDB inside an application transaction it fails
            // on the very snapshot conflict the commit did. The reservation
            // expires on its own, and `filestorage:gc` sweeps it then.
            try {
                $this->ledger->release($reservation, $this->clock->now()->add($this->deleteGracePeriod));
            } catch (Throwable) {
            }

            throw $e instanceof BlobBusyException
                ? $e
                : new AddException("Failed to store \"{$blob->key()}\"", 0, $e);
        }

        return $file;
    }

    /**
     * The complete SHA-256 and the byte count, or null when the upload is
     * larger than dedup is willing to read twice.
     *
     * Also stops at the group's `maxBytes`, with the exception the base facade
     * throws for it: a body that declares no length passes the policy check
     * unmeasured, and the policy limit is usually far below `dedupMaxBytes`.
     *
     * Stops at the limit rather than hashing to the end and then discarding
     * the answer: the point of the cap is not to read the tail at all. The size
     * comes from this pass because `Upload::size()` is null for a body that
     * never declares its length.
     *
     * @return array{non-empty-string, int<0, max>}|null
     *
     * @throws UploadSizeException
     */
    private function digestWithinLimit(Upload $upload, int $maxBytes): ?array
    {
        $declared = $upload->size();
        if ($declared !== null && $declared > $this->dedupMaxBytes) {
            return null;
        }

        $stream = $upload->stream();
        $context = hash_init('sha256');
        $read = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK);
            if ($chunk === '') {
                break;
            }


            $read += \strlen($chunk);
            if ($maxBytes > 0 && $read > $maxBytes) {
                throw new UploadSizeException("Upload exceeds the {$maxBytes} byte limit for this group");
            }
            if ($read > $this->dedupMaxBytes) {
                $stream->rewind();

                return null;
            }

            hash_update($context, $chunk);
        }

        $stream->rewind();

        return [hash_final($context), $read];
    }
}
