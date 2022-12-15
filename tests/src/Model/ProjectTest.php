<?php

declare(strict_types=1);

namespace Tests\PHPCensor\Model;

use PHPCensor\Common\Exception\InvalidArgumentException;
use PHPCensor\DatabaseManager;
use PHPCensor\Model;
use PHPCensor\Model\Project;
use PHPCensor\Store\BuildStore;
use PHPCensor\Store\EnvironmentStore;
use PHPCensor\StoreRegistry;
use PHPUnit\Framework\TestCase;
use PHPCensor\Common\Application\ConfigurationInterface;

/**
 * Unit tests for the Project model class.
 *
 * @author Dan Cryer <dan@block8.co.uk>
 */
class ProjectTest extends TestCase
{
    private BuildStore $buildStore;
    private EnvironmentStore $environmentStore;
    private StoreRegistry $storeRegistry;

    protected function setUp(): void
    {
        $configuration   = $this->getMockBuilder(ConfigurationInterface::class)->getMock();
        $databaseManager = $this
            ->getMockBuilder(DatabaseManager::class)
            ->setConstructorArgs([$configuration])
            ->getMock();
        $this->storeRegistry = $this
            ->getMockBuilder(StoreRegistry::class)
            ->setConstructorArgs([$databaseManager])
            ->getMock();

        $this->buildStore = $this
            ->getMockBuilder(BuildStore::class)
            ->setConstructorArgs([$databaseManager, $this->storeRegistry])
            ->getMock();

        $this->environmentStore = $this
            ->getMockBuilder(EnvironmentStore::class)
            ->setConstructorArgs([$databaseManager, $this->storeRegistry])
            ->getMock();
    }

    public function testExecute_TestIsAValidModel()
    {
        $project = new Project($this->buildStore, $this->environmentStore);
        self::assertTrue($project instanceof Model);

        try {
            $project->setArchived(true);
        } catch (InvalidArgumentException $e) {
            self::assertEquals(
                'Column "archived" must be a bool.',
                $e->getMessage()
            );
        }
    }

    public function testExecute_TestProjectAccessInformation(): void
    {
        $info = [
            'item1' => 'Item One',
            'item2' => 2,
        ];

        $project = new Project($this->buildStore, $this->environmentStore);
        $project->setAccessInformation($info);

        self::assertEquals('Item One', $project->getAccessInformation('item1'));
        self::assertEquals(2, $project->getAccessInformation('item2'));
        self::assertNull($project->getAccessInformation('item3'));
        self::assertEquals($info, $project->getAccessInformation());
    }
}
