<?php

namespace Tests\Unit\Console;

use App\Console\EnableExtensions;
use Flarum\Extension\Extension;
use Flarum\Extension\ExtensionManager;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

class EnableExtensionsTest extends TestCase
{
    public function testEnablesNewExtensionsInDependencyOrder(): void
    {
        $dependency = new Extension('', ['name' => 'test/z-dependency']);
        $dependency->calculateDependencies([]);
        $dependent = new Extension('', ['name' => 'test/a-dependent', 'require' => ['test/z-dependency' => '*']]);
        $dependent->calculateDependencies(['test/z-dependency' => true]);
        $manager = $this->getMockBuilder(ExtensionManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensions', 'isEnabled', 'enable'])
            ->getMock();
        $manager->method('getExtensions')->willReturn(new Collection([
            $dependent->getId() => $dependent,
            $dependency->getId() => $dependency,
        ]));
        $manager->method('isEnabled')->willReturn(false);
        $enabled = [];
        $manager->expects($this->exactly(2))->method('enable')->willReturnCallback(
            static function (string $id) use (&$enabled): void {
                $enabled[] = $id;
            }
        );

        $command = new CommandTester(new EnableExtensions($manager));

        $this->assertSame(0, $command->execute([]));
        $this->assertSame(['test-z-dependency', 'test-a-dependent'], $enabled);
    }

    public function testSkipsEnabledDependenciesAndRepeatedActivation(): void
    {
        $dependency = new Extension('', ['name' => 'test/dependency']);
        $dependency->calculateDependencies([]);
        $extension = new Extension('', ['name' => 'test/new', 'require' => ['test/dependency' => '*']]);
        $extension->calculateDependencies(['test/dependency' => true]);
        $manager = $this->getMockBuilder(ExtensionManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensions', 'isEnabled', 'enable'])
            ->getMock();
        $manager->method('getExtensions')->willReturn(new Collection([
            $extension->getId() => $extension,
            $dependency->getId() => $dependency,
        ]));
        $enabled = ['test-dependency'];
        $manager->method('isEnabled')->willReturnCallback(
            static function (string $id) use (&$enabled): bool {
                return in_array($id, $enabled, true);
            }
        );
        $manager->expects($this->once())->method('enable')->with('test-new')->willReturnCallback(
            static function (string $id) use (&$enabled): void {
                $enabled[] = $id;
            }
        );

        $command = new CommandTester(new EnableExtensions($manager));

        $this->assertSame(0, $command->execute([]));
        $secondRun = new CommandTester(new EnableExtensions($manager));
        $this->assertSame(0, $secondRun->execute([]));
    }

    public function testRejectsMissingDependenciesBeforeEnablingAnything(): void
    {
        $extension = new Extension('', ['name' => 'test/new', 'require' => ['test/missing' => '*']]);
        $extension->calculateDependencies(['test/missing' => true]);
        $manager = $this->getMockBuilder(ExtensionManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensions', 'isEnabled', 'enable'])
            ->getMock();
        $manager->method('getExtensions')->willReturn(new Collection([$extension->getId() => $extension]));
        $manager->expects($this->never())->method('enable');
        $command = new CommandTester(new EnableExtensions($manager));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Installed extensions have missing or circular dependencies.');

        $command->execute([]);
    }

    public function testRejectsCircularDependenciesBeforeEnablingAnything(): void
    {
        $first = new Extension('', ['name' => 'test/first', 'require' => ['test/second' => '*']]);
        $second = new Extension('', ['name' => 'test/second', 'require' => ['test/first' => '*']]);
        $first->calculateDependencies(['test/second' => true]);
        $second->calculateDependencies(['test/first' => true]);
        $manager = $this->getMockBuilder(ExtensionManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensions', 'isEnabled', 'enable'])
            ->getMock();
        $manager->method('getExtensions')->willReturn(new Collection([
            $first->getId() => $first,
            $second->getId() => $second,
        ]));
        $manager->expects($this->never())->method('enable');
        $command = new CommandTester(new EnableExtensions($manager));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Installed extensions have missing or circular dependencies.');

        $command->execute([]);
    }
}
