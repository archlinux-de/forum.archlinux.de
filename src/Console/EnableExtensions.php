<?php

namespace App\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Extension\Extension;
use Flarum\Extension\ExtensionManager;

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

    protected function fire(): void
    {
        foreach ($this->getResolvedExtensions() as $validExtension) {
            assert($validExtension instanceof Extension);
            $this->output->writeln('Enabling extension ' . $validExtension->name);
            $this->extensionManager->enable($validExtension->getId());
        }
    }

    private function getResolvedExtensions(): iterable
    {
        $resolvedExtensions = $this->extensionManager->resolveExtensionOrder([...$this->getDisabledExtensions()]);
        assert(!empty($resolvedExtensions['valid']));
        assert(empty($resolvedExtensions['missingDependencies']));
        assert(empty($resolvedExtensions['circularDependencies']));

        foreach ($resolvedExtensions['valid'] as $validExtension) {
            assert($validExtension instanceof Extension);
            yield $validExtension;
        }
    }

    private function getDisabledExtensions(): iterable
    {
        foreach ($this->extensionManager->getExtensions() as $extension) {
            assert($extension instanceof Extension);
            if (!$this->extensionManager->isEnabled($extension->getId())) {
                yield $extension;
            }
        }
    }
}
