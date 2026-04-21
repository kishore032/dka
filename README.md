## About DKA

The Domain Key Authority (DKA) framework is a DNS-anchored mechanism for 
distributing and discovering public keys associated with email-address 
identifiers. In this framework, an Internet domain designates, via a DNS 
record, a Domain Key Authority for collecting, verifying, storing, and 
distributing public keys for email-address identifiers under that domain. 
Public keys are scoped by selectors, enabling multiple keys per identifier 
for use by different applications. A Fallback DKA (fDKA) extends the 
framework to any email-address identifier regardless of whether its domain 
operates a DKA. The framework provides a decentralized, deterministic, and
application-agnostic public key distribution mechanism for the email address 
namespace.

This codebase is an open-source codebase that implements a DKA for a specified
domain. 

For more information on the DKA framework:
- <a href="https://keymail1.com/whitepaper" target="_blank">See the DKA white paper. </a>

- <a href="https://datatracker.ietf.org/doc/draft-swaminathan-dka-framework/" target="_blank">See the proposed IETF standards Internet Draft. </a>

- <a href="https://keymail1.com/demo" target="_blank">See the DKA demo.</a>


## Requirement

This package is a Laravel application in Laravel 13.2.
Requires PHP 8.2 or higher. 

This version uses Mailgun to send and receive email to and from the DKA. Laravel enables you to switch to several other email senders with simple configuration change. You may also use a full-fledged mail server for heavy duty use. 

This version uses SQlite as its database manager. Laravel enables you to switch to other databases with simple configuration change. 

This version uses Redis with a Predis client for managing timed tokens. To run this code, ensure that your Predis server is running.

## Installation

Clone this directory into your own server directory. 
- Run composer update
- In the databases directory, create a file named "database.sqlite"
- Copy .env.example to .env and set your Mailgun secrets and domain name parameters. 
- Shell Commands:
    -- php artisan migrate or php artisan migrate:refresh
    -- php artisan storage:link
    -- php artisan key:generate
- If this is to run as the dka of example.com at dka.example.com, set your DNS records as follows:


## DNS Configuration

If your domain is example.com, you'll need to set the following DNS records 

    _dka.example.com     TXT "v=DKA1; dka=dka.example.com"  ; designates your dka
    dka.example.com      A   your-server        ; locates your dka server
    www.dka.example.com  A   your-server        ; same

When you add your DKA domain to Mailgun, Mailgun will provide you several DNS records. You must include at least the following: SPF, DKIM and MX to your DNS. After you install these DNS records, go to Mailgun and it will check if they are properly set. 

## Mailgun: Routing incoming mail to your DKA

If your DNS records are set correctly, email sent to `dka@dka.example.com` will reach Mailgun. You need to set Mailgun to route it to your DKA. To do so, on Mailgun dashboard send/Receiving/Routes, set match_recipient(`dka@dka.example.com`) to forward to `dka.example.com/inbound`. 

## HTTPS Configuration for your DKA website

Your DKA website (`dka.example.com` and `www.dka.example.com`) must be served over TLS. You'll typically use Letsencrypt to serve these websites over TLS. Also, dont forget to reboot your webserver.

## Testing

If you are running DKA locally, you can set an ngrok url to your local DKA directory and set Mailgun to forward to this ngrok-url/inbound. From here on, you can trace the path of the incoming email. 

## Software License & IPR

The DKA software is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT). 

## IPR License

The DKA is covered by <a href="https://patents.google.com/patent/US12401508B2/"> US Patent US12401508B2 </a> and has been proposed as an <a href="https://datatracker.ietf.org/doc/draft-swaminathan-dka-framework/" target="_blank"> IETF standard </a>. It is offered royalty free  for any implementation conforming to the proposed standard. See the <a href="https://datatracker.ietf.org/ipr/7256/"> IPR terms. </a> 
