## About DKA

Doman Key Authority (DKA) collects, verifies, stores, and distributes public keys of email addresses belonging to a domain. This code implements a DKA for a domain in PHP/Laravel. 

## Requirement
This package is a Laravel application in Laravel 13.2.
Requirement PHP 8.2 or higher.

## Installation
Clone this directory into your own server directory. 
- Run composer update
- In the databases directory, create a file named "database.sqlite"
- Copy .env.example to .env
- Shell Commands:
    -- php artisan migrate or php artisan migrate:refresh
    -- php artisan storage:link
    -- php artisan key:generate

## Configuration






## License

The DKA software is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
