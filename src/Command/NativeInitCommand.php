<?php

namespace Akyos\UxNativeCliBundle\Command;

use Akyos\UxNativeCliBundle\Service\InitScaffolder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'native:init', description: 'Scaffold minimal Android and iOS shells from bundle templates')]
class NativeInitCommand extends Command
{
    public function __construct(
        private InitScaffolder $initScaffolder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite non-empty output directories')
            ->addOption('app-name', null, InputOption::VALUE_REQUIRED)
            ->addOption('url', null, InputOption::VALUE_REQUIRED)
            ->addOption('application-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('bundle-id', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $overrides = array_filter([
            'app_name' => $input->getOption('app-name'),
            'url' => $input->getOption('url'),
            'application_id' => $input->getOption('application-id'),
            'bundle_id' => $input->getOption('bundle-id'),
        ], static fn ($v) => null !== $v && '' !== $v);

        return $this->initScaffolder->scaffold($overrides, (bool) $input->getOption('force'), $io);
    }
}
