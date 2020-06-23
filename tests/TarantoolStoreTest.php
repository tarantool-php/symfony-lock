<?php

namespace Tarantool\SymfonyLock\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Tarantool\Client\Client;
use Tarantool\Client\Schema\Criteria;
use Tarantool\SymfonyLock\ExpirationDaemon;
use Tarantool\SymfonyLock\SchemaManager;
use Tarantool\SymfonyLock\TarantoolStore;

class TarantoolStoreTest extends TestCase
{
    private Client $client;
    private PersistingStoreInterface $store;
    private SchemaManager $schema;

    protected function getStore(): PersistingStoreInterface
    {
        return $this->store;
    }

    public function setup()
    {
        $host = getenv('TARANTOOL_CONNECTION_HOST');
        $port = getenv('TARANTOOL_CONNECTION_PORT');

        $this->client = Client::fromDsn("tcp://$host:$port");
        $this->client->evaluate('box.session.su("admin")');

        $this->schema = new SchemaManager($this->client);
        $this->schema->setup();

        $this->store = new TarantoolStore($this->client);
    }

    public function tearDown()
    {
        $this->schema->tearDown();
    }

    public function testSave()
    {
        $store = $this->getStore();

        $key = new Key(uniqid(__METHOD__, true));

        $this->assertFalse($store->exists($key));
        $store->save($key);
        $this->assertTrue($store->exists($key));
        $store->delete($key);
        $this->assertFalse($store->exists($key));
    }

    public function testSaveWithDifferentResources()
    {
        $store = $this->getStore();

        $key1 = new Key(uniqid(__METHOD__, true));
        $key2 = new Key(uniqid(__METHOD__, true));

        $store->save($key1);
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        $store->save($key2);
        $this->assertTrue($store->exists($key1));
        $this->assertTrue($store->exists($key2));

        $store->delete($key1);
        $this->assertFalse($store->exists($key1));
        $this->assertTrue($store->exists($key2));

        $store->delete($key2);
        $this->assertFalse($store->exists($key1));
        $this->assertFalse($store->exists($key2));
    }

    public function testSaveWithDifferentKeysOnSameResources()
    {
        $store = $this->getStore();

        $resource = uniqid(__METHOD__, true);
        $key1 = new Key($resource);
        $key2 = new Key($resource);

        $store->save($key1);
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        try {
            $store->save($key2);
            $this->fail('The store shouldn\'t save the second key');
        } catch (LockConflictedException $e) {
        }

        // The failure of previous attempt should not impact the state of current locks
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        $store->delete($key1);
        $this->assertFalse($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        $store->save($key2);
        $this->assertFalse($store->exists($key1));
        $this->assertTrue($store->exists($key2));

        $store->delete($key2);
        $this->assertFalse($store->exists($key1));
        $this->assertFalse($store->exists($key2));
    }

    public function testSaveTwice()
    {
        $store = $this->getStore();

        $resource = uniqid(__METHOD__, true);
        $key = new Key($resource);

        $store->save($key);
        $store->save($key);

        // just asserts it don't throw an exception
        $this->addToAssertionCount(1);

        $store->delete($key);
    }

    public function testPutOffExpiration()
    {
        $store = $this->getStore();

        $resource = uniqid(__METHOD__, true);
        $key = new Key($resource);

        $store->save($key);

        $data = $this->client->getSpace('lock')->select(
            Criteria::key([ (string) $key ])
        );

        $this->assertCount(1, $data);

        $store->putOffExpiration($key, 600);

        $updated = $this->client->getSpace('lock')->select(
            Criteria::key([ (string) $key ])
        );
        $this->assertNotSame($data[0][2], $updated[0][2], "expiration was updated");
    }

    public function testExpirationDaemon()
    {
        $space = $this->client->getSpace('lock');
        $space->insert([ 'key1', 'token', microtime(true) ]);
        $space->insert([ 'key2', 'token', microtime(true) ]);
        $space->insert([ 'key3', 'token', microtime(true) ]);

        $expiration = new ExpirationDaemon($this->client);
        $this->assertSame(3, $expiration->process());
        $this->assertCount(0, $space->select(Criteria::key([])));
    }

    public function testExpirationDaemonLimit()
    {
        $space = $this->client->getSpace('lock');
        $expiration = new ExpirationDaemon($this->client, [
            'limit' => 1,
        ]);

        $space->insert([ 'key1', 'token', microtime(true) ]);
        $space->insert([ 'key2', 'token', microtime(true) ]);
        $space->insert([ 'key3', 'token', microtime(true) + 1 ]);

        $this->assertCount(3, $space->select(Criteria::key([])));

        $this->assertSame(1, $expiration->process());
        $this->assertCount(2, $space->select(Criteria::key([])));

        $this->assertSame(1, $expiration->process());
        $this->assertCount(1, $space->select(Criteria::key([])));

        // last record is not expired
        $this->assertSame(0, $expiration->process());
        $this->assertCount(1, $space->select(Criteria::key([])));
    }
}
