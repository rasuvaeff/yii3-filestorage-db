<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb\Command;

use DateInterval;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageDb\DbRepository;
use Rasuvaeff\Yii3FilestorageDb\DedupScope;
use Rasuvaeff\Yii3FilestorageDb\Exception\InvalidFileRowException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Migrates rows written before deduplication onto shared content-addressed
 * blobs.
 *
 * `filestorage:backfill-hash` computes integrity hashes and stops there — it
 * deliberately does not pretend that existing random paths became shared. This
 * command is the explicit, resumable step that actually moves them, one row at
 * a time and under the same protocol a live `add()` uses:
 *
 * 1. read the object the row points at, and hash it;
 * 2. `reserve()` the content key, so no collector can take it mid-flight;
 * 3. `putIfAbsent()` — a no-op when a previously migrated row already shared it;
 * 4. `commit()`, which repoints the row and records the reference in one
 *    transaction;
 * 5. `release()` on any failure, which schedules rather than deletes.
 *
 * **The old unique object is left behind on purpose.** It becomes an orphan the
 * moment its row is repointed, and `filestorage:gc --orphans --apply` reclaims
 * it. Deleting it here would race the readers still holding the old path, and
 * would do it inside the request-shaped window where a rollback is no longer
 * possible.
 *
 * Dry-run is the default, and a second run over the same range is a no-op:
 * a row already at its content key is recognised and skipped.
 *
 * **The tenant scope is the ambient one.** Rows come from `DbRepository`, which
 * filters by whatever `FileScopeProviderInterface` reports, and the content key
 * mixes that same scope in. A multi-tenant installation therefore runs this
 * once per tenant, with the tenant established the way its other CLI jobs
 * establish it. The scope in force is printed before any work starts.
 *
 * @api
 */
#[AsCommand(name: 'filestorage:deduplicate', description: 'Move existing files onto shared content-addressed blobs')]
final class DeduplicateCommand extends Command
{
    private const int CHUNK = 262_144;

    public function __construct(
        private readonly StoreRegistry $stores,
        private readonly DbRepository $repository,
        private readonly BlobLedgerInterface $ledger,
        private readonly StreamFactoryInterface $streams,
        private readonly ClockInterface $clock,
        private readonly ?FileScopeProviderInterface $scopes = null,
        private readonly ?ContentAddressedKeyGeneratorInterface $keys = null,
        private readonly DateInterval $reservationTtl = new DateInterval('PT15M'),
        private readonly DateInterval $deleteGracePeriod = new DateInterval('PT1H'),
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually migrate. Without this, nothing is written')
            ->addOption(
                'scope',
                null,
                InputOption::VALUE_REQUIRED,
                'Sharing boundary: tenant-group, tenant or global. Must match the running configuration',
                DedupScope::TenantGroup->value,
            )
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store to migrate within')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Resume after this file id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many rows', '1000')
            ->addOption(
                'max-bytes',
                null,
                InputOption::VALUE_REQUIRED,
                'Rows larger than this keep their unique object, as a live add would',
                '104857600',
            );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $options = $input->getOptions();

        $apply = (bool) $input->getOption('apply');
        $limit = max(1, (int) $input->getOption('limit'));
        $maxBytes = $this->maxBytesOption($options);
        if ($maxBytes === null) {
            $io->error(
                'The --max-bytes value must be a non-negative whole number of bytes. `100MB` is not one, and '
                . 'reading it as zero would silently lift the limit rather than apply it.',
            );

            return Command::FAILURE;
        }

        $scopeName = $this->stringOption($options, 'scope') ?? '';
        $scope = DedupScope::tryFrom($scopeName);
        if ($scope === null) {
            $io->error(sprintf(
                'Unknown scope "%s". Use one of: %s. It must be the same value the application passes to '
                    . 'DeduplicatingStorageFactory::create(), or migrated rows land on keys future uploads never join.',
                $scopeName,
                implode(', ', array_column(DedupScope::cases(), 'value')),
            ));

            return Command::FAILURE;
        }

        $store = $this->stores->get($this->stringOption($options, 'store'));
        if (!$store instanceof ContentAddressableStoreInterface) {
            $io->error(sprintf(
                'Store "%s" cannot deduplicate: it does not implement %s, so there is nothing to migrate onto.',
                $store->name(),
                ContentAddressableStoreInterface::class,
            ));

            return Command::FAILURE;
        }

        if (!$apply) {
            $io->note('Dry run. Nothing will be written — pass --apply to act.');
        }
        $io->text(sprintf(
            'Scope %s, tenant %s, store "%s".',
            $scope->value,
            $this->scopes?->currentScopeId() ?? '(none)',
            $store->name(),
        ));

        return $this->migrate($io, $store, $scope, $apply, $limit, $maxBytes, $this->stringOption($options, 'after'));
    }

    /**
     * @param int<0, max> $maxBytes
     * @param non-empty-string|null $after
     */
    private function migrate(
        SymfonyStyle $io,
        ContentAddressableStoreInterface $store,
        DedupScope $scope,
        bool $apply,
        int $limit,
        int $maxBytes,
        ?string $after,
    ): int {
        $seen = 0;
        $moved = 0;
        $shared = 0;
        $skipped = 0;
        $failed = 0;
        $lastId = $after;

        while ($seen < $limit) {
            try {
                $page = iterator_to_array($this->repository->files($lastId, min(500, $limit - $seen)), preserve_keys: false);
            } catch (InvalidFileRowException $e) {
                // files() is a generator and the mapper throws mid-iteration,
                // so one hand-edited row used to abort the whole run before
                // the summary and before the cursor was printed — leaving the
                // operator with no counts and no way to resume, and no id to
                // say which row to fix. Reported as a failure of this page.
                $failed++;
                $io->text(sprintf('  ! unreadable row after %s — %s', $lastId ?? '(start)', $e->getMessage()));

                break;
            }

            if ($page === []) {
                break;
            }

            foreach ($page as $file) {
                $seen++;
                $lastId = $file->id;

                $outcome = $this->migrateOne($io, $store, $scope, $apply, $maxBytes, $file);

                match ($outcome) {
                    Outcome::Moved => $moved++,
                    Outcome::AlreadyShared => $shared++,
                    Outcome::Skipped => $skipped++,
                    Outcome::Failed => $failed++,
                };
            }
        }

        $io->text(sprintf(
            '%s %d of %d row%s; %d already shared, %d skipped, %d failed.',
            $apply ? 'Migrated' : 'Would migrate',
            $moved,
            $seen,
            $seen === 1 ? '' : 's',
            $shared,
            $skipped,
            $failed,
        ));

        // The cursor is what makes a second invocation continue rather than
        // restart, and printing it is the whole resume story for an operator
        // working through millions of rows in bounded batches.
        if ($lastId !== null) {
            $io->text(sprintf('Last id: %s — resume with --after=%s', $lastId, $lastId));
        }

        if ($failed > 0) {
            $io->error(sprintf('%d row%s could not be migrated.', $failed, $failed === 1 ? '' : 's'));

            return Command::FAILURE;
        }

        if ($apply && $moved > 0) {
            $io->success($this->reclaimAdvice());
        }

        return Command::SUCCESS;
    }

    /**
     * Where the objects this run stranded actually get reclaimed.
     *
     * The plain answer is `gc --orphans --apply` — except that this command
     * runs under the ambient tenant scope, and that is exactly the condition
     * `gc --orphans` refuses on. Printing the short recipe to a multi-tenant
     * operator sends them to a command that will not run, with nothing
     * connecting the refusal back to here.
     */
    private function reclaimAdvice(): string
    {
        $reclaim = $this->scopes === null
            ? '`filestorage:gc --orphans --apply`'
            : sprintf(
                '`filestorage:gc --orphans --apply` run from a maintenance entry point that leaves %s unbound — '
                . 'the sweep refuses under a bound scope provider, because a tenant-filtered set of rows cannot '
                . 'prove an object unreferenced',
                FileScopeProviderInterface::class,
            );

        return sprintf(
            'Done. The objects the migrated rows used to point at are orphans now — reclaim them with %s once '
            . 'in-flight reads have drained.',
            $reclaim,
        );
    }

    /**
     * @param int<0, max> $maxBytes
     */
    private function migrateOne(
        SymfonyStyle $io,
        ContentAddressableStoreInterface $store,
        DedupScope $scope,
        bool $apply,
        int $maxBytes,
        File $file,
    ): Outcome {
        if ($file->storeName !== $store->name()) {
            return Outcome::Skipped;
        }

        if ($maxBytes > 0 && $file->size > $maxBytes) {
            // Exactly what a live add does above its own cap: keep the unique
            // object rather than read a multi-gigabyte body twice.
            $io->text(sprintf('  skipping %s: %d bytes is over --max-bytes', $file->id, $file->size));

            return Outcome::Skipped;
        }

        $stream = $store->stream($file);
        if ($stream === null) {
            $io->text(sprintf('  unreadable: %s', $file->id));

            return Outcome::Skipped;
        }

        $upload = Upload::fromStream($stream, $file->originalName, $this->streams);
        [$hash, $size] = $this->digest($upload);

        // Checked again against the *counted* number. The pre-check above uses
        // the recorded row size, and this class already documents that the row
        // can have drifted — so a row understating its size was read in full
        // regardless of the cap, which is the one thing the option exists to
        // prevent. Cheap here: the read has happened, but the second one — the
        // upload — has not.
        if ($maxBytes > 0 && $size > $maxBytes) {
            $io->text(sprintf(
                '  skipping %s: %d bytes counted is over --max-bytes, though the row said %d',
                $file->id,
                $size,
                $file->size,
            ));

            return Outcome::Skipped;
        }

        $blob = BlobId::create(
            $store->name(),
            ($this->keys ?? new Sha256KeyGenerator())->generate(
                $scope->keyFor($file->groupName, $this->scopes?->currentScopeId()),
                $hash,
            ),
        );

        if ($file->relativePath === $blob->relativePath()) {
            // Already at its content key. This is what makes a second run over
            // the same range a no-op rather than a second migration.
            return Outcome::AlreadyShared;
        }

        if (!$apply) {
            $io->text(sprintf('  would move %s to %s', $file->id, $blob->relativePath()));

            return Outcome::Moved;
        }

        return $this->share($io, $store, $file, $blob, $hash, $size, $maxBytes);
    }

    /**
     * @param non-empty-string $hash
     * @param int<0, max> $maxBytes Passed on, so the store refuses an oversized
     *        object rather than trusting a caller that already checked.
     * @param int<0, max> $size Counted while hashing, not read off the row.
     *        The two disagree exactly when `verify --deep` would have something
     *        to report, and a ledger row pairing the real hash with a stale size
     *        makes every later add of that same content fail on the mismatch.
     */
    private function share(
        SymfonyStyle $io,
        ContentAddressableStoreInterface $store,
        File $file,
        BlobId $blob,
        string $hash,
        int $size,
        int $maxBytes,
    ): Outcome {
        $reservation = $this->ledger->reserve(
            blob: $blob,
            contentHash: $hash,
            size: $size,
            expiresAt: $this->clock->now()->add($this->reservationTtl),
        );

        try {
            // Re-read rather than reuse the hashing stream: the object is the
            // source of truth, and a spooled copy would double peak disk for
            // nothing.
            $stream = $store->stream($file);
            if ($stream === null) {
                throw new \RuntimeException("Object for \"{$file->id}\" disappeared while it was being migrated");
            }

            $result = $store->putIfAbsent(
                Upload::fromStream($stream, $file->originalName, $this->streams),
                new StoredObjectId($blob->relativePath()),
                $maxBytes,
            );

            // Same id, same group, same metadata: this is the same file, at a
            // new physical address. `commit()` writes it through the repository,
            // so the tenant predicate still applies and the reference lands in
            // the same transaction.
            $this->ledger->commit($reservation, File::create(
                id: $file->id,
                storeName: $file->storeName,
                groupName: $file->groupName,
                relativePath: $result->relativePath,
                originalName: $file->originalName,
                size: $result->size,
                createdAt: $file->createdAt,
                externalId: $result->externalId,
                mimeType: $file->mimeType,
                description: $file->description,
                contentHash: $hash,
                metadata: $file->metadata,
                updatedAt: $this->clock->now(),
            ));
        } catch (Throwable $e) {
            $io->text(sprintf('  failed %s: %s', $file->id, $e->getMessage()));

            try {
                // Scheduling, never deleting: the bytes at this content key may
                // belong to a writer that committed a microsecond ago.
                $this->ledger->release($reservation, $this->clock->now()->add($this->deleteGracePeriod));
            } catch (Throwable $releaseFailure) {
                // A database outage is a likely cause of the original failure,
                // and the same outage makes the release fail — so letting this
                // escape would abort the run before the summary and the resume
                // cursor, which is exactly the loss this command takes care to
                // avoid elsewhere. Named instead, because a reservation nobody
                // released holds its blob until the sweep expires it.
                $io->text(sprintf(
                    '  ! could not release the reservation for %s (%s) — it expires on its own, and '
                    . '`filestorage:gc --apply` sweeps it',
                    $file->id,
                    $releaseFailure->getMessage(),
                ));
            }

            return Outcome::Failed;
        }

        return Outcome::Moved;
    }

    /**
     * The content hash and the byte count, from one pass over the object.
     *
     * The size is counted rather than taken from the row because the row can be
     * wrong — that is the whole reason `filestorage:verify --deep` exists — and
     * a blob reserved with the real hash beside a stale size rejects every
     * later add of that same content.
     *
     * @return array{non-empty-string, int<0, max>}
     */
    private function digest(Upload $upload): array
    {
        $stream = $upload->stream();
        $context = hash_init('sha256');
        $size = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK);
            if ($chunk === '') {
                break;
            }

            $size += \strlen($chunk);
            hash_update($context, $chunk);
        }

        $stream->rewind();

        return [hash_final($context), $size];
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return int<0, max>|null Null when the value is not a number at all.
     */
    private function maxBytesOption(array $options): ?int
    {
        $value = $options['max-bytes'] ?? null;
        if ($value === null) {
            return 0;
        }

        // Not a cast: `(int) '100MB'` is 0, and 0 means "no limit" here — so a
        // mistyped size silently removed the cap and made the command reread
        // every multi-gigabyte object twice.
        if (!\is_string($value) || preg_match('/^\d+\z/', $value) !== 1) {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return non-empty-string|null
     */
    private function stringOption(array $options, string $name): ?string
    {
        // Through the options array rather than getOption(): an array offset is
        // something psalm narrows across accesses, where a method call's
        // `mixed` needs a `@var` tag that rector removes as redundant.
        return isset($options[$name]) && \is_string($options[$name]) && $options[$name] !== ''
            ? $options[$name]
            : null;
    }
}
