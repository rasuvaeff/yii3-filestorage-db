<?php

declare(strict_types=1);

use Rasuvaeff\Yii3FilestorageDb\Command\DeduplicateCommand;

return [
    'rasuvaeff/yii3-filestorage-db' => [
        // one source of truth: the repository, the ledger and the bundled
        // migrations all read these through the table-name value objects
        'fileTable' => 'filestorage_file',
        'blobTable' => 'filestorage_blob',
        'blobReservationTable' => 'filestorage_blob_reservation',
        // prepended to every name above; set it once to keep every rasuvaeff
        // table out of the way of your application's own
        'tablePrefix' => '',
        // With a FileScopeProviderInterface bound, a null scope is refused.
        // true lets a null scope mean "every tenant" for reads, updates and
        // deletes — through MaintenanceRepositoryInterface and
        // BlobLedgerInterface only, the keys core's maintenance commands
        // resolve. RepositoryInterface, DbRepository and the deduplicating
        // storage keep refusing, so a queue worker sharing the console params
        // is not opened up.
        'globalMaintenanceAccess' => false,
    ],
    'yiisoft/yii-console' => [
        'commands' => [
            'filestorage:deduplicate' => DeduplicateCommand::class,
        ],
    ],
];
