<?php

use App\Schemas\DocumentSchema;

return [
    'schemas' => [
        'documents' => DocumentSchema::class,
        'folders' => \App\Schemas\FolderSchema::class,
        'billing' => App\Schemas\BillingSchema::class,
        'missing' => App\Schemas\MissingSchema::class,
    ],

    'global' => [
        App\Schemas\TenancySchema::class,
        \App\Schemas\MisfiledSchema::class,
        App\Schemas\AuditSchema::class,
    ],
];
