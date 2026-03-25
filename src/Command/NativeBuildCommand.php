<?php

namespace Akyos\UxNativeCliBundle\Command;

use Akyos\UxNativeCliBundle\Service\NativeBuildRunner;
use Akyos\UxNativeCliBundle\Service\NativeIosRunDestinationCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'native:build', description: 'Run native toolchain builds (Gradle / xcodebuild)')]
class NativeBuildCommand extends Command
{
    public function __construct(
        private NativeBuildRunner $buildRunner,
        private NativeIosRunDestinationCatalog $iosRunDestinationCatalog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('platform', 'p', InputOption::VALUE_REQUIRED, 'android, ios, or all', 'android');
        $this->addOption('install', null, InputOption::VALUE_NEGATABLE, 'Après un build réussi : Android → adb install -r ; iOS → appareil USB (devicectl) ou simulateur ciblé (simctl install)', true);
        $this->addOption('ios-target', null, InputOption::VALUE_REQUIRED, 'Filtre macOS : device (iphoneos + USB) ou simulator (simulateurs démarrés uniquement). Sans filtre, seules les destinations réellement disponibles sont proposées.');
        $this->addOption('ios-destination', null, InputOption::VALUE_REQUIRED, 'macOS : device:UDID ou simulator:UDID (évite les questions interactives, utile en CI).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $platform = (string) $input->getOption('platform');
        if (!\in_array($platform, ['android', 'ios', 'all'], true)) {
            $io->error('platform must be one of: android, ios, all');

            return Command::INVALID;
        }

        $iosTargetOption = $input->getOption('ios-target');
        if (null !== $iosTargetOption && '' !== $iosTargetOption && !\in_array($iosTargetOption, ['device', 'simulator'], true)) {
            $io->error('ios-target doit être device ou simulator');

            return Command::INVALID;
        }
        $iosTarget = ('' === $iosTargetOption || null === $iosTargetOption) ? null : (string) $iosTargetOption;

        $iosRunDestination = null;
        if ('Darwin' === \PHP_OS_FAMILY && ('ios' === $platform || 'all' === $platform)) {
            $resolved = $this->resolveIosRunDestination($input, $io, $iosTarget);
            if (null === $resolved) {
                return Command::FAILURE;
            }
            $iosRunDestination = $resolved;
        }

        $installAfterNative = (bool) $input->getOption('install');

        return $this->buildRunner->build($platform, $io, $installAfterNative, $iosRunDestination);
    }

    private function resolveIosRunDestination(InputInterface $input, SymfonyStyle $io, ?string $iosTarget): ?string
    {
        $explicit = $input->getOption('ios-destination');
        if (\is_string($explicit) && '' !== $explicit) {
            if (!preg_match('/^(device|simulator):[0-9A-Fa-f.-]+$/i', $explicit)) {
                $io->error('L’option --ios-destination doit être de la forme device:UDID ou simulator:UDID.');

                return null;
            }

            return $explicit;
        }

        $devices = $this->iosRunDestinationCatalog->listPhysicalDevices();
        $sims = $this->iosRunDestinationCatalog->listBootedSimulators();

        if ('device' === $iosTarget) {
            if ([] === $devices) {
                $io->error('Aucun appareil iOS USB détecté. Branchez un appareil déverrouillé (Faire confiance à cet ordinateur).');

                return null;
            }
            if (1 === \count($devices)) {
                return 'device:'.$devices[0]['udid'];
            }
            if ($input->isInteractive()) {
                $choices = [];
                foreach ($devices as $d) {
                    $choices['device:'.$d['udid']] = $d['label'].' (USB)';
                }
                $first = array_key_first($choices);
                $picked = $io->choice('Quel appareil USB utiliser pour le build iOS ?', $choices, $first);

                return \is_string($picked) ? $picked : (string) $first;
            }
            $io->error('Plusieurs appareils USB détectés. Indiquez --ios-destination=device:UDID (voir `xcrun devicectl list devices`).');

            return null;
        }

        if ('simulator' === $iosTarget) {
            if ([] === $sims) {
                $io->error('Aucun simulateur iOS démarré. Lancez un simulateur (Xcode → Open Simulator) puis relancez.');

                return null;
            }
            if (1 === \count($sims)) {
                return 'simulator:'.$sims[0]['udid'];
            }
            if ($input->isInteractive()) {
                $choices = [];
                foreach ($sims as $s) {
                    $choices['simulator:'.$s['udid']] = $s['label'].' (simulateur démarré)';
                }
                $first = array_key_first($choices);
                $picked = $io->choice('Quel simulateur démarré utiliser pour le build iOS ?', $choices, $first);

                return \is_string($picked) ? $picked : (string) $first;
            }
            $io->error('Plusieurs simulateurs démarrés. Indiquez --ios-destination=simulator:UDID (voir `xcrun simctl list devices booted`).');

            return null;
        }

        $choices = [];
        foreach ($devices as $d) {
            $choices['device:'.$d['udid']] = $d['label'].' (appareil USB)';
        }
        foreach ($sims as $s) {
            $choices['simulator:'.$s['udid']] = $s['label'].' (simulateur démarré)';
        }
        if ([] === $choices) {
            $io->error('Aucune destination iOS disponible : branchez un appareil USB ou démarrez au moins un simulateur iOS.');

            return null;
        }
        if (1 === \count($choices)) {
            return (string) array_key_first($choices);
        }
        if ($input->isInteractive()) {
            $first = array_key_first($choices);
            $picked = $io->choice('Destination iOS pour le build ?', $choices, $first);

            return \is_string($picked) ? $picked : (string) $first;
        }
        if ([] !== $sims) {
            return 'simulator:'.$sims[0]['udid'];
        }

        return 'device:'.$devices[0]['udid'];
    }
}
