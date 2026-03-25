<?php

namespace Akyos\UxNativeCliBundle\Command;

use Akyos\UxNativeCliBundle\Service\NativeAndroidApkLocator;
use Akyos\UxNativeCliBundle\Service\NativeBuildRunner;
use Akyos\UxNativeCliBundle\Service\NativeIosAppLocator;
use Akyos\UxNativeCliBundle\Service\NativeIosRunDestinationCatalog;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\ConsoleWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'native:qr-install',
    description: 'Sert APK et/ou IPA en HTTP et affiche un QR (détection Android/iOS sur l’URL racine si --platform=all)',
)]
class NativeQrInstallCommand extends Command
{
    public function __construct(
        private NativeAndroidApkLocator $apkLocator,
        private NativeIosAppLocator $iosLocator,
        private NativeBuildRunner $buildRunner,
        private NativeIosRunDestinationCatalog $iosRunDestinationCatalog,
        private string $bundleRoot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('platform', 'p', InputOption::VALUE_REQUIRED, 'android, ios, ou all (défaut : all — une seule URL, redirection selon le téléphone)', 'all')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Adresse IPv4 dans le QR (ex. IP LAN). Sinon détection automatique si possible.')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port d’écoute', '9876')
            ->addOption('build', 'b', InputOption::VALUE_NONE, 'Lancer native:build sans installation USB avant de servir les fichiers (Android et/ou iOS selon --platform)')
            ->addOption('apk', null, InputOption::VALUE_REQUIRED, 'Chemin absolu vers un .apk (ignore la sortie Gradle)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $platform = (string) $input->getOption('platform');
        if (!\in_array($platform, ['android', 'ios', 'all'], true)) {
            $io->error('platform doit être android, ios ou all');

            return Command::INVALID;
        }

        $isDarwin = 'Darwin' === \PHP_OS_FAMILY;
        if (('ios' === $platform || 'all' === $platform) && !$isDarwin) {
            if ('ios' === $platform) {
                $io->error('La plateforme ios nécessite macOS avec Xcode.');

                return 1;
            }
            $io->warning('Build et IPA iOS ignorés (macOS requis). Seul Android sera proposé si un APK est disponible.');
        }

        if ((bool) $input->getOption('build')) {
            if ('android' === $platform) {
                $code = $this->buildRunner->build('android', $io, false, null);
                if (0 !== $code) {
                    return $code;
                }
            } elseif ('ios' === $platform) {
                $devices = $this->iosRunDestinationCatalog->listPhysicalDevices();
                if ([] === $devices) {
                    $io->error('Pour --build côté iOS, aucun iPhone/iPad USB détecté. Branchez un appareil ou utilisez --platform=android.');

                    return 1;
                }
                $iosDest = 'device:'.$devices[0]['udid'];
                if (\count($devices) > 1) {
                    $io->warning(sprintf(
                        '%d appareils USB détectés — utilisation de « %s ». Pour en choisir un autre : native:build --ios-destination=device:UDID',
                        \count($devices),
                        $devices[0]['label']
                    ));
                }
                $code = $this->buildRunner->build('ios', $io, false, $iosDest);
                if (0 !== $code) {
                    return $code;
                }
            } else {
                if ($isDarwin) {
                    $devices = $this->iosRunDestinationCatalog->listPhysicalDevices();
                    if ([] === $devices) {
                        $io->error('Pour --build avec iOS, aucun iPhone/iPad USB détecté. Branchez un appareil pour l’IPA, ou lancez seulement Android (sans --build puis construisez Android à part).');

                        return 1;
                    }
                    $iosDest = 'device:'.$devices[0]['udid'];
                    if (\count($devices) > 1) {
                        $io->warning(sprintf(
                            '%d appareils USB détectés — utilisation de « %s » pour l’IPA. Pour en choisir un autre : native:build --ios-destination=device:UDID',
                            \count($devices),
                            $devices[0]['label']
                        ));
                    }
                    $code = $this->buildRunner->build('all', $io, false, $iosDest);
                    if (0 !== $code) {
                        return $code;
                    }
                } else {
                    $code = $this->buildRunner->build('android', $io, false, null);
                    if (0 !== $code) {
                        return $code;
                    }
                }
            }
        }

        $apkPath = null;
        $ipaPath = null;
        $ipaTemp = null;

        if ('android' === $platform || 'all' === $platform) {
            $apkOption = $input->getOption('apk');
            $apkPath = \is_string($apkOption) && '' !== $apkOption
                ? $apkOption
                : $this->apkLocator->resolveBuiltApkPath();
            if (null !== $apkPath && is_file($apkPath)) {
                $apkPath = Path::canonicalize($apkPath);
            } else {
                $apkPath = null;
            }
        }

        if (($platform === 'ios' || $platform === 'all') && $isDarwin) {
            $appPath = $this->iosLocator->resolveBuiltDeviceAppPath();
            if (null !== $appPath) {
                $ipaPath = $this->iosLocator->packAppBundleToTempIpa($appPath);
                if (null !== $ipaPath) {
                    $ipaTemp = $ipaPath;
                }
            }
        }

        if ('android' === $platform && (null === $apkPath || !is_file($apkPath))) {
            $io->error(sprintf(
                'APK introuvable. Chemin attendu : %s — Utilisez --build, ou native:build --platform=android --no-install, ou --apk=…',
                $this->apkLocator->getExpectedApkPath()
            ));

            return 1;
        }

        if ('ios' === $platform && (null === $ipaPath || !is_file($ipaPath))) {
            $io->error(sprintf(
                'IPA introuvable (build iOS appareil requis). Bundle attendu : %s — Utilisez native:qr-install --platform=ios --build ou native:build --platform=ios --install une fois pour produire le .app iphoneos.',
                $this->iosLocator->getExpectedDeviceAppPath()
            ));

            return 1;
        }

        if ('all' === $platform && (null === $apkPath || !is_file($apkPath)) && (null === $ipaPath || !is_file($ipaPath))) {
            $io->error('Aucun APK ni IPA disponible. Lancez native:qr-install --platform=all --build sur macOS, ou construisez au moins une plateforme.');

            return 1;
        }

        $hostOption = $input->getOption('host');
        $host = \is_string($hostOption) && '' !== $hostOption
            ? $hostOption
            : $this->guessLanIPv4();
        if (null === $host || '' === $host) {
            $io->error('Impossible de deviner l’adresse LAN. Indiquez --host=192.168.x.x');

            return 1;
        }

        $portRaw = $input->getOption('port');
        $basePort = is_numeric($portRaw) ? (int) $portRaw : 9876;
        if ($basePort < 1 || $basePort > 65535) {
            $io->error('Le port doit être compris entre 1 et 65535.');

            return Command::INVALID;
        }

        $routerPath = Path::join($this->bundleRoot, 'resources', 'router_serve_native.php');
        if (!is_file($routerPath)) {
            $io->error(sprintf('Script routeur introuvable : %s', $routerPath));

            return 1;
        }

        if (!$input->isInteractive()) {
            $io->error('Exécutez la commande dans un terminal interactif (sans --no-interaction).');

            return 1;
        }

        $port = $this->pickListenPort($basePort);
        if (null === $port) {
            $io->error(sprintf('Aucun port libre trouvé à partir de %d.', $basePort));

            return 1;
        }

        $publicBase = sprintf('http://%s:%d', $host, $port);
        if ('android' === $platform) {
            $qrUrl = $publicBase.'/d/app.apk';
        } elseif ('ios' === $platform) {
            $qrUrl = $publicBase.'/d/app.ipa';
        } else {
            $qrUrl = $publicBase.'/';
        }

        $apkForEnv = (null !== $apkPath && is_file($apkPath)) ? $apkPath : '';
        $ipaForEnv = (null !== $ipaPath && is_file($ipaPath)) ? $ipaPath : '';

        $process = new Process([PHP_BINARY, '-S', '0.0.0.0:'.$port, $routerPath], null, null, null, null);
        $process->start(null, [
            'NATIVE_APK_PATH' => $apkForEnv,
            'NATIVE_IPA_PATH' => $ipaForEnv,
            'NATIVE_PUBLIC_BASE' => $publicBase,
        ]);

        usleep(150000);
        if (!$process->isRunning()) {
            if (null !== $ipaTemp && is_file($ipaTemp)) {
                @unlink($ipaTemp);
            }
            $io->error('Le serveur PHP intégré n’a pas démarré. '.$process->getErrorOutput().$process->getOutput());

            return 1;
        }

        try {
            $io->section('Installation par QR code');
            $io->writeln(sprintf('<info>URL du QR :</info> %s', $qrUrl));
            if ('all' === $platform) {
                $io->writeln('Avec <info>all</info>, Android est redirigé vers l’APK et iPhone/iPad vers l’IPA selon le navigateur.');
            }
            $io->newLine();
            $io->writeln('Même réseau Wi‑Fi que cette machine. Android : télécharger l’APK puis installer. iOS : l’IPA en HTTP ne s’installe pas comme un APK (voir page ou Xcode).');
            $io->newLine();

            $qr = Builder::create()
                ->writer(new ConsoleWriter())
                ->data($qrUrl)
                ->build();
            $io->write($qr->getString());

            $io->newLine();
            $io->note('Pare-feu : autorisez le port si besoin.');
            $io->writeln('<comment>Appuyez sur Entrée pour arrêter le serveur.</comment>');
            fgets(STDIN);
        } finally {
            $process->stop(3);
            if (null !== $ipaTemp && is_file($ipaTemp)) {
                @unlink($ipaTemp);
            }
        }

        return 0;
    }

    private function guessLanIPv4(): ?string
    {
        if (!\extension_loaded('sockets')) {
            return null;
        }
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (false === $socket) {
            return null;
        }
        $connected = @socket_connect($socket, '8.8.8.8', 53);
        if (false === $connected) {
            @socket_close($socket);

            return null;
        }
        if (!socket_getsockname($socket, $addr) || !\is_string($addr)) {
            @socket_close($socket);

            return null;
        }
        @socket_close($socket);
        if ('0.0.0.0' === $addr || '' === $addr) {
            return null;
        }

        return $addr;
    }

    private function pickListenPort(int $basePort): ?int
    {
        for ($p = $basePort; $p < $basePort + 40 && $p <= 65535; ++$p) {
            $socket = @stream_socket_server('tcp://0.0.0.0:'.$p, $errno, $errstr);
            if (\is_resource($socket)) {
                fclose($socket);

                return $p;
            }
        }

        return null;
    }
}
