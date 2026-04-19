<?php

namespace App\Http\Controllers;

use App\Models\PublicKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/lookup?email_address={email_id}[&selector={selector}]
    // -------------------------------------------------------------------------

    public function lookup(Request $request): JsonResponse
    {
        $email    = strtolower(trim($request->query('email_address', '')));
        $selector = strtolower(trim($request->query('selector', 'default')));

        if (!$email) {
            return response()->json([
                'error'   => 'invalid_request',
                'message' => 'email_address parameter is required',
            ], 400);
        }

        $domainCheck = $this->checkEmailDomain($email);
        if ($domainCheck) {
            return $domainCheck;
        }

        $row = PublicKey::findKey($email, $selector);
        if (!$row) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'No matching Public Key Record exists',
            ], 404);
        }

        return response()->json([
            'email_address'        => $row->email_id,
            'selector'             => $row->selector,
            'public_key'           => $row->public_key,
            'verification_methods' => json_decode($row->verification_methods ?? '[]'),
            'metadata'             => json_decode($row->metadata ?? '{}'),
            'version'              => $row->version,
            'updated_at'           => $row->updated_at?->toIso8601String(),
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/version
    // -------------------------------------------------------------------------

    public function version(): JsonResponse
    {
        return response()->json([
            'dka_version' => config('dka.version'),
            'domain'      => config('dka.mail_domain')
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/apis
    // -------------------------------------------------------------------------

    public function apis(): JsonResponse
    {
        return response()->json([
            'endpoints' => [
                ['method' => 'GET', 'path' => '/api/v1/lookup',  'params' => ['email_address', 'selector?']],
                ['method' => 'GET', 'path' => '/api/v1/version', 'params' => []],
                ['method' => 'GET', 'path' => '/api/v1/apis',    'params' => []],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * In Domain DKA mode, reject lookups for email addresses outside the mail domain.
     * Returns a JsonResponse on failure, or null if the check passes.
     */
    private function checkEmailDomain(string $email): ?JsonResponse
    {
        $mailDomain = config('dka.mail_domain');
        if ($mailDomain === '*') {
            return null;
        }

        $parsed = eparse($email);
        if (!$parsed || $parsed->domain !== $mailDomain) {
            return response()->json([
                'error'   => 'domain_not_served',
                'message' => 'This DKA does not serve that domain',
            ], 403);
        }

        return null;
    }
}
