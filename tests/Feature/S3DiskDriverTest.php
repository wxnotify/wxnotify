<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

it('the s3 filesystem driver is installed and the s3 disk resolves', function () {
    config(['filesystems.disks.s3' => [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'us-east-1',
        'bucket' => 'wxnotify-test',
        'endpoint' => 'http://localhost:9000',
        'use_path_style_endpoint' => true,
        'throw' => false,
    ]]);

    $disk = Storage::disk('s3');

    expect($disk)->toBeInstanceOf(FilesystemAdapter::class)
        ->and($disk->getAdapter())->toBeInstanceOf(AwsS3V3Adapter::class);
});
