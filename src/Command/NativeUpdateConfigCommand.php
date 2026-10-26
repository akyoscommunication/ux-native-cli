<?php

namespace Akyos\UxNativeCliBundle\Command;

use Akyos\UxNativeCliBundle\Service\NativeProjectUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(name: 'native:update:config', description: 'Réinjecte la config native.yaml (URL, nom, barre du haut, icône) et ajoute les ponts manquants')]
class NativeUpdateConfigCommand extends Command
{
    public function __construct(
        private NativeProjectUpdater $projectUpdater,
        private KernelInterface $kernel,
        private array $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('notification', null, InputOption::VALUE_NONE, 'Ajoute le bridge notification-token s\'il manque')
            ->addOption('barcode-scanner', null, InputOption::VALUE_NONE, 'Ajoute le bridge barcode-scanner s\'il manque')
            ->addOption('admob', null, InputOption::VALUE_NONE, 'Ajoute le bridge admob s\'il manque')
            ->addOption('calendar', null, InputOption::VALUE_NONE, 'Ajoute le bridge calendar s\'il manque')
            ->addOption('game', null, InputOption::VALUE_NONE, 'Ajoute le bridge game s\'il manque');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectDir = $this->kernel->getProjectDir();
        $android = $this->config['android_path'] ?? Path::join($projectDir, 'native', 'android');
        $ios = $this->config['ios_path'] ?? Path::join($projectDir, 'native', 'ios');

        $status = $this->projectUpdater->apply(
            $this->config,
            $projectDir,
            $android,
            $ios,
            $io,
            (bool) $input->getOption('notification'),
            (bool) $input->getOption('barcode-scanner'),
            (bool) $input->getOption('admob'),
            (bool) $input->getOption('calendar'),
            (bool) $input->getOption('game'),
        );
        if (0 === $status) {
            $io->success('Configuration native mise à jour.');
        }

        return $status;
    }
}
