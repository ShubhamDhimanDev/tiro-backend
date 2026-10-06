<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Block private hosts when downloading remote images
    |--------------------------------------------------------------------------
    |
    | Supplier sheets supply the image URLs, so downloads refuse hosts that
    | resolve to private/reserved addresses (SSRF protection). Only switch
    | this off for local debugging.
    |
    */

    'block_private_hosts' => env('MEDIA_BLOCK_PRIVATE_HOSTS', true),

    /*
    |--------------------------------------------------------------------------
    | Download supplier images after a catalog import
    |--------------------------------------------------------------------------
    |
    | When on, a real (non dry-run) catalog import queues a job per touched
    | tyre model that downloads its external images into local storage.
    |
    */

    'localize_on_import' => env('MEDIA_LOCALIZE_ON_IMPORT', true),

];
