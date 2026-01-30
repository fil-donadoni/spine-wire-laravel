<?php

namespace FilDonadoni\SpineWireLaravel\Storage;

use Google\Auth\SignBlobInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

/**
 * Signs blobs via the IAM Credentials API using an externally-provided access token.
 *
 * Calls the IAM signBlob API directly (without delegates) to avoid the
 * `implicitDelegation` permission. The caller's ADC token is used to
 * authenticate — only `roles/iam.serviceAccountTokenCreator` is needed.
 */
class IamSigner implements SignBlobInterface
{
    private const IAM_API = 'https://iamcredentials.googleapis.com/v1';
    private const SA_NAME = 'projects/-/serviceAccounts/%s';

    public function __construct(
        private string $serviceAccountEmail,
        private string $accessToken,
    ) {}

    public function signBlob($stringToSign, $forceOpenSsl = false)
    {
        $name = sprintf(self::SA_NAME, $this->serviceAccountEmail);
        $uri = self::IAM_API . '/' . $name . ':signBlob?alt=json';

        $request = new Request(
            'POST',
            $uri,
            [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],
            json_encode([
                'payload' => base64_encode($stringToSign),
            ]),
        );

        $client = new Client();
        $response = $client->send($request);
        $body = json_decode((string) $response->getBody(), true);

        return $body['signedBlob'];
    }

    public function getClientName(?callable $httpHandler = null)
    {
        return $this->serviceAccountEmail;
    }

    public function fetchAuthToken(?callable $httpHandler = null)
    {
        return ['access_token' => $this->accessToken];
    }

    public function getCacheKey()
    {
        return '';
    }

    public function getLastReceivedToken()
    {
        return ['access_token' => $this->accessToken];
    }
}
