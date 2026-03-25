<?php

namespace Akyos\UxNativeCliBundle\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;

class InitScaffolder
{
    private const TEMPLATE_ANDROID = 'templates/native/android';

    private const TEMPLATE_IOS = 'templates/native/ios';

    private const DEFAULT_JAVA_PACKAGE_PATH = 'com/example/nativeapp';

    public function __construct(
        private Filesystem $filesystem,
        private KernelInterface $kernel,
        private array $config,
        private string $bundleRoot,
    ) {
    }

    public function scaffold(array $overrides, bool $force, SymfonyStyle $io): int
    {
        $config = array_merge($this->config, $overrides);
        $projectDir = $this->kernel->getProjectDir();
        $androidTarget = $config['android_path'] ?? Path::join($projectDir, 'native', 'android');
        $iosTarget = $config['ios_path'] ?? Path::join($projectDir, 'native', 'ios');

        $androidSource = Path::join($this->bundleRoot, self::TEMPLATE_ANDROID);
        $iosSource = Path::join($this->bundleRoot, self::TEMPLATE_IOS);

        if (!$this->filesystem->exists($androidSource) || !$this->filesystem->exists($iosSource)) {
            $io->error('Bundle templates are missing.');

            return 1;
        }

        foreach ([['path' => $androidTarget, 'label' => 'Android'], ['path' => $iosTarget, 'label' => 'iOS']] as $target) {
            if ($this->filesystem->exists($target['path']) && !is_dir($target['path'])) {
                $io->error(sprintf('%s output path exists and is not a directory: %s', $target['label'], $target['path']));

                return 1;
            }
            if ($this->filesystem->exists($target['path'])) {
                $iterator = new \FilesystemIterator($target['path']);
                if ($iterator->valid() && !$force) {
                    $io->error(sprintf('%s output directory is not empty: %s (use --force)', $target['label'], $target['path']));

                    return 1;
                }
            }
        }

        $tokens = $this->buildTokens($config);
        $this->mirrorAndSubstitute($androidSource, $androidTarget, $tokens, $force);
        $this->relocateAndroidJavaPackage($androidTarget, $config['application_id']);
        $this->mirrorAndSubstitute($iosSource, $iosTarget, $tokens, $force);

        $gradlew = Path::join($androidTarget, 'gradlew');
        if ($this->filesystem->exists($gradlew)) {
            $this->filesystem->chmod($gradlew, 0755);
        }

        $io->success('Native shells scaffolded.');
        $io->note('Open the Android project in Android Studio and the iOS project in Xcode. See https://native.hotwired.dev/ for Hotwire Native setup.');

        return 0;
    }

    private function buildTokens(array $config): array
    {
        return [
            '%NATIVE_APP_NAME%' => $config['app_name'],
            '%NATIVE_BASE_URL%' => $config['url'],
            '%NATIVE_APPLICATION_ID%' => $config['application_id'],
            '%NATIVE_BUNDLE_ID%' => $config['bundle_id'],
        ];
    }

    private function mirrorAndSubstitute(string $source, string $destination, array $tokens, bool $force): void
    {
        if ($force && $this->filesystem->exists($destination)) {
            $this->filesystem->remove($destination);
        }
        $this->filesystem->mkdir($destination);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $relative = substr($fileInfo->getPathname(), \strlen($source) + 1);
            $targetPath = Path::join($destination, $relative);
            if ($fileInfo->isDir()) {
                $this->filesystem->mkdir($targetPath);

                continue;
            }
            $this->filesystem->mkdir(\dirname($targetPath));
            if ($this->shouldSubstitute($fileInfo->getPathname())) {
                $content = file_get_contents($fileInfo->getPathname());
                $content = strtr($content, $tokens);
                file_put_contents($targetPath, $content);
            } else {
                $this->filesystem->copy($fileInfo->getPathname(), $targetPath, true);
            }
        }
    }

    private function shouldSubstitute(string $path): bool
    {
        $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        $allowed = ['kt', 'kts', 'xml', 'gradle', 'properties', 'md', 'swift', 'plist', 'pbxproj', 'pro', 'html', 'json', 'txt', 'java', 'yaml', 'yml', 'toml', 'xcconfig'];

        return \in_array($ext, $allowed, true);
    }

    private function relocateAndroidJavaPackage(string $androidRoot, string $applicationId): void
    {
        $newPath = str_replace('.', '/', $applicationId);
        if ($newPath === self::DEFAULT_JAVA_PACKAGE_PATH) {
            return;
        }
        $oldDir = Path::join($androidRoot, 'app', 'src', 'main', 'java', self::DEFAULT_JAVA_PACKAGE_PATH);
        $newDir = Path::join($androidRoot, 'app', 'src', 'main', 'java', $newPath);
        if (!$this->filesystem->exists($oldDir)) {
            return;
        }
        $this->filesystem->mkdir(\dirname($newDir));
        $this->filesystem->rename($oldDir, $newDir);
    }
}
