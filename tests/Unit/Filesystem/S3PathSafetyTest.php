<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Psr7\Request;
use Marko\Filesystem\Exceptions\FilesystemException;
use Marko\Filesystem\Exceptions\PathException;
use Marko\Filesystem\S3\Filesystem\S3Filesystem;
use Marko\Filesystem\S3\Tests\Support\MockS3Client;

it('url-encodes each CopySource segment so query strings cannot select another version', function () {
    $client = MockS3Client::create([
        'copyObject' => fn (array $args) => new Result([]),
    ]);

    $filesystem = new S3Filesystem($client, MockS3Client::createConfig());
    $filesystem->copy('reports/old file.txt?versionId=abc', 'reports/new.txt');

    expect($client->calls[0]['args']['CopySource'])
        ->toBe('test-bucket/reports/old%20file.txt%3FversionId%3Dabc')
        ->and($client->calls[0]['args']['Key'])->toBe('reports/new.txt');
});

it('url-encodes the CopySource after applying the configured prefix', function () {
    $client = MockS3Client::create([
        'copyObject' => fn (array $args) => new Result([]),
    ]);

    $filesystem = new S3Filesystem($client, MockS3Client::createConfig('uploads'));
    $filesystem->copy('a+b#c.txt', 'dest.txt');

    expect($client->calls[0]['args']['CopySource'])->toBe('test-bucket/uploads/a%2Bb%23c.txt');
});

it('rejects paths containing dot-dot segments', function (string $path) {
    $client = MockS3Client::create();
    $filesystem = new S3Filesystem($client, MockS3Client::createConfig('tenant-a'));

    expect(fn () => $filesystem->read($path))->toThrow(PathException::class, 'Path traversal attempt detected')
        ->and($client->calls)->toBe([]);
})->with([
    'parent prefix' => '../tenant-b/secret.txt',
    'middle segment' => 'docs/../../tenant-b/secret.txt',
    'trailing segment' => 'docs/..',
    'bare' => '..',
]);

it('rejects dot-dot segments on the copy source and destination', function () {
    $client = MockS3Client::create();
    $filesystem = new S3Filesystem($client, MockS3Client::createConfig('tenant-a'));

    expect(fn () => $filesystem->copy('../tenant-b/secret.txt', 'stolen.txt'))->toThrow(PathException::class)
        ->and(fn () => $filesystem->copy('mine.txt', '../tenant-b/planted.txt'))->toThrow(PathException::class)
        ->and($client->calls)->toBe([]);
});

it('allows dots that are not whole segments', function () {
    $client = MockS3Client::create([
        'putObject' => fn (array $args) => new Result([]),
    ]);

    $filesystem = new S3Filesystem($client, MockS3Client::createConfig());
    $filesystem->write('releases/v1..2/notes..txt', 'x');

    expect($client->calls[0]['args']['Key'])->toBe('releases/v1..2/notes..txt');
});

it('rejects paths containing a NUL byte', function () {
    $client = MockS3Client::create();
    $filesystem = new S3Filesystem($client, MockS3Client::createConfig());

    expect(fn () => $filesystem->write("file.txt\0.jpg", 'x'))->toThrow(PathException::class, 'Invalid path')
        ->and($client->calls)->toBe([]);
});

it('rejects paths containing a backslash', function () {
    $client = MockS3Client::create();
    $filesystem = new S3Filesystem($client, MockS3Client::createConfig('tenant-a'));

    expect(fn () => $filesystem->read('..\\tenant-b\\secret.txt'))->toThrow(PathException::class, 'Invalid path')
        ->and($client->calls)->toBe([]);
});

it('still strips a leading slash from paths', function () {
    $client = MockS3Client::create([
        'putObject' => fn (array $args) => new Result([]),
    ]);

    $filesystem = new S3Filesystem($client, MockS3Client::createConfig('uploads'));
    $filesystem->write('/images/photo.jpg', 'x');

    expect($client->calls[0]['args']['Key'])->toBe('uploads/images/photo.jpg');
});

it('rejects temporary URL expirations outside 1 to 604800 seconds', function (int $expiration) {
    $client = MockS3Client::create();
    $filesystem = new S3Filesystem($client, MockS3Client::createConfig());

    expect(fn () => $filesystem->temporaryUrl('file.txt', $expiration))
        ->toThrow(FilesystemException::class, 'Invalid temporary URL expiration')
        ->and($client->calls)->toBe([]);
})->with([
    'zero' => 0,
    'negative' => -60,
    'over seven days' => 604801,
]);

it('accepts temporary URL expirations at the bounds', function (int $expiration) {
    $client = MockS3Client::create([
        'createPresignedRequest' => fn (CommandInterface $command, $expires, array $options) => new Request(
            'GET',
            'https://test-bucket.s3.us-east-1.amazonaws.com/file.txt?signed=true',
        ),
    ]);

    $filesystem = new S3Filesystem($client, MockS3Client::createConfig());

    expect($filesystem->temporaryUrl('file.txt', $expiration))
        ->toBe('https://test-bucket.s3.us-east-1.amazonaws.com/file.txt?signed=true');
})->with([
    'one second' => 1,
    'seven days' => 604800,
]);
