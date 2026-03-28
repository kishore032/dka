<?php

namespace App\Services;

use App\Mail\DkaMail;
use App\Models\PublicKey;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DkaService
{
    public function __construct(
        protected TokenService $tokens,
    ) {}

    // -------------------------------------------------------------------------
    // Step 1 — Challenge
    // -------------------------------------------------------------------------

    /**
     * Handle a Step 1 challenge email.
     * Checks DKIM, issues a token, and sends it to the sender.
     * Silently ignores the request if a token already exists for this email_id.
     *
     * @param  string  $emailId     Normalised sender email address
     * @param  string  $dkimResult  Value of X-Mailgun-Dkim-Check-Result header
     * @param  bool    $verbose     True if recipient was DKA_USERNAME (send response)
     * @param  string  $fromAddress DKA email address to send from
     */
    public function handleEmailChallenge(
        string $emailId,
        string $dkimResult,
        bool   $verbose,
        string $fromAddress
    ): void {
        if (config('dka.DKIM_required') && strtolower($dkimResult) !== 'pass') {
            if ($verbose) {
                $this->sendEmail(
                    $emailId,
                    'DKA: DKIM Verification Failed',
                    "Your email did not pass DKIM verification and cannot be processed.\n\n"
                    . "Please ensure DKIM signing is enabled for your domain and try again.",
                    $fromAddress
                );
            }
            return;
        }

        // If a token already exists, resend it with the remaining TTL
        $existing = $this->tokens->get($emailId);
        if ($existing) {
            $token = $existing['token'];
            $ttl   = $this->tokens->ttl($emailId) ?? config('dka.token_ttl');

            $body = "Your DKA verification token:\n\n"
                . "  {$token}\n\n"
                . "This token expires in {$ttl} seconds.\n\n"
                . "To register a public key, reply with:\n"
                . "  Subject: register\n"
                . "  Body (JSON): {\"token\": \"<token>\", \"public_key\": \"<base64>\", \"selector\": \"<optional>\", \"metadata\": {}}\n\n"
                . "To delete a key, reply with:\n"
                . "  Subject: delete [selector]\n"
                . "  Body (JSON): {\"token\": \"<token>\"}\n";

            $this->sendEmail($emailId, 'DKA: Your Verification Token', $body, $fromAddress);
            return;
        }

        $token = $this->tokens->create($emailId, 'email');
        $ttl   = config('dka.token_ttl');

        $body = "Your DKA verification token:\n\n"
            . "  {$token}\n\n"
            . "This token expires in {$ttl} seconds.\n\n"
            . "To register a public key, reply with:\n"
            . "  Subject: register\n"
            . "  Body (JSON): {\"token\": \"<token>\", \"public_key\": \"<base64>\", \"selector\": \"<optional>\", \"metadata\": {}}\n\n"
            . "To delete a key, reply with:\n"
            . "  Subject: delete [selector]\n"
            . "  Body (JSON): {\"token\": \"<token>\"}\n";

        $this->sendEmail($emailId, 'DKA: Your Verification Token', $body, $fromAddress);
    }

    // -------------------------------------------------------------------------
    // Step 2 — Register
    // -------------------------------------------------------------------------

    /**
     * Handle a Step 2 register email.
     * Validates the token from the JSON payload, then upserts the public key.
     * Version starts at 1 for new keys and increments on each update.
     * Token is consumed only on success.
     *
     * @param  string  $emailId     Normalised sender email address
     * @param  array   $payload     Decoded JSON from the email body
     * @param  bool    $verbose     True if recipient was DKA_USERNAME (send response)
     * @param  string  $fromAddress DKA email address to send from
     */
    public function handleEmailRegister(
        string $emailId,
        array  $payload,
        bool   $verbose,
        string $fromAddress
    ): void {
        $tokenCheck = $this->validateToken($emailId, $payload['token'] ?? null);
        if (!$tokenCheck['valid']) {
            if ($verbose) {
                $this->sendEmail($emailId, 'DKA: Register Failed', $tokenCheck['error'], $fromAddress);
            }
            return;
        }

        $selector  = strtolower(trim($payload['selector'] ?? 'default'));
        $publicKey = trim($payload['public_key'] ?? '');
        $metadata  = $payload['metadata'] ?? null;

        if (!$this->isValidSelector($selector)) {
            if ($verbose) {
                $this->sendEmail(
                    $emailId,
                    'DKA: Register Failed',
                    "Invalid selector '{$selector}'. Must be lowercase alphanumeric (hyphens allowed), max 32 chars.",
                    $fromAddress
                );
            }
            return;
        }

        if ($publicKey === '') {
            if ($verbose) {
                $this->sendEmail($emailId, 'DKA: Register Failed', 'public_key is required.', $fromAddress);
            }
            return;
        }

        $metaJson = is_array($metadata)
            ? json_encode($metadata)
            : (is_string($metadata) ? $metadata : '{}');

        $existing = PublicKey::findKey($emailId, $selector);

        if ($existing) {
            $newVersion = $existing->version + 1;
            $existing->update([
                'public_key' => $publicKey,
                'metadata'   => $metaJson,
                'version'    => $newVersion,
                'verification_methods' => config('dka.DKIM_required') ? json_encode(['mailbox-control', 'dkim-pass']) :
                    json_encode(['mailbox-control'])
            ]);
            $message = "Selector '{$selector}' updated (version {$newVersion}).";
        } else {
            PublicKey::create([
                'email_id'   => $emailId,
                'selector'   => $selector,
                'public_key' => $publicKey,
                'metadata'   => $metaJson,
                'version'    => 1,
                'verification_methods' => config('dka.DKIM_required') ? json_encode(['mailbox-control', 'dkim-pass']) :
                    json_encode(['mailbox-control'])
            ]);
            $message = "Selector '{$selector}' registered.";
        }

        $this->tokens->delete($emailId);

        if ($verbose) {
            $this->sendEmail($emailId, 'DKA: Register Successful', $message, $fromAddress);
        }
    }

    // -------------------------------------------------------------------------
    // Step 2 — Delete
    // -------------------------------------------------------------------------

    /**
     * Handle a Step 2 delete email.
     * Validates the token from the JSON payload, then deletes the key.
     * Selector is derived from the subject line ('default' when omitted).
     * Token is consumed only on success.
     *
     * @param  string  $emailId     Normalised sender email address
     * @param  string  $selector    Selector to delete ('default' when none given)
     * @param  array   $payload     Decoded JSON from the email body (must contain 'token')
     * @param  bool    $verbose     True if recipient was DKA_USERNAME (send response)
     * @param  string  $fromAddress DKA email address to send from
     */
    public function handleEmailDelete(
        string $emailId,
        string $selector,
        array  $payload,
        bool   $verbose,
        string $fromAddress
    ): void {
        $tokenCheck = $this->validateToken($emailId, $payload['token'] ?? null);
        if (!$tokenCheck['valid']) {
            if ($verbose) {
                $this->sendEmail($emailId, 'DKA: Delete Failed', $tokenCheck['error'], $fromAddress);
            }
            return;
        }

        $existing = PublicKey::findKey($emailId, $selector);

        if (!$existing) {
            if ($verbose) {
                $this->sendEmail(
                    $emailId,
                    'DKA: Delete Failed',
                    "Selector '{$selector}' not found for your email address.",
                    $fromAddress
                );
            }
            return;
        }

        $existing->delete();
        $this->tokens->delete($emailId);

        if ($verbose) {
            $this->sendEmail(
                $emailId,
                'DKA: Delete Successful',
                "Selector '{$selector}' has been deleted.",
                $fromAddress
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate that a token exists in Redis, was issued for the email channel,
     * and matches the submitted value.
     *
     * @return array{valid: bool, error?: string}
     */
    private function validateToken(string $emailId, ?string $token): array
    {
        $stored = $this->tokens->get($emailId);

        if (!$stored) {
            return ['valid' => false, 'error' => 'No active token. Send a new email to request a token.'];
        }

        if ($stored['channel'] !== 'email') {
            return ['valid' => false, 'error' => 'Token was not issued for the email channel.'];
        }

        if ($stored['token'] !== $token) {
            return ['valid' => false, 'error' => 'Token value does not match. Token survives until expiry; try again.'];
        }

        return ['valid' => true];
    }

    /**
     * Validate selector format: lowercase alphanumeric (hyphens allowed), max 32 chars.
     */
    private function isValidSelector(string $selector): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $selector);
    }

    /**
     * Send a plain-text email.
     */
    private function sendEmail(string $to, string $subject, string $body, string $fromAddress): void
    {
        try {
            Mail::to($to)->send(new DkaMail($fromAddress, $subject, $body));
        } catch (\Exception $e) {
            Log::error('DKA: failed to send email', [
                'to'      => $to,
                'subject' => $subject,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
