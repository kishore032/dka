<?php

namespace App\Http\Controllers;

use App\Mail\DkaMail;
use App\Models\Rawmail;
use App\Services\DkaService;
use App\Services\TokenService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RawmailController extends Controller
{
    public function __construct(
        protected DkaService   $dka,
        protected TokenService $tokens,
    ) {}

    /**
     * Receive and process a Mailgun inbound webhook.
     *
     * Step 1 (challenge): any email with no active token → DKIM check → issue token.
     * Step 2 (command):   email with active token → parse JSON body → dispatch.
     *
     * Step 2 routing is determined entirely by the JSON payload:
     *   - "delete": true (no public_key)  → delete
     *   - "public_key" (no "delete": true) → register
     *   - both fields present             → rejected (conflict)
     *
     * In production (in_test_mode=false) reads directly from $_POST.
     * In test mode callers supply a $post array in the same shape.
     */
    public function receive($post = null, bool $in_test_mode = false): Response
    {
        if (!$in_test_mode) {
            $post = $_POST;
        }

        // ------------------------------------------------------------------
        // 1. Basic payload sanity
        // ------------------------------------------------------------------
        if (!is_array($post)) {
            return response('Unacceptable', 406);
        }

        // ------------------------------------------------------------------
        // 2. Require Message-Id
        // ------------------------------------------------------------------
        $messageId = $post['Message-Id'] ?? $post['message-id'] ?? null;
        if (!$messageId) {
            return response('Missing Message-Id', 406);
        }

        // ------------------------------------------------------------------
        // 3. Deduplicate — return 200 immediately if already logged
        // ------------------------------------------------------------------
        if (Rawmail::where('message_id', $messageId)->exists()) {
            return response('OK', 200);
        }

        // ------------------------------------------------------------------
        // 4. Verify Mailgun webhook signature
        // ------------------------------------------------------------------
        $timestamp  = $post['timestamp']  ?? '';
        $mgToken    = $post['token']      ?? '';
        $signature  = $post['signature']  ?? '';
        $signingKey = config('dka.mg_signing_key');

        if ($signingKey) {
            $computed = hash_hmac('sha256', $timestamp . $mgToken, $signingKey);
            if (!hash_equals($computed, $signature)) {
                return response('Unauthorized', 401);
            }
        }

        // ------------------------------------------------------------------
        // 5. Parse From address
        // ------------------------------------------------------------------
        $fromRaw    = $post['From'] ?? $post['from'] ?? $post['sender'] ?? '';
        $fromParsed = eparse($fromRaw);
        if (!$fromParsed) {
            Log::warning('DKA: unparseable From', ['raw' => $fromRaw, 'message_id' => $messageId]);
            return response('Invalid From', 406);
        }

        // ------------------------------------------------------------------
        // 6. Domain check (domain DKA mode only)
        // ------------------------------------------------------------------
        $mailDomain = config('dka.mail_domain');
        if ($mailDomain !== '*' && $fromParsed->domain !== $mailDomain) {
            Log::info('DKA: domain mismatch', ['from' => $fromParsed->domain, 'target' => $mailDomain]);
            return response('Domain not served', 403);
        }

        // ------------------------------------------------------------------
        // 7. Parse recipient — must be DKA_USERNAME or DKA_TERSE
        // ------------------------------------------------------------------
        $toRaw    = $post['recipient'] ?? $post['To'] ?? $post['to'] ?? '';
        $toParsed = eparse($toRaw);
        $dkaUser  = config('dka.username');
        $dkaTerse = config('dka.terse');

        if (!$toParsed || !in_array($toParsed->mailbox, [$dkaUser, $dkaTerse])) {
            Log::warning('DKA: unrecognised recipient', ['raw' => $toRaw]);
            return response('Invalid recipient', 406);
        }

        $verbose     = ($toParsed->mailbox === $dkaUser);
        $fromAddress = $toParsed->email;

        // ------------------------------------------------------------------
        // 8. Collect headers
        // ------------------------------------------------------------------
        $dkimCheck = $post['X-Mailgun-Dkim-Check-Result'] ?? '';
        $spamFlag  = $post['X-Mailgun-Sflag']             ?? '';
        $subject   = $post['subject'] ?? $post['Subject'] ?? '';

        // ------------------------------------------------------------------
        // 9. Store rawmail record (append-only log)
        // ------------------------------------------------------------------
        Rawmail::create([
            'message_id' => $messageId,
            'from_email' => $fromParsed->email,
            'to_email'   => $toParsed->email,
            'subject'    => substr((string) $subject, 0, 1024),
            'timestamp'  => $timestamp,
            'spam_flag'  => $spamFlag,
            'dkim_check' => $dkimCheck,
        ]);

        // ------------------------------------------------------------------
        // 10. Route: Step 1 (challenge) vs Step 2 (command)
        //
        //     Step 2 is triggered when ALL of the following are true:
        //       - An email-channel token is active in Redis for this sender
        //       - The body contains parseable JSON
        //       - That JSON contains "public_key" or "delete": true
        //
        //     Within Step 2, routing is determined by the JSON payload:
        //       - "delete": true (no public_key)   → delete operation
        //       - "public_key" (no "delete": true)  → register operation
        //       - both fields present               → rejected (conflict)
        //
        //     Everything else falls through to Step 1 (challenge / resend).
        // ------------------------------------------------------------------
        $emailId       = $fromParsed->email;
        $tokenData     = $this->tokens->get($emailId);
        $hasEmailToken = $tokenData && ($tokenData['channel'] === 'email');

        // Use stripped-text (Mailgun removes quoted reply content) so that
        // template JSON in the quoted challenge email is not mistaken for a
        // Step 2 command. Fall back to body-plain for non-reply emails.
        $strippedText = $post['stripped-text'] ?? '';
        $body         = ($strippedText !== '') ? $strippedText : ($post['body-plain'] ?? $post['body'] ?? '');
        $payload      = $this->parseJsonFromBody((string) $body);

        $hasDelete    = $payload !== null && isset($payload['delete']) && $payload['delete'] === true;
        $hasPublicKey = $payload !== null && isset($payload['public_key']) && $payload['public_key'] !== '';
        $isStep2      = $hasEmailToken && ($hasDelete || $hasPublicKey);

        if ($isStep2) {
            // Step 2 — dispatch command based on JSON payload fields
            if ($hasDelete && $hasPublicKey) {
                // Conflict: spec requires rejection when both fields are present
                if ($verbose) {
                    Mail::to($emailId)->send(new DkaMail(
                        $fromAddress,
                        'DKA: Command Failed',
                        'A payload may not contain both "public_key" and "delete": true.'
                    ));
                }
            } elseif ($hasDelete) {
                $this->dka->handleEmailDelete($emailId, $payload, $verbose, $fromAddress);
            } else {
                $this->dka->handleEmailRegister($emailId, $payload, $verbose, $fromAddress);
            }
        } else {
            // Step 1 — issue or resend a verification token if DKIM passes
            $this->dka->handleEmailChallenge($emailId, $dkimCheck, $verbose, $fromAddress);
        }

        return response('OK', 200);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract and decode the first JSON object from an email body.
     *
     * Scans for the first '{' anywhere in the body (reply emails may have
     * quoted text before the JSON). Extracts from that opening brace to its
     * matching closing brace, ignoring any trailing content (e.g. signatures).
     */
    private function parseJsonFromBody(string $body): ?array
    {
        $len   = strlen($body);
        $start = null;

        for ($i = 0; $i < $len; $i++) {
            if ($body[$i] === '{') {
                $start = $i;
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $depth    = 0;
        $inString = false;
        $escape   = false;
        $end      = null;

        for ($i = $start; $i < $len; $i++) {
            $c = $body[$i];

            if ($escape) {
                $escape = false;
                continue;
            }
            if ($c === '\\' && $inString) {
                $escape = true;
                continue;
            }
            if ($c === '"') {
                $inString = !$inString;
                continue;
            }
            if (!$inString) {
                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
        }

        if ($end === null) {
            return null;
        }

        $json    = substr($body, $start, $end - $start + 1);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
