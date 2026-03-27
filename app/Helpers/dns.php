<?php

define('DKA_PATTERN', '/^\s*\s*v\s*=\s*DKA1\s*;\s*dka\s*=\s*([a-zA-Z0-9.]+)*/');

/**
 * Get the DNS designation from the DNS for the target domain claimed by this DKA.
 * DNS data is returned as array of arrays.
    [
        [
        "host" => "_dka.keymail1.com",
        "class" => "IN",
        "ttl" => 3600,
        "type" => "TXT",
        "txt" => "v=DKA1; dka=dka.keymail1.com",
        "entries" => [
            "v=DKA1; dka=dka.keymail1.com",
        ],
        ],
    ]
 * @return string|null  {name, mailbox, host, email, domain} or null on failure
 */

function dns_text (): ?string
{
    $dns_data = dns_get_record(config('dka.dns_canonical'), DNS_TXT);
    if (!$dns_data || !is_array($dns_data)) {
        return null;
    }

    $dns_data = $dns_data[0];

    if (!is_array($dns_data) || !array_key_exists('entries', $dns_data) || count($dns_data['entries']) <> 1) {
        return null;
    }
    return $dns_data['txt'];
}

/**
 * Evaluate if the DNS designation is consistent with the claimed domain of this DKA.
 *
 *
 * @return boolean  {name, mailbox, host, email, domain} or null on failure
 */

function dns_designation (): ?string
{
    $dns_text = dns_text();
    if ($dns_text && preg_match(DKA_PATTERN, $dns_text, $matches)) {
        return $matches[1];
    } else {
        return null;
    }
}

function current_website_url(): ?string
{
    return $_SERVER['HTTP_HOST'];
}
