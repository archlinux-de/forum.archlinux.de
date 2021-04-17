<?php

namespace App\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Extension\Extension;
use Flarum\Extension\ExtensionManager;

class EnableExtensions extends AbstractCommand
{
    private ExtensionManager $extensionManager;

    public function __construct(ExtensionManager $extensionManager)
    {
        $this->extensionManager = $extensionManager;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('app:enable-extensions')
            ->setDescription('Enable all installed extensions');
    }

    protected function fire()
    {
        /** @var Extension $extension */
        foreach ($this->extensionManager->getExtensions() as $extension) {
            if (!$this->extensionManager->isEnabled($extension->getId())) {
                $this->output->writeln('Enabling extension ' . $extension->name);
                $this->extensionManager->enable($extension->getId());
            }
        }
    }
}
