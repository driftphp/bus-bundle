<?php

/*
 * This file is part of the DriftPHP Project
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 */

declare(strict_types=1);

namespace Drift\CommandBus\Tests\Async;

use function Clue\React\Block\await;
use function Clue\React\Block\awaitAll;
use Drift\CommandBus\Tests\BusFunctionalTest;
use Drift\CommandBus\Tests\Command\ChangeAnotherThing;
use Drift\CommandBus\Tests\Command\ChangeAThing;
use Drift\CommandBus\Tests\Command\ChangeBThing;
use Drift\CommandBus\Tests\Command\ChangeYetAnotherThing;
use Drift\CommandBus\Tests\CommandHandler\ChangeAnotherThingHandler;
use Drift\CommandBus\Tests\CommandHandler\ChangeAThingHandler;
use Drift\CommandBus\Tests\CommandHandler\ChangeYetAnotherThingHandler;
use Drift\CommandBus\Tests\Context;
use Drift\CommandBus\Tests\Middleware\Middleware1;

/**
 * Class AsyncAdapterTest.
 */
abstract class AsyncAdapterTest extends BusFunctionalTest
{
    /**
     * Decorate configuration.
     *
     * @param array $configuration
     *
     * @return array
     */
    protected static function decorateConfiguration(array $configuration): array
    {
        $configuration['services'][Context::class] = [];
        $configuration['services'][ChangeAThingHandler::class] = [
            'tags' => [
                ['name' => 'command_handler', 'method' => 'handle'],
                ['name' => 'another_tag', 'method' => 'anotherMethod'],
            ],
        ];

        $configuration['services'][ChangeAnotherThingHandler::class] = [
            'tags' => [
                ['name' => 'command_handler', 'method' => 'handle'],
            ],
        ];

        $configuration['services'][ChangeYetAnotherThingHandler::class] = [
            'tags' => [
                ['name' => 'command_handler', 'method' => 'handle'],
            ],
        ];

        $configuration['imports'] = [
            ['resource' => __DIR__.'/../autowiring.yml'],
        ];

        $configuration['command_bus'] = [
            'command_bus' => [
                'async_adapter' => static::getAsyncConfiguration(),
                'middlewares' => [
                    Middleware1::class.'::anotherMethod',
                ],
            ],
        ];

        return $configuration;
    }

    /**
     * Get async configuration.
     *
     * @return array
     */
    abstract protected static function getAsyncConfiguration(): array;

    /**
     * Test infrastructure.
     */
    public function testInfrastructure()
    {
        $output = $this->dropInfrastructure();
        $this->assertContains('dropped', $output);

        $output = $this->createInfrastructure();
        $this->assertContains('created properly', $output);

        $output = $this->checkInfrastructure();
        $this->assertContains('exists', $output);

        $output = $this->dropInfrastructure();
        $this->assertContains('dropped', $output);
    }

    /**
     * Test by reading only 1 command.
     */
    public function test1Command()
    {
        @unlink('/tmp/a.thing');
        $this->resetInfrastructure();

        $promise1 = $this
            ->getCommandBus()
            ->execute(new ChangeAThing('thing'));

        $promise2 = $this
            ->getCommandBus()
            ->execute(new ChangeBThing());

        $promise3 = $this
            ->getCommandBus()
            ->execute(new ChangeAnotherThing('thing'));

        await($promise1, $this->getLoop());
        await($promise2, $this->getLoop());
        await($promise3, $this->getLoop());

        if ($this instanceof InMemoryAsyncTest) {
            $this->assertNull($this->getContextValue('middleware1'));
        }

        $this->assertFalse(file_exists('/tmp/a.thing'));
        $output = $this->consumeCommands(1);

        $this->assertContains("\033[01;32mConsumed\033[0m ChangeAThing", $output);
        $this->assertNotContains("\033[01;32mConsumed\033[0m ChangeAnotherThing", $output);
        if ($this instanceof InMemoryAsyncTest) {
            $this->assertTrue($this->getContextValue('middleware1'));
        }

        $this->assertTrue(file_exists('/tmp/a.thing'));
        $output2 = $this->consumeCommands(1);

        $this->assertNotContains("\033[01;32mConsumed\033[0m ChangeAThing", $output2);
        $this->assertContains("\033[01;36mIgnored \033[0m ChangeBThing", $output2);
        $this->assertContains("\033[01;32mConsumed\033[0m ChangeAnotherThing", $output2);
    }

    /**
     * Test by reading 2 commands.
     */
    public function test2Commands()
    {
        $this->resetInfrastructure();

        $promises[] = $this
            ->getCommandBus()
            ->execute(new ChangeAThing('thing'));

        $promises[] = $this
            ->getCommandBus()
            ->execute(new ChangeAnotherThing('thing'));

        $promises[] = $this
            ->getCommandBus()
            ->execute(new ChangeYetAnotherThing('thing'));

        awaitAll($promises, $this->getLoop());

        $output = $this->consumeCommands(2);

        $this->assertContains("\033[01;32mConsumed\033[0m ChangeAThing", $output);
        $this->assertContains("\033[01;32mConsumed\033[0m ChangeAnotherThing", $output);
        $this->assertNotContains("\033[01;32mConsumed\033[0m ChangeYetAnotherThing", $output);
        $output = $this->consumeCommands(1);

        $this->assertNotContains("\033[01;32mConsumed\033[0m ChangeAThing", $output);
        $this->assertNotContains("\033[01;32mConsumed\033[0m ChangeAnotherThing", $output);
        $this->assertContains("\033[01;32mConsumed\033[0m ChangeYetAnotherThing", $output);
    }

    /**
     * Test async commands.
     */
    public function testAsyncCommands()
    {
        $this->resetInfrastructure();

        $process = $this->runAsyncCommand([
            'command-bus:consume-commands',
        ]);

        usleep(200000);

        $promise1 = $this
            ->getCommandBus()
            ->execute(new ChangeAThing('thing'));

        await($promise1, $this->getLoop());
        usleep(200000);

        $promises = [];
        $promises[] = $this
            ->getCommandBus()
            ->execute(new ChangeAnotherThing('thing'));

        $promises[] = $this
            ->getCommandBus()
            ->execute(new ChangeYetAnotherThing('thing'));

        awaitAll($promises, $this->getLoop());
        usleep(200000);
        $output = $process->getOutput();
        $this->assertContains("\033[01;32mConsumed\033[0m ChangeAThing", $output);
        $this->assertContains("\033[01;32mConsumed\033[0m ChangeAnotherThing", $output);
        $this->assertContains("\033[01;32mConsumed\033[0m ChangeYetAnotherThing", $output);
        $process->stop();
    }

    /**
     * Reset infrastructure.
     *
     * We wait .1 second to sure that the infrastructure is properly created
     */
    private function resetInfrastructure()
    {
        $this->dropInfrastructure();
        $this->createInfrastructure();
    }

    /**
     * Drop infrastructure.
     *
     * @return string
     */
    private function dropInfrastructure(): string
    {
        return $this->runCommand([
            'command-bus:infra:drop',
            '--force' => true,
        ]);
    }

    /**
     * Create infrastructure.
     *
     * @return string
     */
    private function createInfrastructure(): string
    {
        return $this->runCommand([
            'command-bus:infra:create',
            '--force' => true,
        ]);
    }

    /**
     * Consume commands.
     *
     * @param int $limit
     *
     * @return string
     */
    protected function consumeCommands(int $limit): string
    {
        $process = $this->runAsyncCommand([
            'command-bus:consume-commands',
            "--limit=$limit",
        ]);

        while ($process->isRunning()) {
            usleep(100000);
        }

        return $process->getOutput();
    }

    /**
     * Check infrastructure.
     *
     * @return string
     */
    private function checkInfrastructure(): string
    {
        return $this->runCommand([
            'command-bus:infra:check',
        ]);
    }
}
