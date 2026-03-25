<?php

namespace Akyos\UxNativeCliBundle\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class NativeDevHelper
{
    public function __construct(
        private KernelInterface $kernel,
        private array $config,
    ) {
    }

    public function run(SymfonyStyle $io, bool $startServer): int
    {
        $projectDir = $this->kernel->getProjectDir();
        $url = $this->config['url'] ?? 'http://127.0.0.1:8000';

        $io->section('Native development');
        $io->listing([
            'Point the native shell base URL at: '.$url,
            'From the project root, run the Symfony dev server (symfony server:start or php -S) so the device or emulator can reach it.',
            'Android: start an emulator or connect a device, then use Android Studio or adb to install the debug APK after native:build.',
            'iOS: open the Xcode workspace or project under native/ios and run on a simulator.',
        ]);

        $io->note('Hotwire Native docs: https://native.hotwired.dev/ — UX Native: https://ux.symfony.com/native');

        if (!$startServer) {
            return 0;
        }

        $finder = new ExecutableFinder();
        $symfony = $finder->find('symfony');
        if (null === $symfony) {
            $io->warning('symfony CLI not found in PATH. Start your server manually.');

            return 0;
        }

        $process = new Process([$symfony, 'server:start'], $projectDir, null, null, null);
        $io->writeln($process->getCommandLine());
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        return $process->getExitCode() ?? 0;
    }
}
