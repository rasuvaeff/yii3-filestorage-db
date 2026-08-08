<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageDb;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3FilestorageDb\Exception\InvalidFileRowException;

/**
 * Between a `File` and a database row.
 *
 * The direction that matters is inwards. A driver hands back whatever the
 * column and the platform agree on — `bigint` may arrive as a string, a
 * `text` column may hold JSON somebody edited by hand — and `File::create()`
 * has a narrow contract it will refuse. Coercing until it stops refusing is
 * how a corrupted row becomes a plausible file record pointing at the wrong
 * object, so every value is checked and a bad row is an exception instead.
 *
 * @internal
 */
final readonly class FileRowMapper
{
    /**
     * @return array<string, scalar|null>
     */
    public function toRow(File $file, ?BlobId $blob = null, ?string $scopeId = null): array
    {
        return [
            'id' => $file->id,
            'store_name' => $file->storeName,
            'external_id' => $file->externalId,
            'group_name' => $file->groupName,
            'relative_path' => $file->relativePath,
            'original_name' => $file->originalName,
            'mime_type' => $file->mimeType,
            'size' => $file->size,
            'description' => $file->description,
            'content_hash' => $file->contentHash,
            'metadata' => json_encode($file->metadata, \JSON_THROW_ON_ERROR),
            'created_at' => Timestamps::toStorage($file->createdAt),
            'updated_at' => Timestamps::toStorage($file->updatedAt),
            'blob_id' => $blob instanceof BlobId ? BlobRowId::for($blob) : null,
            'scope_id' => $scopeId,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidFileRowException
     */
    public function fromRow(array $row): File
    {
        try {
            return File::create(
                id: $this->text($row, 'id'),
                storeName: $this->text($row, 'store_name'),
                groupName: $this->text($row, 'group_name'),
                relativePath: $this->text($row, 'relative_path'),
                originalName: $this->text($row, 'original_name'),
                size: $this->size($row),
                createdAt: Timestamps::fromStorage($row['created_at'] ?? null, 'created_at'),
                externalId: $this->textOrNull($row, 'external_id'),
                mimeType: $this->textOrNull($row, 'mime_type'),
                description: $this->stringOrNull($row, 'description'),
                contentHash: $this->textOrNull($row, 'content_hash'),
                metadata: $this->metadata($row),
                updatedAt: Timestamps::fromStorage($row['updated_at'] ?? null, 'updated_at'),
            );
        } catch (InvalidArgumentException $e) {
            // File::create() rejected a value that survived the column checks —
            // a path with a `..` segment, a hash that is not SHA-256, an
            // updatedAt before createdAt. Still a bad row, not a bad caller.
            throw new InvalidFileRowException("Stored file row is invalid: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * The blob a row references, or null for a row the base facade wrote.
     *
     * @param array<string, mixed> $row
     *
     * @return non-empty-string|null
     */
    public function blobRowId(array $row): ?string
    {
        return isset($row['blob_id']) && \is_string($row['blob_id']) && $row['blob_id'] !== ''
            ? $row['blob_id']
            : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return non-empty-string
     *
     * @throws InvalidFileRowException
     */
    private function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new InvalidFileRowException("Column \"{$column}\" must hold a non-empty string");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return non-empty-string|null
     *
     * @throws InvalidFileRowException
     */
    private function textOrNull(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;
        if ($value === null) {
            return null;
        }
        // an empty string here is a row written by something other than this
        // package: the column is nullable, so "absent" already has a spelling
        if (!\is_string($value) || $value === '') {
            throw new InvalidFileRowException("Column \"{$column}\" must hold null or a non-empty string");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidFileRowException
     */
    private function stringOrNull(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw new InvalidFileRowException("Column \"{$column}\" must hold null or a string");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return int<0, max>
     *
     * @throws InvalidFileRowException
     */
    private function size(array $row): int
    {
        // `bigint` comes back as a string on several drivers, and as an int on
        // others; accepting both is not laxity, it is the actual contract
        $value = $row['size'] ?? null;
        if (\is_string($value) && preg_match('/^\d{1,19}\z/', $value) === 1) {
            // Not `(int)`: `PHP_INT_MAX + 1` is nineteen digits, so the pattern
            // bounding the length lets it through and the cast *saturates* to
            // `PHP_INT_MAX`. The row would then map to a plausible `File` whose
            // size is wrong — the coercion this class exists to refuse.
            //
            // And not `filter_var` alone: it accepts surrounding whitespace, so
            // `"12\n"` parses to 12. The pattern is the format gate, `filter_var`
            // is the range gate, and neither does the other's job.
            $parsed = filter_var($value, \FILTER_VALIDATE_INT);
            $value = $parsed === false ? null : $parsed;
        }
        if (!\is_int($value) || $value < 0) {
            throw new InvalidFileRowException('Column "size" must hold a non-negative integer');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<non-empty-string, scalar|null>
     *
     * @throws InvalidFileRowException
     */
    private function metadata(array $row): array
    {
        $value = $row['metadata'] ?? null;
        if ($value === null || $value === '') {
            return [];
        }
        if (!\is_string($value)) {
            throw new InvalidFileRowException('Column "metadata" must hold a JSON object');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidFileRowException('Column "metadata" does not hold valid JSON', 0, $e);
        }
        if (!\is_array($decoded)) {
            throw new InvalidFileRowException('Column "metadata" must hold a JSON object');
        }

        $metadata = [];
        /** @var mixed $item */
        foreach ($decoded as $key => $item) {
            if (!\is_string($key) || $key === '' || (!\is_scalar($item) && $item !== null)) {
                throw new InvalidFileRowException('Column "metadata" must hold a flat JSON object');
            }
            $metadata[$key] = $item;
        }

        return $metadata;
    }
}
