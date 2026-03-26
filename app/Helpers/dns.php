<?php

define('DKA_PATTERN', '/^\"v=DKA1;\s*dka=([a-zA-Z0-9.]+)\s*\"$/');

/**
 * Get the DNS designation from the DNS for the target domain claimed by this DKA.
 *
 *
 * @return string|null  {name, mailbox, host, email, domain} or null on failure
 */

function dns_text (): ?string
{
    $dnsData = dns_get_record(config('dka.dns_canonical'), DNS_TXT);
    if (!$dnsData || !is_array($dnsData)) {
        return null;
    }

    $dnsData = $dnsData[0];

    if (!is_array($dnsData) || !array_key_exists('entries', $dnsData) || count($dnsData['entries']) <> 1) {
        return null;
    }
    return $dnsData['txt'];
}


function dns_designation ($txt): ?string
{
    if (preg_match(DKA_PATTERN, $txt, $matches)) {
        return $matches[1];
    }
    $dnsData = dns_get_record(config('dka.dns_canonical'), DNS_TXT);
    if (!$dnsData || !is_array($dnsData)) {
        return null;
    }

    $dnsData = $dnsData[0];

    if (!is_array($dnsData) || !array_key_exists('entries', $dnsData) || count($dnsData['entries']) <> 1) {
        return null;
    }
    // return $dnsData['txt'];
    if (preg_match(DKA_PATTERN, $dnsData['txt'], $matches)) {
        return $matches[1];
    }
}

/**
 * Evaluate if the DNS designation is consistent with the claimed domain of this DKA.
 *
 *
 * @return boolean  {name, mailbox, host, email, domain} or null on failure
 */

function dns_designation_correct (): bool
{
    $dns_text = dns_text();
    if (!$dns_text) return false;
    switch (true)
    {
        case config('dka.target_domain') == "*" :
            return dns_designation($dns_text) == config('dka.rdka') ? true : false;
        default: return dns_designation($dns_text) == config('dka.target_domain');
    }
}
