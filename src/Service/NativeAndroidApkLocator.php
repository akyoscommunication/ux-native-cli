<?php

namespace Akyos\UxNativeCliBundle\Service;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;

class NativeAndroidApkLocator
{
    public function __construct(
        private KernelInterface $kernel,
        private array $config,
    ) {
    }

    public function getAndroidRoot(): string
    {
        $projectDir = $this->kernel->getProjectDir();

        return $this->config['android_path'] ?? Path::join($projectDir, 'native', 'android');
    }

    public function getExpectedApkRelativePath(): string
    {
        $task = $this->config['android_gradle_task'] ?? 'assembleDebug';
        $isRelease = str_contains($task, 'Release');

        return $isRelease
            ? 'app/build/outputs/apk/release/app-release.apk'
            : 'app/build/outputs/apk/debug/app-debug.apk';
    }

    public function getExpectedApkPath(?string $androidRoot = null): string
    {
        $root = $androidRoot ?? $this->getAndroidRoot();

        return Path::join($root, $this->getExpectedApkRelativePath());
    }

    public function resolveBuiltApkPath(?string $androidRoot = null): ?string
    {
        $path = $this->getExpectedApkPath($androidRoot);

        return is_file($path) ? Path::canonicalize($path) : null;
    }
}
