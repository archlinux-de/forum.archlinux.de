<?php

namespace App\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Extension\Extension;
use Flarum\Extension\ExtensionManager;
use RuntimeException;

class EnableExtensions extends AbstractCommand
{
    public function __construct(private readonly ExtensionManager $extensionManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:enable-extensions')
            ->setDescription('Enable all installed extensions');
    }

    protected function fire(): int
    {
        foreach ($this->getResolvedExtensions() as $validExtension) {
            assert($validExtension instanceof Extension);
            $this->output->writeln('Enabling extension ' . $validExtension->name);
            $this->extensionManager->enable($validExtension->getId());
        }

        return self::SUCCESS;
    }

    /**
     * @return iterable<Extension>
     */
    private function getResolvedExtensions(): iterable
    {
        $resolvedExtensions = $this->extensionManager->resolveExtensionOrder(
            $this->extensionManager->getExtensions()->all()
        );
        assert(is_array($resolvedExtensions['valid']));
        if ($resolvedExtensions['missingDependencies'] || $resolvedExtensions['circularDependencies']) {
            throw new RuntimeException('Installed extensions have missing or circular dependencies.');
        }

        foreach ($resolvedExtensions['valid'] as $validExtension) {
            assert($validExtension instanceof Extension);
            if (!$this->extensionManager->isEnabled($validExtension->getId())) {
                yield $validExtension;
            }
        }
    }
}
