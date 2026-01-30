<?php

namespace FilDonadoni\SpineWireLaravel\Storage;

use Google\Auth\Iam;
use Google\Auth\SignBlobInterface;

/**
 * Signs blobs via the IAM Credentials API using an externally-provided access token.
 *
 * This allows signing on behalf of a service account using the caller's own
 * credentials (e.g. a user's ADC token), avoiding the `implicitDelegation`
 * permission that would be required if the SA tried to sign for itself.
 */
class IamSigner implements SignBlobInterface
{
    public function __construct(
        private Iam $iam,
        private string $serviceAccountEmail,
        private string $accessToken,
    ) {}

    public function signBlob($stringToSign, $forceOpenSsl = false)
    {
        return $this->iam->signBlob($this->serviceAccountEmail, $this->accessToken, $stringToSign);
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
