<?php

namespace Tarantool\SymfonyLock\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\InvalidTtlException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Tarantool\Client\Client;
use Tarantool\Client\Exception\RequestFailed;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;
use Tarantool\SymfonyLock\Cleaner;
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

    public function setUp(): void
    {
        $host = getenv('TARANTOOL_CONNECTION_HOST');
        $port = getenv('TARANTOOL_CONNECTION_PORT');

        $this->client = Client::fromDsn("tcp://$host:$port");
        $this->client->evaluate('box.session.su("admin")');

        $this->schema = new SchemaManager($this->client);
        $this->schema->setup();

        $this->store = new TarantoolStore($this->client);
    }

    public function tearDown(): void
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

    public function testCleaner()
    {
        $space = $this->client->getSpace('lock');
        $space->insert([ 'key1', 'token', microtime(true) ]);
        $space->insert([ 'key2', 'token', microtime(true) ]);
        $space->insert([ 'key3', 'token', microtime(true) ]);

        $cleaner = new Cleaner($this->client);
        $this->assertSame(3, $cleaner->process());
        $this->assertCount(0, $space->select(Criteria::key([])));
    }

    public function testCleanerLimit()
    {
        $space = $this->client->getSpace('lock');
        $cleaner = new Cleaner($this->client, [
            'limit' => 1,
        ]);

        $space->insert([ 'key1', 'token', microtime(true) ]);
        $space->insert([ 'key2', 'token', microtime(true) ]);
        $space->insert([ 'key3', 'token', microtime(true) + 1 ]);

        $this->assertCount(3, $space->select(Criteria::key([])));

        $this->assertSame(1, $cleaner->process());
        $this->assertCount(2, $space->select(Criteria::key([])));

        $this->assertSame(1, $cleaner->process());
        $this->assertCount(1, $space->select(Criteria::key([])));

        // last record is not expired
        $this->assertSame(0, $cleaner->process());
        $this->assertCount(1, $space->select(Criteria::key([])));
    }
    
    public function testSchemaInvalidEngine()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Engine should be defined");

        new SchemaManager(Client::fromDefaults(), [ 'engine' => '' ]);
    }

    public function testSchemaInvalidSpaceName()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Space should be defined");

        new SchemaManager(Client::fromDefaults(), [ 'space' => '' ]);
    }

    public function testCleanerInvalidLimit()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Limit expects a strictly positive. Got 0");

        new Cleaner(Client::fromDefaults(), [ 'limit' => 0 ]);
    }

    public function testCleanerInvalidSpaceName()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Space should be defined");

        new Cleaner(Client::fromDefaults(), [ 'space' => '' ]);
    }

    public function testStoreInvalidTtl()
    {
        $this->expectException(InvalidTtlException::class);
        $this->expectExceptionMessage("InitialTtl expects a strictly positive TTL. Got 0.");

        new TarantoolStore(Client::fromDefaults(), [ 'initialTtl' => 0 ]);
    }

    public function testStoreInvalidSpaceName()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Space should be defined");

        new TarantoolStore(Client::fromDefaults(), [ 'space' => '' ]);
    }

    public function testSuccessSchemaCreation()
    {
        $host = getenv('TARANTOOL_CONNECTION_HOST');
        $port = getenv('TARANTOOL_CONNECTION_PORT');

        $client = Client::fromDsn("tcp://$host:$port");
        $client->evaluate('box.session.su("admin")');

        $schema = new SchemaManager($client);
        $schema->tearDown();

        $store = new TarantoolStore($client, [ 'createSchema' => true ]);
        $store->save(new Key(uniqid(__METHOD__, true)));

        $tuples = $this->client->getSpace('lock')->select(Criteria::key([]));
        $this->assertCount(1, $tuples);
    }

    public function testDefaultSchemaCreationIsDisabled()
    {
        $host = getenv('TARANTOOL_CONNECTION_HOST');
        $port = getenv('TARANTOOL_CONNECTION_PORT');

        $client = Client::fromDsn("tcp://$host:$port");
        $client->evaluate('box.session.su("admin")');

        $schema = new SchemaManager($client);
        $schema->tearDown();

        $this->expectException(RequestFailed::class);
        $this->expectExceptionMessage("Space 'lock' does not exist");

        $store = new TarantoolStore($client);
        $store->save(new Key(uniqid(__METHOD__, true)));
    }

    public function testExpiredKeyOverwrite()
    {
        $resource = uniqid(__METHOD__, true);
        $key1 = new Key($resource);
        $key2 = new Key($resource);

        $store = $this->getStore();

        $store->save($key1);
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        $rows = $this->client->getSpace('lock')->select(Criteria::key([]));
        $this->assertCount(1, $rows);
        $this->client->getSpace('lock')->update([$rows[0][0]], Operations::set(2, microtime(true)));

        $this->assertFalse($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        $store->save($key2);

        $this->assertFalse($store->exists($key1));
        $this->assertTrue($store->exists($key2));
    }

    public function testExistsOnDroppedSpace()
    {
        $key = new Key(uniqid(__METHOD__, true));

        $store = $this->getStore();
        $store->save($key);

        $this->schema->tearDown();

        $this->assertFalse($store->exists($key));
    }
}
