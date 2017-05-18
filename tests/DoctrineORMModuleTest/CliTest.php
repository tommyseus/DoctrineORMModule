<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace DoctrineORMModuleTest;

use DoctrineORMModule\Module;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Application;
use Zend\EventManager\Event;

/**
 * Tests used to verify that command line functionality is active
 *
 * @license MIT
 * @link    http://www.doctrine-project.org/
 * @author  Marco Pivetta <ocramius@gmail.com>
 */
class CliTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var \Zend\ServiceManager\ServiceManager
     */
    protected $serviceManager;

    /**
     * @var \Symfony\Component\Console\Application
     */
    protected $cli;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * {@inheritDoc}
     */
    public function setUp()
    {
        $serviceManager     = ServiceManagerFactory::getServiceManager();
        /* @var $sharedEventManager \Zend\EventManager\SharedEventManagerInterface */
        $sharedEventManager = $serviceManager->get('SharedEventManager');
        /* @var $application \Zend\Mvc\Application */
        $application        = $serviceManager->get('Application');
        $invocations        = 0;

        $sharedEventManager->attach(
            'doctrine',
            'loadCli.post',
            function () use (&$invocations) {
                $invocations += 1;
            }
        );

        $application->bootstrap();
        $this->serviceManager = $serviceManager;
        $this->entityManager  = $serviceManager->get('doctrine.entitymanager.orm_default');
        $this->cli            = $serviceManager->get('doctrine.cli');
        $this->assertSame(1, $invocations);
    }

    public function testValidHelpers()
    {
        $helperSet = $this->cli->getHelperSet();

        /* @var $emHelper \Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper */
        $emHelper = $helperSet->get('em');
        $this->assertInstanceOf('Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper', $emHelper);
        $this->assertSame($this->entityManager, $emHelper->getEntityManager());

        /* @var $dbHelper \Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper */
        $dbHelper = $helperSet->get('db');
        $this->assertInstanceOf('Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper', $dbHelper);
        $this->assertSame($this->entityManager->getConnection(), $dbHelper->getConnection());
    }

    public function testOrmDefaultIsUsedAsTheEntityManagerIfNoneIsProvided()
    {
        $application = new Application();
        $event = new Event('loadCli.post', $application, ['ServiceManager' => $this->serviceManager]);

        $module = new Module();
        $module->initializeConsole($event);

        /* @var $entityManagerHelper \Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper */
        $entityManagerHelper = $application->getHelperSet()->get('entityManager');
        $this->assertInstanceOf('Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper', $entityManagerHelper);
        $this->assertSame($this->entityManager, $entityManagerHelper->getEntityManager());
    }

    /**
     * @backupGlobals enabled
     */
    public function testEntityManagerUsedCanBeSpecifiedInCommandLineArgument()
    {
        $connection = $this->getMockBuilder('Doctrine\DBAL\Connection')
            ->disableOriginalConstructor()
            ->getMock();

        $entityManager = $this->getMockbuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();

        $entityManager
            ->expects($this->atLeastOnce())
            ->method('getConnection')
            ->willReturn($connection);

        $this->serviceManager->setService('doctrine.entitymanager.some_other_name', $entityManager);

        $application = new Application();
        $event = new Event('loadCli.post', $application, ['ServiceManager' => $this->serviceManager]);

        $_SERVER['argv'][] = '--entitymanager=some_other_name';

        $module = new Module();
        $module->initializeConsole($event);

        /* @var $entityManagerHelper \Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper */
        $entityManagerHelper = $application->getHelperSet()->get('entityManager');
        $this->assertInstanceOf('Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper', $entityManagerHelper);
        $this->assertSame($entityManager, $entityManagerHelper->getEntityManager());
    }

    /**
     * @param string $commandName
     * @param string $className
     *
     * @dataProvider dataProviderForTestValidCommands
     */
    public function testValidCommands($commandName, $className)
    {
        /* @var $command \Symfony\Component\Console\Command\Command */
        $command = $this->cli->get($commandName);
        $this->assertInstanceOf($className, $command);

        // check for the entity-manager option
        $this->assertTrue($command->getDefinition()->hasOption('entitymanager'));

        $entityManagerOption = $command->getDefinition()->getOption('entitymanager');

        $this->assertTrue($entityManagerOption->isValueOptional());
        $this->assertFalse($entityManagerOption->isValueRequired());
        $this->assertFalse($entityManagerOption->isArray());
        $this->assertNull($entityManagerOption->getShortcut());
        $this->assertSame(
            'The name of the entitymanager to use. If none is provided, it will use orm_default.',
            $entityManagerOption->getDescription()
        );
    }

    /**
     * @return array
     */
    public function dataProviderForTestValidCommands()
    {
        return [
            [
                'dbal:import',
                'Doctrine\DBAL\Tools\Console\Command\ImportCommand',
            ],
            [
                'dbal:run-sql',
                'Doctrine\DBAL\Tools\Console\Command\RunSqlCommand',
            ],
            [
                'orm:clear-cache:query',
                'Doctrine\ORM\Tools\Console\Command\ClearCache\QueryCommand',
            ],
            [
                'orm:clear-cache:result',
                'Doctrine\ORM\Tools\Console\Command\ClearCache\ResultCommand',
            ],
            [
                'orm:generate-proxies',
                'Doctrine\ORM\Tools\Console\Command\GenerateProxiesCommand',
            ],
            [
                'orm:ensure-production-settings',
                'Doctrine\ORM\Tools\Console\Command\EnsureProductionSettingsCommand',
            ],
            [
                'orm:info',
                'Doctrine\ORM\Tools\Console\Command\InfoCommand',
            ],
            [
                'orm:schema-tool:create',
                'Doctrine\ORM\Tools\Console\Command\SchemaTool\CreateCommand',
            ],
            [
                'orm:schema-tool:update',
                'Doctrine\ORM\Tools\Console\Command\SchemaTool\UpdateCommand',
            ],
            [
                'orm:schema-tool:drop',
                'Doctrine\ORM\Tools\Console\Command\SchemaTool\DropCommand',
            ],
            [
                'orm:validate-schema',
                'Doctrine\ORM\Tools\Console\Command\ValidateSchemaCommand',
            ],
            [
                'orm:run-dql',
                'Doctrine\ORM\Tools\Console\Command\RunDqlCommand',
            ],
            [
                'migrations:generate',
                'Doctrine\DBAL\Migrations\Tools\Console\Command\GenerateCommand',
            ],
            [
                'migrations:diff',
                'Doctrine\DBAL\Migrations\Tools\Console\Command\DiffCommand',
            ],
            [
                'migrations:execute',
                'Doctrine\DBAL\Migrations\Tools\Console\Command\ExecuteCommand',
            ],
        ];
    }
}
