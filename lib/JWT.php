<?php
/**
 * JWT (JSON Web Token) Library
 *
 * Handles creation and verification of JWTs for authentication.
 * Supports both HS256 (HMAC) and RS256 (RSA) algorithms based on JWT_ALGORITHM environment variable.
 * - HS256: Uses shared secret (JWT_SECRET)
 * - RS256: Uses RSA private/public key pair
 */

class JWT {
    private static $privateKey = null;
    private static $publicKey = null;
    private static $keyId = 'teamselevated-key-1';

    /**
     * Generate a JWT token for authenticated user
     *
     * @param int|string $userId User's database ID
     * @param string $email User's email
     * @param string $name User's full name
     * @param array $additionalClaims Optional additional claims
     * @return string Signed JWT token
     */
    public static function generate($userId, $email, $name, $additionalClaims = []) {
        $algorithm = getenv('JWT_ALGORITHM') ?: 'HS256';

        // Header
        $header = [
            'typ' => 'JWT',
            'alg' => $algorithm
        ];

        // Only add kid for RS256
        if ($algorithm === 'RS256') {
            $header['kid'] = self::$keyId;
        }

        // Payload with standard claims
        $now = time();
        $payload = array_merge([
            'user_id' => (string)$userId, // Neon expects string
            'email' => $email,
            'name' => $name,
            'iat' => $now,
            'exp' => $now + (24 * 60 * 60), // 24 hours
            'nbf' => $now, // Not before
            'iss' => 'teamselevated', // Issuer
        ], $additionalClaims);

        // Encode header and payload
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        // Create signature
        $signatureInput = $headerEncoded . '.' . $payloadEncoded;

        if ($algorithm === 'HS256') {
            // HMAC-based signature
            $secret = getenv('JWT_SECRET');
            if (!$secret) {
                throw new Exception('JWT_SECRET not configured');
            }
            $signature = hash_hmac('sha256', $signatureInput, $secret, true);
            $signatureEncoded = self::base64UrlEncode($signature);
        } else {
            // RS256: RSA-based signature
            $signature = '';
            if (!openssl_sign($signatureInput, $signature, self::getPrivateKey(), OPENSSL_ALGO_SHA256)) {
                throw new Exception('Failed to sign JWT');
            }
            $signatureEncoded = self::base64UrlEncode($signature);
        }

        return $signatureInput . '.' . $signatureEncoded;
    }

    /**
     * Verify and decode a JWT token
     *
     * @param string $token JWT token to verify
     * @return object|false Decoded payload if valid, false otherwise
     */
    public static function verify($token) {
        try {
            $parts = explode('.', $token);

            if (count($parts) !== 3) {
                return false;
            }

            list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

            // Decode header to determine algorithm
            $header = json_decode(self::base64UrlDecode($headerEncoded));
            if (!$header || !isset($header->alg)) {
                return false;
            }

            $algorithm = $header->alg;

            // Verify signature
            $signature = self::base64UrlDecode($signatureEncoded);
            $signatureInput = $headerEncoded . '.' . $payloadEncoded;

            if ($algorithm === 'HS256') {
                // HMAC-based verification
                $secret = getenv('JWT_SECRET');
                if (!$secret) {
                    error_log('JWT verification error: JWT_SECRET not configured');
                    return false;
                }
                $expectedSignature = hash_hmac('sha256', $signatureInput, $secret, true);
                $verified = hash_equals($expectedSignature, $signature);
            } elseif ($algorithm === 'RS256') {
                // RSA-based verification
                $verified = openssl_verify(
                    $signatureInput,
                    $signature,
                    self::getPublicKey(),
                    OPENSSL_ALGO_SHA256
                ) === 1;
            } else {
                error_log('JWT verification error: Unsupported algorithm ' . $algorithm);
                return false;
            }

            if (!$verified) {
                return false;
            }

            // Decode payload
            $payload = json_decode(self::base64UrlDecode($payloadEncoded));

            if (!$payload) {
                return false;
            }

            // Check expiration
            if (isset($payload->exp) && $payload->exp < time()) {
                return false; // Token expired
            }

            // Check not before
            if (isset($payload->nbf) && $payload->nbf > time()) {
                return false; // Token not yet valid
            }

            return $payload;

        } catch (Exception $e) {
            error_log('JWT verification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Decode a JWT without verification (for debugging only)
     *
     * @param string $token JWT token
     * @return object|false Decoded payload
     */
    public static function decode($token) {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        $payload = json_decode(self::base64UrlDecode($parts[1]));
        return $payload ?: false;
    }

    /**
     * Get the private key for signing
     *
     * @return resource OpenSSL private key resource
     */
    private static function getPrivateKey() {
        if (self::$privateKey === null) {
            $keyPath = __DIR__ . '/../keys/private.pem';

            if (!file_exists($keyPath)) {
                throw new Exception('Private key not found. Run setup/generate-keys.php first.');
            }

            $keyContent = file_get_contents($keyPath);
            self::$privateKey = openssl_pkey_get_private($keyContent);

            if (self::$privateKey === false) {
                throw new Exception('Failed to load private key');
            }
        }

        return self::$privateKey;
    }

    /**
     * Get the public key for verification
     *
     * @return resource OpenSSL public key resource
     */
    private static function getPublicKey() {
        if (self::$publicKey === null) {
            $keyPath = __DIR__ . '/../keys/public.pem';

            if (!file_exists($keyPath)) {
                throw new Exception('Public key not found. Run setup/generate-keys.php first.');
            }

            $keyContent = file_get_contents($keyPath);
            self::$publicKey = openssl_pkey_get_public($keyContent);

            if (self::$publicKey === false) {
                throw new Exception('Failed to load public key');
            }
        }

        return self::$publicKey;
    }

    /**
     * Base64 URL encode (JWT standard)
     *
     * @param string $data Data to encode
     * @return string Base64 URL encoded string
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode (JWT standard)
     *
     * @param string $data Data to decode
     * @return string Decoded string
     */
    private static function base64UrlDecode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Get the key ID used for signing
     *
     * @return string Key ID
     */
    public static function getKeyId() {
        return self::$keyId;
    }
}
