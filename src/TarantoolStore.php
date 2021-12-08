<?php

namespace Tarantool\SymfonyLock;

use InvalidArgumentException;
use Symfony\Component\Lock\Exception\InvalidTtlException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\ExpiringStoreTrait;
use Tarantool\Client\Client;
use Tarantool\Client\Exception\RequestFailed;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;
use Tarantool\Client\Schema\Space;

class TarantoolStore implements PersistingStoreInterface
{
    use OptionalConstructor;
    use ExpiringStoreTrait;

    /**
     * Initialize database schema if not exists
     */
    protected bool $createSchema = false;

    /**
     * Expiration delay of locks in seconds
     */
    protected int $initialTtl = 300;

    /**
     * Space name
     */
    protected string $space = 'lock';

    protected function validateOptions()
    {
        if ($this->initialTtl <= 0) {
            $message = sprintf(
                'InitialTtl expects a strictly positive TTL. Got %d.',
                $this->initialTtl
            );
            throw new InvalidTtlException($message);
        }

        if ($this->space == '') {
            throw new InvalidArgumentException("Space should be defined");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(Key $key, ?string $token = null)
    {
        $arguments = [
            (string) $key,
            $token ?: $this->getUniqueToken($key),
        ];

        $script = <<<LUA
        local key, token = ...
        local tuple = box.space.$this->space:get(key)
        if tuple and tuple.token == token then
            box.space.$this->space:delete(tuple.key)
        end
        LUA;

        $this->client->evaluate($script, ...$arguments);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(Key $key): bool
    {
        try {
            $data = $this->client
                ->getSpace($this->space)
                ->select(Criteria::key([ (string) $key ]));

            if (count($data)) {
                [$tuple] = $data;
                return $tuple[1] == $this->getUniqueToken($key)
                    && $tuple[2] >= microtime(true);
            }
        } catch (RequestFailed $e) {
            // query failure means there is no valid space
            // it means that key is not exists in store
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function putOffExpiration(Key $key, float $ttl)
    {
        if ($this->exists($key)) {
            $key->resetLifetime();
            $key->reduceLifetime($ttl);

            $this->getSpace()->update(
                [ (string) $key ],
                Operations::set(2, $this->getExpirationTimestamp($key)),
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save(Key $key)
    {
        $key->reduceLifetime($this->initialTtl);

        try {
            $tuple = [
                (string) $key,
                $this->getUniqueToken($key),
                $this->getExpirationTimestamp($key),
            ];
            $this->getSpace()->insert($tuple);
            $this->checkNotExpired($key);
        } catch (RequestFailed $e) {
            $data = $this->getSpace()
                ->select(Criteria::key([ (string) $key ]));

            if (count($data)) {
                [$tuple] = $data;

                if ($tuple[1] == $this->getUniqueToken($key)) {
                    $this->checkNotExpired($key);
                    return true;
                }

                if ($tuple[2] < microtime(true)) {
                    $this->delete($key, $tuple[1]);
                    return $this->save($key);
                }
            }

            throw new LockConflictedException($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function getUniqueToken(Key $key): string
    {
        if (!$key->hasState(__CLASS__)) {
            $token = base64_encode(random_bytes(32));
            $key->setState(__CLASS__, $token);
        }

        return $key->getState(__CLASS__);
    }

    protected function getExpirationTimestamp(Key $key): float
    {
        return microtime(true) + $key->getRemainingLifetime();
    }

    protected function getSpace(): Space
    {
        try {
            return $this->client->getSpace($this->space);
        } catch (RequestFailed $e) {
            if ($this->createSchema) {
                $schema = new SchemaManager($this->client, [ 'space' => $this->space ]);
                $schema->setup();
                return $this->client->getSpace($this->space);
            }
            throw $e;
        }
    }
}
