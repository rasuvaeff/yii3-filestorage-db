<?php

declare(strict_types=1);

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
    ],
];
