<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Tests\Functional\Ticket;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\PersistentCollection;
use Doctrine\ODM\MongoDB\Tests\BaseTest;

class GH2080Test extends BaseTest
{
    public function testReferencedDocumentPropertyInitialisation()
    {
        $tree = new GH2080Tree();
        $apple = new GH2080Apple();
        $apple->foo = 'bar';
        $tree->apples[] = $apple;

        $this->dm->persist($tree);
        $this->dm->persist($apple);
        $this->dm->flush();
        $this->dm->clear();

        /** @var GH2080Tree $tree */
        $tree = $this->dm->createQueryBuilder(GH2080Tree::class)->find()->getQuery()->getSingleResult();

        $this->assertEquals('bar', $tree->apples[0]->foo);
        $this->assertIsArray($tree->apples[0]->getExcludedFromArray());
    }
}


/**
 * @ODM\Document(collection="trees")
 */
class GH2080Tree extends GH2080BaseModel
{
    /**
     * @ODM\Id
     * @var string
     */
    public $_id;
    /**
     * @ODM\ReferenceMany(targetDocument=GH2080Apple::class, storeAs="ref", cascade="all")
     * @var GH2080Apple[]|PersistentCollection
     */
    public $apples;
}

/**
 * @ODM\Document(collection="apples")
 */
class GH2080Apple extends GH2080BaseModel
{
    /**
     * @ODM\Id
     * @var string
     */
    public $_id;
    /**
     * @ODM\Field(type="string")
     * @var string
     */
    public $foo;
}

class GH2080BaseModel
{
    protected $excludedFromArray = ['_id', 'lazyPropertiesDefaults'];
    private $preserveOriginalName = false;

    public function __construct(array $data = null)
    {
        if (!empty($data)) {
            foreach ($data as $key => $value) {
                $this->$key = $value;
            }
        }
    }

    public function __get($name)
    {
        $accessor = 'get_' . $this->snakeCase($name);
        if (method_exists($this, $accessor)) {
            return $this->$accessor();
        }
        return $this->$name ?? null;
    }

    public function __set($name, $value)
    {
        $mutator = 'set_' . $this->snakeCase($name);
        if (method_exists($this, $mutator)) {
            // an additional bool parameter ($toRemove = false) is provided
            $this->$mutator($value, false);
        } else {
            $this->$name = $value;
        }
    }

    public function __unset($name)
    {
        $this->__set($name, null);
    }

    public function __isset($name)
    {
        return $this->__get($name) !== null;
    }

    public function __clone()
    {
        foreach (get_object_vars($this) as $key => $value) {
            $this->$key = $this->cloneRecursively($value);
        }
    }

    /**
     * @return array
     */
    public function getExcludedFromArray(): array
    {
        return $this->excludedFromArray;
    }

    /**
     * @param array $excludedFromArray
     */
    public function setExcludedFromArray(array $excludedFromArray): void
    {
        $this->excludedFromArray = $excludedFromArray;
    }

    /**
     * @param mixed $item
     * @return mixed
     */
    private function cloneRecursively($item)
    {
        if (is_object($item)) {
            return clone $item;
        }
        if (is_array($item)) {
            return array_map([self::class, 'cloneRecursively'], $item);
        }
        return $item;
    }

    public function toArray(): array
    {
        $reflect = new \ReflectionClass($this);
        $result = [];
        foreach ($reflect->getProperties(\ReflectionProperty::IS_PUBLIC) as $key) {
            $key = $key->getName();
            if (strpos($key, '__') === 0
                || in_array($key, $this->getExcludedFromArray(), true)
                || method_exists($this, 'get_' . $this->snakeCase($key))) {
                continue;
            }
            $result[$key] = $this->toArrayRecursively($this->$key);
        }
        foreach ($reflect->getMethods() as $method) {
            $method = $method->getName();
            if (preg_match('/^get_(.+)$/', $method, $matches)) {
                $key = $matches[1];
                if (strpos($key, '__') === 0 || in_array($key, $this->getExcludedFromArray(), true)) {
                    continue;
                }
                $result[$key] = $this->toArrayRecursively($this->{$matches[0]}());
            }
        }
        return $result;
    }

    /**
     * @param mixed $item
     * @return mixed
     */
    private function toArrayRecursively($item)
    {
//        if ($item instanceof Carbon) {
//            return $item->format('c');
//        }
        if ($item instanceof \DateTimeInterface) {
//            return Carbon::instance($item)->format('c');
            return $item->format('c');
        }
//        if ($item instanceof Enum) {
//            return $item->getValue();
//        }
        if ($item instanceof \IteratorAggregate) {
            $item = $item->getIterator();
        }
        if ($item instanceof \Iterator) {
            $item = iterator_to_array($item);
        }
        if (is_object($item) && method_exists($item, 'toArray')) {
            return $item->toArray();
        }
        if (is_array($item)) {
            return array_map([self::class, 'toArrayRecursively'], $item);
        }
        return $item;
    }

    protected function snakeCase($name): string
    {
        if ($this->preserveOriginalName) {
            return $name;
        }
        return strtolower(preg_replace('/[^A-Z0-9]+/i', '_', $name));
    }
}
