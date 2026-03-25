<?php

namespace Akyos\UxNativeCliBundle\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

class NativeIosAppLocator
{
    public function __construct(
        private KernelInterface $kernel,
        private array $config,
        private Filesystem $filesystem,
    ) {
    }

    public function getIosRoot(): string
    {
        $projectDir = $this->kernel->getProjectDir();

        return $this->config['ios_path'] ?? Path::join($projectDir, 'native', 'ios');
    }

    public function getDerivedDataPath(): string
    {
        $iosRoot = $this->getIosRoot();
        $fromConfig = $this->config['ios_derived_data_path'] ?? null;
        if (\is_string($fromConfig) && '' !== trim($fromConfig)) {
            $p = trim($fromConfig);
            if (Path::isAbsolute($p)) {
                return Path::canonicalize($p);
            }

            return Path::canonicalize(Path::join($iosRoot, $p));
        }

        return Path::join($iosRoot, 'build', 'DerivedDataCli');
    }

    public function getProductName(): string
    {
        $fromConfig = $this->config['ios_product_name'] ?? null;
        if (\is_string($fromConfig) && '' !== trim($fromConfig)) {
            return trim($fromConfig);
        }

        return (string) ($this->config['ios_scheme'] ?? 'NativeApp');
    }

    public function getExpectedDeviceAppPath(): string
    {
        $derived = $this->getDerivedDataPath();
        $product = $this->getProductName();

        return Path::join($derived, 'Build', 'Products', 'Debug-iphoneos', $product.'.app');
    }

    public function getExpectedSimulatorAppPath(): string
    {
        $derived = $this->getDerivedDataPath();
        $product = $this->getProductName();

        return Path::join($derived, 'Build', 'Products', 'Debug-iphonesimulator', $product.'.app');
    }

    public function resolveBuiltDeviceAppPath(): ?string
    {
        $path = $this->getExpectedDeviceAppPath();

        return is_dir($path) ? Path::canonicalize($path) : null;
    }

    public function resolveBuiltSimulatorAppPath(): ?string
    {
        $path = $this->getExpectedSimulatorAppPath();

        return is_dir($path) ? Path::canonicalize($path) : null;
    }

    public function packAppBundleToTempIpa(string $appPath): ?string
    {
        if (!is_dir($appPath) || !str_ends_with(strtolower($appPath), '.app')) {
            return null;
        }
        $tmpBase = Path::join(sys_get_temp_dir(), 'native_ipa_'.bin2hex(random_bytes(6)));
        $payload = Path::join($tmpBase, 'Payload');
        $this->filesystem->mkdir($payload);
        $dest = Path::join($payload, basename($appPath));
        $this->filesystem->mirror($appPath, $dest);
        $ipaPath = $tmpBase.'.ipa';
        $zip = new Process(['zip', '-qr', $ipaPath, 'Payload'], $tmpBase, null, null, 120);
        $zip->run();
        $this->filesystem->remove($tmpBase);
        if (!$zip->isSuccessful() || !is_file($ipaPath)) {
            if (is_file($ipaPath)) {
                $this->filesystem->remove($ipaPath);
            }

            return null;
        }

        return Path::canonicalize($ipaPath);
    }
}
