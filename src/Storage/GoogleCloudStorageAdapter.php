<?php

namespace FilDonadoni\SpineWireLaravel\Storage;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\SignBlobInterface;
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

        return $object->signedUrl($expiration, $this->signingOptions($options));
    }

    public function temporaryUploadUrl($path, $expiration, array $options = [])
    {
        $object = $this->bucket->object($this->prefixer->prefixPath($path));

        return $object->signedUrl($expiration, $this->signingOptions(array_merge($options, [
            'method' => 'PUT',
            'contentType' => $options['contentType'] ?? 'application/octet-stream',
            'version' => 'v4',
        ])));
    }

    /**
     * Add signing credentials to options when ADC cannot sign natively.
     *
     * On Cloud Run, GCECredentials implements SignBlobInterface and signedUrl()
     * works out of the box. Locally, UserRefreshCredentials cannot sign.
     * When `service_account` is configured, we build a custom SignBlobInterface
     * that calls the IAM signBlob API using the user's ADC token to sign on
     * behalf of the service account.
     */
    private function signingOptions(array $options): array
    {
        $serviceAccount = $this->config['service_account'] ?? null;
        if (!$serviceAccount) {
            return $options;
        }

        $scope = 'https://www.googleapis.com/auth/cloud-platform';
        $credentials = ApplicationDefaultCredentials::getCredentials($scope);

        if ($credentials instanceof SignBlobInterface) {
            return $options;
        }

        // Fetch an access token from the user's ADC credentials
        $token = $credentials->fetchAuthToken();
        $accessToken = $token['access_token'];

        // Use the IAM API to sign on behalf of the service account,
        // authenticated with the user's own access token
        $options['credentialsFetcher'] = new IamSigner($serviceAccount, $accessToken);

        return $options;
    }
}
