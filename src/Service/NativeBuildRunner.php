<?php

namespace Akyos\UxNativeCliBundle\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

class NativeBuildRunner
{
    public function __construct(
        private KernelInterface $kernel,
        private array $config,
        private NativeAndroidApkLocator $apkLocator,
        private NativeIosAppLocator $iosLocator,
        private NativeIosDeviceInstaller $iosDeviceInstaller,
    ) {
    }

    public function build(string $platform, SymfonyStyle $io, bool $installAfterNative = true, ?string $iosRunDestination = null): int
    {
        $projectDir = $this->kernel->getProjectDir();
        $androidRoot = $this->config['android_path'] ?? Path::join($projectDir, 'native', 'android');
        $iosRoot = $this->config['ios_path'] ?? Path::join($projectDir, 'native', 'ios');
        $task = $this->config['android_gradle_task'] ?? 'assembleDebug';
        $scheme = $this->config['ios_scheme'] ?? 'NativeApp';
        $iosProject = $this->config['ios_project'] ?? 'NativeApp.xcodeproj';

        if ('android' === $platform || 'all' === $platform) {
            $code = $this->runAndroid($androidRoot, $task, $io);
            if (0 !== $code) {
                return $code;
            }
            if ($installAfterNative) {
                $installCode = $this->installAndroidApk($androidRoot, $task, $io);
                if (0 !== $installCode) {
                    return $installCode;
                }
            }
        }

        if ('ios' === $platform || 'all' === $platform) {
            $iosForDevice = false;
            $iosDeviceUdid = null;
            $iosSimUdid = null;
            if ('Darwin' === \PHP_OS_FAMILY) {
                if (\is_string($iosRunDestination) && str_starts_with($iosRunDestination, 'device:')) {
                    $iosForDevice = true;
                    $iosDeviceUdid = substr($iosRunDestination, 7);
                    if ('' === $iosDeviceUdid) {
                        $io->error('Destination iOS appareil invalide (UDID vide).');

                        return 1;
                    }
                } elseif (\is_string($iosRunDestination) && str_starts_with($iosRunDestination, 'simulator:')) {
                    $iosForDevice = false;
                    $iosSimUdid = substr($iosRunDestination, 10);
                    if ('' === $iosSimUdid) {
                        $io->error('Destination iOS simulateur invalide (UDID vide).');

                        return 1;
                    }
                } else {
                    $io->error('Une destination iOS explicite est requise sur macOS (device:UDID ou simulator:UDID).');

                    return 1;
                }
            }
            $code = $this->runIos($iosRoot, $iosProject, $scheme, $io, $iosForDevice, $iosDeviceUdid, $iosSimUdid);
            if (0 !== $code) {
                return $code;
            }
            if ($installAfterNative) {
                return $iosForDevice
                    ? $this->installIosDeviceApp($io, $iosDeviceUdid)
                    : $this->installIosSimulatorApp($io, $iosSimUdid);
            }
        }

        return 0;
    }

    private function runAndroid(string $androidRoot, string $task, SymfonyStyle $io): int
    {
        if (!is_dir($androidRoot)) {
            $io->error(sprintf('Android project not found. Run native:init first (%s).', $androidRoot));

            return 1;
        }
        $androidHome = $this->resolveAndroidHome();
        $javaHome = $this->resolveJavaHome();
        if ('' === $javaHome) {
            $io->warning('JAVA_HOME n’est pas défini (shell, .env ou native.java_home). Gradle risque d’échouer.');
        }
        if ('' === $androidHome) {
            $io->warning('ANDROID_HOME n’est pas défini (shell, .env ou native.android_home). Le SDK Android peut être introuvable pour Gradle.');
        }
        $gradlew = Path::join($androidRoot, 'gradlew');
        if (!is_file($gradlew)) {
            $io->error(sprintf('gradlew not found at %s', $gradlew));

            return 1;
        }
        $process = Process::fromShellCommandline('./gradlew '.$task, $androidRoot, null, null, null);
        $io->writeln($process->getCommandLine());
        $processEnv = array_filter([
            'ANDROID_HOME' => $androidHome,
            'JAVA_HOME' => $javaHome,
        ], static fn (string $v): bool => '' !== $v);
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        }, $processEnv);
        if (!$process->isSuccessful()) {
            $io->error($process->getErrorOutput() ?: $process->getOutput());

            return $process->getExitCode() ?: 1;
        }

        return 0;
    }

    private function installAndroidApk(string $androidRoot, string $gradleTask, SymfonyStyle $io): int
    {
        $apkPath = $this->apkLocator->resolveBuiltApkPath($androidRoot);
        if (null === $apkPath) {
            $io->error(sprintf('APK introuvable après le build (tâche Gradle : %s). Vérifiez android_gradle_task et le chemin de sortie.', $gradleTask));

            return 1;
        }
        $adb = $this->resolveAdbExecutable();
        if (null === $adb) {
            $io->error('adb introuvable. Définissez ANDROID_HOME (ou native.android_home) ou installez le SDK Android (platform-tools).');

            return 1;
        }
        $process = new Process([$adb, 'install', '-r', $apkPath], null, null, null, null);
        $io->writeln($process->getCommandLine());
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });
        if (!$process->isSuccessful()) {
            $io->error($process->getErrorOutput() ?: $process->getOutput());

            return $process->getExitCode() ?: 1;
        }

        return 0;
    }

    private function resolveAdbExecutable(): ?string
    {
        $sdkRoot = $this->resolveAndroidSdkRootForPlatformTools();
        if ('' === $sdkRoot) {
            return null;
        }
        $adbName = 'Windows' === \PHP_OS_FAMILY ? 'adb.exe' : 'adb';
        $adb = Path::join($sdkRoot, 'platform-tools', $adbName);

        return is_file($adb) ? $adb : null;
    }

    private function resolveAndroidSdkRootForPlatformTools(): string
    {
        $fromConfigAndEnv = $this->resolveAndroidHome();
        if ('' !== $fromConfigAndEnv && is_dir($fromConfigAndEnv)) {
            return $fromConfigAndEnv;
        }
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        if ('' === $home) {
            return '';
        }
        if ('Darwin' === \PHP_OS_FAMILY) {
            $macSdk = Path::join($home, 'Library', 'Android', 'sdk');
            if (is_dir($macSdk)) {
                return $macSdk;
            }
        }
        $linuxStyle = Path::join($home, 'Android', 'Sdk');
        if (is_dir($linuxStyle)) {
            return $linuxStyle;
        }

        return '';
    }

    private function runIos(
        string $iosRoot,
        string $iosProject,
        string $scheme,
        SymfonyStyle $io,
        bool $forPhysicalDevice,
        ?string $explicitDeviceUdid,
        ?string $explicitSimulatorUdid,
    ): int
    {
        if ('Darwin' !== \PHP_OS_FAMILY) {
            $io->error('iOS builds require macOS with Xcode.');

            return 1;
        }
        if (!is_dir($iosRoot)) {
            $io->error(sprintf('iOS project not found. Run native:init first (%s).', $iosRoot));

            return 1;
        }
        $projectPath = Path::join($iosRoot, $iosProject);
        if (!is_dir($projectPath)) {
            $io->error(sprintf('Xcode project not found: %s', $projectPath));

            return 1;
        }
        $derived = $this->iosLocator->getDerivedDataPath();

        $resolve = new Process(
            [
                'xcodebuild',
                '-project', $iosProject,
                '-scheme', $scheme,
                '-derivedDataPath', $derived,
                '-resolvePackageDependencies',
            ],
            $iosRoot,
            null,
            null,
            600
        );
        $resolve->run();
        if (!$resolve->isSuccessful()) {
            $io->warning('xcodebuild -resolvePackageDependencies a échoué (les dépendances SPM peuvent être incomplètes). '.$resolve->getErrorOutput());
        }

        $command = [
            'xcodebuild',
            '-project', $iosProject,
            '-scheme', $scheme,
            '-configuration', 'Debug',
            '-derivedDataPath', $derived,
        ];
        if ($forPhysicalDevice) {
            $command[] = '-allowProvisioningUpdates';
            $command[] = '-sdk';
            $command[] = 'iphoneos';
            if (null !== $explicitDeviceUdid && '' !== $explicitDeviceUdid) {
                $command[] = '-destination';
                $command[] = 'platform=iOS,id='.$explicitDeviceUdid;
            } else {
                $deviceId = $this->iosDeviceInstaller->findConnectedPhysicalDeviceIdentifier();
                if (null !== $deviceId) {
                    $command[] = '-destination';
                    $command[] = 'platform=iOS,id='.$deviceId;
                } else {
                    $fallbackDest = $this->pickIosDeviceDestinationFromShowDestinations($iosRoot, $iosProject, $scheme, $derived);
                    if (null !== $fallbackDest) {
                        $command[] = '-destination';
                        $command[] = $fallbackDest;
                        $io->note('Destination issue de `xcodebuild -showdestinations` : '.$fallbackDest);
                    } else {
                        $command[] = '-destination';
                        $command[] = 'generic/platform=iOS';
                        $io->note('Aucun appareil USB détecté et aucune destination iOS exploitable dans -showdestinations : utilisation de `generic/platform=iOS`. En cas d’erreur « iOS … is not installed », installez le support dans Xcode → Settings → Components ou branchez l’iPhone.');
                    }
                }
            }
        } else {
            $command[] = '-sdk';
            $command[] = 'iphonesimulator';
            if (null !== $explicitSimulatorUdid && '' !== $explicitSimulatorUdid) {
                $command[] = '-destination';
                $command[] = 'platform=iOS Simulator,id='.$explicitSimulatorUdid;
            } else {
                $command[] = '-destination';
                $command[] = 'generic/platform=iOS Simulator';
            }
        }
        $command[] = 'build';
        $process = new Process(
            $command,
            $iosRoot,
            null,
            null,
            null
        );
        $io->writeln($process->getCommandLine());
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });
        if (!$process->isSuccessful()) {
            $combined = $process->getErrorOutput().$process->getOutput();
            if (str_contains($combined, 'is not installed') && str_contains($combined, 'Components')) {
                $io->note('Xcode n’a pas le support de la version iOS de l’appareil (DeviceSupport). Installez la plateforme indiquée dans Xcode → Settings → Components, puis relancez le build.');
            }
            $io->error($process->getErrorOutput() ?: $process->getOutput());

            return $process->getExitCode() ?: 1;
        }

        return 0;
    }

    private function pickIosDeviceDestinationFromShowDestinations(
        string $iosRoot,
        string $iosProject,
        string $scheme,
        string $derived,
    ): ?string {
        $process = new Process(
            [
                'xcodebuild',
                '-project', $iosProject,
                '-scheme', $scheme,
                '-derivedDataPath', $derived,
                '-showdestinations',
            ],
            $iosRoot,
            null,
            null,
            120
        );
        $process->run();
        $text = $process->getOutput().$process->getErrorOutput();
        if (preg_match_all('/\{[^}]*platform:iOS[^}]*\}/', $text, $blocks)) {
            foreach ($blocks[0] as $block) {
                if (str_contains($block, 'Simulator')) {
                    continue;
                }
                if (str_contains($block, 'placeholder')) {
                    continue;
                }
                if (preg_match('/id:([^,}]+)/', $block, $m)) {
                    $id = trim($m[1]);
                    if ('' === $id || str_contains($id, 'placeholder')) {
                        continue;
                    }
                    if (!preg_match('/^00008[0-9A-F]{2}-[0-9A-F-]+$/i', $id) && !preg_match('/^[0-9A-F]{40}$/i', $id)) {
                        continue;
                    }

                    return 'platform=iOS,id='.$id;
                }
            }
        }

        return null;
    }

    private function installIosDeviceApp(SymfonyStyle $io, ?string $hardwareUdid): int
    {
        $appPath = $this->iosLocator->resolveBuiltDeviceAppPath();
        if (null === $appPath) {
            $io->error(sprintf(
                'Bundle .app appareil introuvable après le build. Chemin attendu : %s',
                $this->iosLocator->getExpectedDeviceAppPath()
            ));

            return 1;
        }

        return $this->iosDeviceInstaller->installAppBundle($appPath, $io, $hardwareUdid);
    }

    private function installIosSimulatorApp(SymfonyStyle $io, ?string $simulatorUdid): int
    {
        $appPath = $this->iosLocator->resolveBuiltSimulatorAppPath();
        if (null === $appPath) {
            $io->error(sprintf(
                'Bundle .app simulateur introuvable après le build. Chemin attendu : %s',
                $this->iosLocator->getExpectedSimulatorAppPath()
            ));

            return 1;
        }
        $target = (null !== $simulatorUdid && '' !== $simulatorUdid) ? $simulatorUdid : 'booted';
        $process = new Process(['xcrun', 'simctl', 'install', $target, $appPath], null, null, null, 180);
        $io->writeln($process->getCommandLine());
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });
        if (!$process->isSuccessful()) {
            $io->error(($process->getErrorOutput() ?: $process->getOutput()).' Vérifiez que le simulateur ciblé est bien démarré, puis relancez avec --install.');

            return $process->getExitCode() ?: 1;
        }

        return 0;
    }

    private function resolveAndroidHome(): string
    {
        $fromConfig = $this->config['android_home'] ?? null;
        if (\is_string($fromConfig) && '' !== trim($fromConfig)) {
            return trim($fromConfig);
        }

        return $this->firstNonEmptyString([
            $_ENV['ANDROID_HOME'] ?? null,
            $_SERVER['ANDROID_HOME'] ?? null,
            getenv('ANDROID_HOME') ?: null,
        ]);
    }

    private function resolveJavaHome(): string
    {
        $fromConfig = $this->config['java_home'] ?? null;
        if (\is_string($fromConfig) && '' !== trim($fromConfig)) {
            return trim($fromConfig);
        }

        return $this->firstNonEmptyString([
            $_ENV['JAVA_HOME'] ?? null,
            $_SERVER['JAVA_HOME'] ?? null,
            getenv('JAVA_HOME') ?: null,
        ]);
    }

    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $v) {
            if (\is_string($v) && '' !== trim($v)) {
                return trim($v);
            }
        }

        return '';
    }
}
