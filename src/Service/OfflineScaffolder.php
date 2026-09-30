<?php

namespace Akyos\UxNativeCliBundle\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

class OfflineScaffolder
{
    private const TEMPLATE_ROOT = 'templates/offline';

    private const PWA_BUNDLE_CLASS = 'SpomkyLabs\\PwaBundle\\SpomkyLabsPwaBundle';

    public function __construct(
        private Filesystem $filesystem,
        private KernelInterface $kernel,
        private string $bundleRoot,
    ) {
    }

    public function scaffold(array $config, bool $force, string $iosTarget, string $androidTarget, SymfonyStyle $io): int
    {
        $projectDir = $this->kernel->getProjectDir();
        $templateRoot = Path::join($this->bundleRoot, self::TEMPLATE_ROOT);

        if (!$this->filesystem->exists($templateRoot)) {
            $io->error('Offline templates are missing in the bundle.');

            return 1;
        }

        $tokens = [
            '%NATIVE_APP_NAME%' => $config['app_name'],
            '%NATIVE_BASE_URL%' => $config['url'],
        ];

        $this->copyOfflineTemplates($templateRoot, $projectDir, $tokens, $force, $io);
        $this->registerPwaBundle(Path::join($projectDir, 'config', 'bundles.php'), $io);
        $this->patchBaseTwig(Path::join($projectDir, 'templates'), $io);
        $this->patchIosForServiceWorker($iosTarget, $config['url'], $force, $io);
        $this->alignNativeUrls($iosTarget, $androidTarget, $config['url'], $io);
        $this->syncPathConfigurations($projectDir, $iosTarget, $androidTarget, $io);

        if (!$this->isPwaBundleInstalled($projectDir)) {
            $this->installPwaBundle($projectDir, $io);
        } else {
            $io->writeln('<info>PWA bundle déjà présent dans composer.json.</info>');
        }

        $this->compilePwaAssets($projectDir, $io);

        $io->success('Mode hors ligne activé (Workbox + service worker).');
        $io->note([
            'Ajustez config/packages/pwa.yaml si besoin (routes, preload_urls).',
            'IMPORTANT : après TOUTE modif de pwa.yaml, relancez : php bin/console pwa:compile',
            'Les Service Workers exigent HTTPS (sauf localhost) — utilisez ngrok/mkcert pour un device.',
            'L’URL native doit être identique partout — vérifiez config/packages/native.yaml.',
            'Recompilez l’app native après ces changements.',
        ]);

        return 0;
    }

    private function alignNativeUrls(string $iosTarget, string $androidTarget, string $baseUrl, SymfonyStyle $io): void
    {
        $parts = parse_url(rtrim($baseUrl, '/'));
        $host = $parts['host'] ?? null;
        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        if (!\is_string($host) || '' === $host) {
            return;
        }

        $expected = sprintf('%s://%s%s', $scheme, $host, $port);
        $pattern = sprintf('#https?://%s%s#', preg_quote($host, '#'), preg_quote($port, '#'));

        foreach ([$iosTarget, $androidTarget] as $root) {
            if (!$this->filesystem->exists($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!\in_array($ext, ['swift', 'kt', 'kts'], true)) {
                    continue;
                }
                $content = (string) file_get_contents($file->getPathname());
                $patched = preg_replace($pattern, $expected, $content);
                if (null !== $patched && $patched !== $content) {
                    file_put_contents($file->getPathname(), $patched);
                    $io->writeln(sprintf('<info>URL alignée sur %s : %s</info>', $expected, $file->getFilename()));
                }
            }
        }
    }

    private function syncPathConfigurations(string $projectDir, string $iosTarget, string $androidTarget, SymfonyStyle $io): void
    {
        $process = new Process(
            [(\PHP_BINARY), 'bin/console', 'ux-native:dump'],
            $projectDir,
            null,
            null,
            120
        );
        $process->run();
        if (!$process->isSuccessful()) {
            $io->warning('ux-native:dump a échoué — path-configuration embarquée peut être incomplète.');

            return;
        }

        $iosSource = Path::join($projectDir, 'public', 'config', 'ios_v1.json');
        $androidSource = Path::join($projectDir, 'public', 'config', 'android_v1.json');
        $iosDest = Path::join($iosTarget, 'NativeApp', 'path-configuration.json');
        $androidDest = Path::join($androidTarget, 'app', 'src', 'main', 'assets', 'json', 'path-configuration.json');

        if ($this->filesystem->exists($iosSource)) {
            $this->filesystem->mkdir(\dirname($iosDest));
            $this->filesystem->copy($iosSource, $iosDest, true);
            $io->writeln('<info>iOS : path-configuration.json embarqué (tabs)</info>');
        }

        if ($this->filesystem->exists($androidSource)) {
            $this->filesystem->mkdir(\dirname($androidDest));
            $this->filesystem->copy($androidSource, $androidDest, true);
            $io->writeln('<info>Android : assets/json/path-configuration.json embarqué</info>');
        }
    }

    private function copyOfflineTemplates(string $source, string $projectDir, array $tokens, bool $force, SymfonyStyle $io): void
    {
        $files = [
            'config/packages/pwa.yaml' => Path::join($projectDir, 'config', 'packages', 'pwa.yaml'),
            'src/Controller/NativeOfflineController.php' => Path::join($projectDir, 'src', 'Controller', 'NativeOfflineController.php'),
            'templates/native/offline.html.twig' => Path::join($projectDir, 'templates', 'native', 'offline.html.twig'),
        ];

        foreach ($files as $relative => $target) {
            $origin = Path::join($source, $relative);
            if (!$this->filesystem->exists($origin)) {
                continue;
            }
            if ($this->filesystem->exists($target) && !$force) {
                $io->writeln(sprintf('<comment>Conservé (déjà présent) : %s</comment>', $relative));

                continue;
            }
            $this->filesystem->mkdir(\dirname($target));
            $content = strtr((string) file_get_contents($origin), $tokens);
            file_put_contents($target, $content);
            $io->writeln(sprintf('<info>Écrit : %s</info>', $relative));
        }
    }

    private function registerPwaBundle(string $bundlesPath, SymfonyStyle $io): void
    {
        if (!$this->filesystem->exists($bundlesPath)) {
            $io->warning('config/bundles.php introuvable — ajoutez SpomkyLabs\\PwaBundle\\PwaBundle manuellement.');

            return;
        }

        $content = (string) file_get_contents($bundlesPath);
        if (str_contains($content, self::PWA_BUNDLE_CLASS)) {
            return;
        }

        $line = "    ".self::PWA_BUNDLE_CLASS."::class => ['all' => true],\n";
        $patched = preg_replace('/\n\];\s*$/', "\n".$line.'];', $content, 1);
        if (null === $patched || $patched === $content) {
            $io->warning('Impossible de modifier config/bundles.php automatiquement.');

            return;
        }

        file_put_contents($bundlesPath, $patched);
        $io->writeln('<info>Enregistré : SpomkyLabs\\PwaBundle dans config/bundles.php</info>');
    }

    private function patchBaseTwig(string $templatesDir, SymfonyStyle $io): void
    {
        if (!$this->filesystem->exists($templatesDir)) {
            return;
        }

        $candidates = [
            Path::join($templatesDir, 'base.html.twig'),
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templatesDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && 'base.html.twig' === $file->getFilename()) {
                $candidates[] = $file->getPathname();
            }
        }

        $candidates = array_unique($candidates);
        $needle = '{{ pwa() }}';
        $insertion = "        {{ pwa() }}\n";

        foreach ($candidates as $path) {
            $content = (string) file_get_contents($path);
            if (str_contains($content, $needle)) {
                continue;
            }
            if (!preg_match('/<\/head>/i', $content)) {
                continue;
            }
            $patched = preg_replace('/<\/head>/i', $insertion.'</head>', $content, 1);
            if (null !== $patched && $patched !== $content) {
                file_put_contents($path, $patched);
                $io->writeln(sprintf('<info>Ajouté {{ pwa() }} dans %s</info>', $path));
            }
        }
    }

    private function patchIosForServiceWorker(string $iosTarget, string $baseUrl, bool $force, SymfonyStyle $io): void
    {
        $appDelegate = Path::join($iosTarget, 'NativeApp', 'AppDelegate.swift');
        $infoPlist = Path::join($iosTarget, 'NativeApp', 'Info.plist');

        if ($this->filesystem->exists($appDelegate)) {
            $this->patchAppDelegate($appDelegate, $force);
            $io->writeln('<info>iOS : service worker activé dans AppDelegate.swift</info>');
        }

        if ($this->filesystem->exists($infoPlist)) {
            $this->patchInfoPlist($infoPlist, $this->resolveAppBoundHosts($baseUrl), $force);
            $io->writeln('<info>iOS : WKAppBoundDomains ajouté dans Info.plist</info>');
        }
    }

    private function patchAppDelegate(string $path, bool $force): void
    {
        $content = (string) file_get_contents($path);
        if (str_contains($content, 'limitsNavigationsToAppBoundDomains')) {
            return;
        }

        if (!str_contains($content, 'import WebKit')) {
            $content = preg_replace('/(import UIKit\n)/', "$1import WebKit\n", $content, 1) ?? $content;
        }

        $snippet = <<<'SWIFT'

        Hotwire.config.makeCustomWebView = { config in
            config.limitsNavigationsToAppBoundDomains = true
            return WKWebView(frame: .zero, configuration: config)
        }
SWIFT;

        $patched = preg_replace(
            '/(Hotwire\.loadPathConfiguration\(from: \[[\s\S]*?\]\))\s+return true/',
            '$1'.$snippet."\n        return true",
            $content,
            1
        );

        if (null !== $patched && $patched !== $content) {
            file_put_contents($path, $patched);
        }
    }

    private function patchInfoPlist(string $path, array $hosts, bool $force): void
    {
        $content = (string) file_get_contents($path);
        if (str_contains($content, 'WKAppBoundDomains') && !$force) {
            return;
        }

        if (str_contains($content, 'WKAppBoundDomains') && $force) {
            $content = preg_replace(
                '/\s*<key>WKAppBoundDomains<\/key>\s*<array>.*?<\/array>/s',
                '',
                $content,
                1
            ) ?? $content;
        }

        $domainLines = '';
        foreach ($hosts as $host) {
            $domainLines .= "\t\t<string>".htmlspecialchars($host, \ENT_XML1)."</string>\n";
        }

        $block = <<<XML

	<key>WKAppBoundDomains</key>
	<array>
{$domainLines}	</array>
XML;

        $patched = preg_replace('/\n<\/dict>\s*\n<\/plist>\s*$/', $block."\n</dict>\n</plist>", $content, 1);
        if (null !== $patched && $patched !== $content) {
            file_put_contents($path, $patched);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveAppBoundHosts(string $baseUrl): array
    {
        $hosts = ['localhost'];
        $parts = parse_url($baseUrl);
        $host = $parts['host'] ?? null;
        if (\is_string($host) && '' !== $host && !\in_array($host, $hosts, true)) {
            $hosts[] = $host;
        }

        return $hosts;
    }

    private function isPwaBundleInstalled(string $projectDir): bool
    {
        $composerJson = Path::join($projectDir, 'composer.json');
        if (!$this->filesystem->exists($composerJson)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);

        return isset($data['require']['spomky-labs/pwa-bundle'])
            || isset($data['require-dev']['spomky-labs/pwa-bundle']);
    }

    private function compilePwaAssets(string $projectDir, SymfonyStyle $io): void
    {
        $process = new Process(
            [\PHP_BINARY, 'bin/console', 'pwa:compile', '--no-interaction'],
            $projectDir,
            null,
            null,
            300
        );

        $io->writeln('<info>Compilation des assets PWA (génère public/sw.js)…</info>');
        $process->run(static function ($type, $buffer) use ($io) {
            $io->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $io->warning('pwa:compile a échoué — lancez-le manuellement après cache:clear.');
        }
    }

    private function installPwaBundle(string $projectDir, SymfonyStyle $io): void
    {
        $process = new Process(
            [...$this->resolveComposerCommand(), 'require', 'spomky-labs/pwa-bundle:^1.5', '--no-interaction'],
            $projectDir,
            null,
            null,
            600
        );

        $io->writeln('<info>Installation de spomky-labs/pwa-bundle…</info>');
        $process->run(static function ($type, $buffer) use ($io) {
            $io->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $io->warning('composer require spomky-labs/pwa-bundle a échoué — lancez-le manuellement puis cache:clear.');
        }
    }

    /**
     * @return list<string>
     */
    private function resolveComposerCommand(): array
    {
        $local = Path::join($this->kernel->getProjectDir(), 'composer.phar');
        if ($this->filesystem->exists($local)) {
            return [\PHP_BINARY, $local];
        }

        return ['composer'];
    }
}
