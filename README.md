# Tarantool symfony-lock
[![License](https://poser.pugx.org/tarantool/symfony-lock/license.png)](https://packagist.org/packages/tarantool/symfony-lock)
[![Build Status](https://travis-ci.org/tarantool-php/symfony-lock.svg?branch=master)](https://travis-ci.org/tarantool-php/symfony-lock)
[![Latest Version](https://img.shields.io/github/release/tarantool-php/symfony-lock.svg?style=flat-square)](https://github.com/tarantool-php/symfony-lock/releases)
[![Total Downloads](https://img.shields.io/packagist/dt/tarantool/symfony-lock.svg?style=flat-square)](https://packagist.org/packages/tarantool/symfony-lock)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/tarantool-php/symfony-lock/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/tarantool-php/symfony-lock/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/tarantool-php/mapper/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/tarantool-php/mapper/?branch=master)
[![Telegram](https://img.shields.io/badge/Telegram-join%20chat-blue.svg)](https://t.me/tarantool_php)

# About

The `TarantoolStore` implements symfony `PersistingStoreInterface` using Tarantool Database.

# Installation

The recommended way to install the library is through [Composer](http://getcomposer.org):
```
$ composer require tarantool/symfony-lock
```

# Prepare Tarantool

To start working on locking you need to create schema, the package contains schema manager that can be used for that. For additional documentation on client configuration see [tarantool/client repository](https://github.com/tarantool-php/client#creating-a-client).

```php
use Tarantool\Client\Client;
use Tarantool\SymfonyLock\SchemaManager;

$client = Client::fromDefaults();
$schema = new SchemaManager($client);

// create spaces and indexes
$schema->setup();

// later if you want to cleanup lock space, use
$schema->tearDown();

// in addition you can configure TarantoolStore to create schema on demand
// pay attention, this option is false by default
$store = new TarantoolStore($client, [
    'createSchema' => true,
]);

```

# Using Store

For additional examples on lock factory usage follow [symfony/lock docs](https://symfony.com/doc/current/components/lock.html) 

```php
use Symfony\Component\Lock\LockFactory;
use Tarantool\Client\Client;
use Tarantool\SymfonyLock\TarantoolStore;

$client = Client::fromDefaults();
$store = new TarantoolStore($client);
$factory = new LockFactory($store);

$lock = $factory->createLock('pdf-invoice-generation');

if ($lock->acquire()) {
    // The resource "pdf-invioce-generation" is locked.
    // You can compute and generate invoice safely here.
    $lock->release();
}

```

# Expiration helper

When key is expired it will be removed on acquiring new lock with same name. If your key names are not unique, you can use php cleaner to cleanup your database. The best way to cleanup data is start a fiber inside tarantool. For more details see [expirationd module documentation](https://github.com/tarantool/expirationd)

```php
use Tarantool\Client\Client;
use Tarantool\SymfonyLock\Cleaner;

$client = Client::fromDefaults();
$cleaner = new Cleaner($client);

// cleanup keys that are expired
$cleaner->process();

// by default cleaner will process upto 100 items
// you can override it via optional configuration
$cleaner = new Cleaner($client, [
    'limit' => 10,
]);

```

# Customization
 Out of the box all classes are using space named `lock`. If you want - you can override it via options configuration. All available options and default values are listed below:

```php
use Tarantool\Client\Client;
use Tarantool\SymfonyLock\Cleaner;
use Tarantool\SymfonyLock\SchemaManager;
use Tarantool\SymfonyLock\TarantoolStore;

$client = Client::fromDefaults();
$cleaner = new Cleaner($client, [
    'space' => 'lock',
    'limit' => '100',
]);

$schema = new SchemaManager($client, [
    'engine' => 'memtx',
    'space' => 'lock',
]);

$store = new TarantoolStore($client, [
    'space' => 'lock',
    'initialTtl' => 300,
    'createSchema' => false,
]);
```