<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Symfony\Kernel;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../../vendor/autoload.php';

/**
 * phpstan/phpstan-doctrine takes exactly one ObjectManager, and this project has two: the write
 * models on `default` and Matching's feed projection on `read_model` (see
 * config/packages/doctrine.php and docs/storage-map.md, path 3). Handing it only the default one makes
 * it crash on any query against the projection, with "not found in the chain configured
 * namespaces".
 *
 * So it gets a manager that answers metadata questions from whichever of the two actually knows
 * the class, and delegates everything else to the default one. Nothing below runs at runtime; it
 * exists so the analyser can see the whole mapping the application has.
 */
final class ChainedMetadataFactory implements ClassMetadataFactory
{
    /**
     * @param list<ObjectManager> $managers
     */
    public function __construct(private readonly array $managers)
    {
    }

    public function getAllMetadata(): array
    {
        $all = [];

        foreach ($this->managers as $manager) {
            foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
                $all[] = $metadata;
            }
        }

        return $all;
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        return $this->managerFor($className)->getMetadataFactory()->getMetadataFor($className);
    }

    public function hasMetadataFor(string $className): bool
    {
        foreach ($this->managers as $manager) {
            if ($manager->getMetadataFactory()->hasMetadataFor($className)) {
                return true;
            }
        }

        return false;
    }

    public function setMetadataFor(string $className, ClassMetadata $class): void
    {
        $this->managers[0]->getMetadataFactory()->setMetadataFor($className, $class);
    }

    public function isTransient(string $className): bool
    {
        foreach ($this->managers as $manager) {
            if (!$manager->getMetadataFactory()->isTransient($className)) {
                return false;
            }
        }

        return true;
    }

    private function managerFor(string $className): ObjectManager
    {
        foreach ($this->managers as $manager) {
            if (!$manager->getMetadataFactory()->isTransient($className)) {
                return $manager;
            }
        }

        return $this->managers[0];
    }
}

final class AllMappingsObjectManager implements ObjectManager
{
    private readonly ChainedMetadataFactory $metadataFactory;

    /**
     * @param list<ObjectManager> $managers
     */
    public function __construct(private readonly ObjectManager $default, array $managers)
    {
        $this->metadataFactory = new ChainedMetadataFactory($managers);
    }

    public function getMetadataFactory(): ClassMetadataFactory
    {
        return $this->metadataFactory;
    }

    public function getClassMetadata(string $className): ClassMetadata
    {
        return $this->metadataFactory->getMetadataFor($className);
    }

    public function find(string $className, mixed $id): ?object
    {
        return $this->default->find($className, $id);
    }

    public function persist(object $object): void
    {
        $this->default->persist($object);
    }

    public function remove(object $object): void
    {
        $this->default->remove($object);
    }

    public function clear(): void
    {
        $this->default->clear();
    }

    public function detach(object $object): void
    {
        $this->default->detach($object);
    }

    public function refresh(object $object): void
    {
        $this->default->refresh($object);
    }

    public function flush(): void
    {
        $this->default->flush();
    }

    public function getRepository(string $className): ObjectRepository
    {
        return $this->default->getRepository($className);
    }

    public function initializeObject(object $obj): void
    {
        $this->default->initializeObject($obj);
    }

    public function isUninitializedObject(mixed $value): bool
    {
        return $this->default->isUninitializedObject($value);
    }

    public function contains(object $object): bool
    {
        return $this->default->contains($object);
    }
}

new Dotenv()->bootEnv(__DIR__.'/../../.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

$registry = $kernel->getContainer()->get('doctrine');
\assert($registry instanceof ManagerRegistry);

return new AllMappingsObjectManager($registry->getManager(), array_values($registry->getManagers()));
