<?php
declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Google_Client;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Google OAuth Token Verifier
 * Provides secure verification of Google ID tokens
 */
class GoogleOAuthVerifier
{
    private Google_Client $client;
    private LoggerInterface $logger;
    private string $clientId;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';

        // Don't fail construction when Google login isn't configured — only fail when a
        // token is actually verified. This lets the rest of the app (JWT/dev auth) work.
        $this->client = new Google_Client(['client_id' => $this->clientId]);
    }

    /**
     * Verify Google ID token and return payload
     * 
     * @param string $idToken The Google ID token to verify
     * @return array The verified payload containing user information
     * @throws InvalidArgumentException If token is invalid
     */
    public function verifyToken(string $idToken): array
    {
        if (empty($this->clientId)) {
            throw new InvalidArgumentException('Google login no está configurado (falta GOOGLE_CLIENT_ID).');
        }
        try {
            // Verify the token signature and claims
            $payload = $this->client->verifyIdToken($idToken);
            
            if (!$payload) {
                $this->logger->warning('Google token verification failed', [
                    'reason' => 'Invalid signature or expired token'
                ]);
                throw new InvalidArgumentException('Invalid Google token: verification failed');
            }

            // Verify the token was issued for this application
            if ($payload['aud'] !== $this->clientId) {
                $this->logger->warning('Google token audience mismatch', [
                    'expected' => $this->clientId,
                    'received' => $payload['aud']
                ]);
                throw new InvalidArgumentException('Invalid Google token: audience mismatch');
            }

            // Verify required claims are present
            $requiredClaims = ['sub', 'email', 'name', 'email_verified'];
            foreach ($requiredClaims as $claim) {
                if (!isset($payload[$claim])) {
                    $this->logger->warning('Google token missing required claim', [
                        'missing_claim' => $claim
                    ]);
                    throw new InvalidArgumentException("Invalid Google token: missing claim '{$claim}'");
                }
            }

            // Verify email is verified
            if (!$payload['email_verified']) {
                $this->logger->warning('Google token email not verified', [
                    'email' => $payload['email']
                ]);
                throw new InvalidArgumentException('Google account email is not verified');
            }

            $this->logger->info('Google token verified successfully', [
                'user_id' => $payload['sub'],
                'email' => $payload['email']
            ]);

            return $payload;

        } catch (\Exception $e) {
            $this->logger->error('Google token verification error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            
            throw new InvalidArgumentException('Failed to verify Google token: ' . $e->getMessage());
        }
    }

    /**
     * Get the configured Google Client ID
     * 
     * @return string
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }
}
