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
     * Step 2 (command):   email with active token + command subject → parse JSON body → dispatch.
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
        $targetDomain = config('dka.target_domain');
        if ($targetDomain !== '*' && $fromParsed->domain !== $targetDomain) {
            Log::info('DKA: domain mismatch', ['from' => $fromParsed->domain, 'target' => $targetDomain]);
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
        //     Step 2 is triggered when:
        //       - An email-channel token is active in Redis for this email_id
        //       - AND the subject is 'register' or 'delete [selector]'
        //     Everything else is treated as Step 1 (challenge).
        // ------------------------------------------------------------------
        $emailId      = $fromParsed->email;
        $subjectNorm  = strtolower(trim((string) $subject));
        $tokenData    = $this->tokens->get($emailId);
        $hasEmailToken = $tokenData && ($tokenData['channel'] === 'email');

        $isRegister = $subjectNorm === 'register';
        $isDelete   = $subjectNorm === 'delete' || str_starts_with($subjectNorm, 'delete ');

        if ($hasEmailToken && ($isRegister || $isDelete)) {
            // Step 2 — parse JSON body and dispatch command
            $body    = $post['body-plain'] ?? $post['body'] ?? '';
            $payload = $this->parseJsonFromBody((string) $body);

            if ($payload === null) {
                if ($verbose) {
                    Mail::to($emailId)->send(new DkaMail(
                        $fromAddress,
                        'DKA: ' . ucfirst($subjectNorm) . ' Failed',
                        'Could not find valid JSON in the email body. The body must start with a JSON object.'
                    ));
                }
                return response('OK', 200);
            }

            if ($isRegister) {
                $this->dka->handleEmailRegister($emailId, $payload, $verbose, $fromAddress);
            } else {
                $selector = $subjectNorm === 'delete'
                    ? 'default'
                    : strtolower(trim(substr($subjectNorm, 7)));
                $this->dka->handleEmailDelete($emailId, $selector, $payload, $verbose, $fromAddress);
            }
        } else {
            // Step 1 — issue a verification token if DKIM passes
            $this->dka->handleEmailChallenge($emailId, $dkimCheck, $verbose, $fromAddress);
        }

        return response('OK', 200);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract and decode a JSON object from an email body.
     *
     * The first non-blank character must be '{'. Extracts from the opening
     * brace to its matching closing brace, ignoring any trailing content
     * (e.g. email signatures) that follows.
     */
    private function parseJsonFromBody(string $body): ?array
    {
        $len   = strlen($body);
        $start = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $body[$i];
            if ($c === '{') {
                $start = $i;
                break;
            }
            if ($c !== ' ' && $c !== "\n" && $c !== "\r" && $c !== "\t") {
                return null;
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
