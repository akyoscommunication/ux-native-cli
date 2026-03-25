<?php

namespace Akyos\UxNativeCliBundle\Command;

use Akyos\UxNativeCliBundle\Service\NativeDevHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'native:dev', description: 'Show native dev workflow and optionally start symfony server')]
class NativeDevCommand extends Command
{
    public function __construct(
        private NativeDevHelper $devHelper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('server', null, InputOption::VALUE_NONE, 'Try to start symfony server:start when symfony binary is available');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        return $this->devHelper->run($io, (bool) $input->getOption('server'));
    }
}
