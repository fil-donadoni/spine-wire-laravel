<?php

namespace FilDonadoni\SpineWireLaravel\Storage;

use DateTimeInterface;
use Google\Cloud\Storage\Bucket;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;

class GoogleCloudStorageAdapter extends FilesystemAdapter
{
    public function __construct(
        FilesystemOperator $driver,
        \League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter $adapter,
        array $config,
        protected Bucket $bucket,
    ) {
        parent::__construct($driver, $adapter, $config);
    }

    public function url(string $path): string
    {
        $storageApiUri = $this->config['storage_api_uri'] ?? 'https://storage.googleapis.com';

        return rtrim($storageApiUri, '/') . '/' . $this->bucket->name() . '/' . ltrim($path, '/');
    }

    public function providesTemporaryUrls(): bool
    {
        return true;
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        $object = $this->bucket->object($this->prefixer->prefixPath($path));

        return $object->signedUrl($expiration, $options);
    }

    public function temporaryUploadUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        $object = $this->bucket->object($this->prefixer->prefixPath($path));

        return $object->signedUrl($expiration, array_merge($options, [
            'method' => 'PUT',
            'contentType' => $options['contentType'] ?? 'application/octet-stream',
            'version' => 'v4',
        ]));
    }
}
