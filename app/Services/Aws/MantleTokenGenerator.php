<?php

namespace App\Services\Aws;

use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Psr7\Request;

/**
 * Mints an Amazon Bedrock short-term API key (bearer token) from a team's AWS
 * credentials, WITHOUT calling AWS — the token is a SigV4-presigned request to
 * bedrock's `CallWithBearerToken` action, base64-encoded with a fixed prefix.
 * Mirrors the aws-bedrock-token-generator libraries (Python/JS/Java) which have
 * no PHP port.
 *
 * We use this at SETUP time only — to query the Mantle catalog and verify a
 * chosen model before a server exists. On the running EC2 box OpenClaw mints
 * its own token from the instance-profile role, so no key is ever stored there.
 *
 * The token authorises whatever Bedrock permissions the signing identity holds;
 * using it against the Mantle endpoint additionally requires the IAM action
 * `bedrock-mantle:CallWithBearerToken` (see the team-create wizard policy).
 */
class MantleTokenGenerator
{
    /** Bearer tokens carry this literal prefix; the Bedrock endpoint strips it. */
    private const PREFIX = 'bedrock-api-key-';

    /** Signed host — the same token is accepted by the Mantle endpoint. */
    private const HOST = 'bedrock.amazonaws.com';

    /** Max short-term key lifetime AWS allows (12h); we presign for the full window. */
    private const EXPIRES_SECONDS = 43200;

    /**
     * Build a bearer token string from static credentials. Returns a value ready
     * to send as `Authorization: Bearer <token>` or the `x-api-key` header.
     */
    public function generate(AwsCredentials $credentials): string
    {
        // Must byte-match AWS's aws-bedrock-token-generator (botocore
        // SigV4QueryAuth / smithy presign): a POST to
        // bedrock.amazonaws.com?Action=CallWithBearerToken, host the only signed
        // header, and the empty body hashed as SHA256('') (NOT UNSIGNED-PAYLOAD —
        // over HTTPS botocore leaves payload signing on, so it hashes the empty
        // body). Any deviation (GET, or unsigned payload) yields a signature
        // Bedrock rejects as invalid_api_key.
        $signer = new SignatureV4('bedrock', $credentials->region);

        $awsCredentials = new Credentials(
            $credentials->keyId,
            $credentials->secret,
            // Instance-profile / STS credentials carry a session token; static
            // IAM-user keys don't. Passed through when present so both work.
            $credentials->sessionToken,
        );

        $request = new Request(
            'POST',
            'https://'.self::HOST.'/?Action=CallWithBearerToken',
            ['host' => self::HOST],
        );

        $presigned = $signer->presign($request, $awsCredentials, '+'.self::EXPIRES_SECONDS.' seconds');

        // The token payload is the presigned URL without its scheme, with a
        // trailing &Version=1 (unsigned, matching AWS's own generators), base64'd.
        $uri = $presigned->getUri();
        $payload = self::HOST.$uri->getPath().'?'.$uri->getQuery().'&Version=1';

        return self::PREFIX.base64_encode($payload);
    }
}
