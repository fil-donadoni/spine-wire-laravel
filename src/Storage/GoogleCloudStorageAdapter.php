<?php

namespace FilDonadoni\SpineWireLaravel\Storage;

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

    public function url($path)
    {
        $storageApiUri = $this->config['storage_api_uri'] ?? 'https://storage.googleapis.com';

        return rtrim($storageApiUri, '/') . '/' . $this->bucket->name() . '/' . ltrim($path, '/');
    }

    public function providesTemporaryUrls()
    {
        return true;
    }

    public function temporaryUrl($path, $expiration, array $options = [])
    {
        $object = $this->bucket->object($this->prefixer->prefixPath($path));

        return $object->signedUrl($expiration, $options);
    }

    public function temporaryUploadUrl($path, $expiration, array $options = [])
    {
        $object = $this->bucket->object($this->prefixer->prefixPath($path));

        return $object->signedUrl($expiration, array_merge($options, [
            'method' => 'PUT',
            'contentType' => $options['contentType'] ?? 'application/octet-stream',
            'version' => 'v4',
        ]));
    }
}
