<?php

namespace Akyos\UxNativeCliBundle\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class NativeIosDeviceInstaller
{
    public function installAppBundle(string $appPath, SymfonyStyle $io, ?string $hardwareUdid = null): int
    {
        if (!is_dir($appPath) || !str_ends_with(strtolower($appPath), '.app')) {
            $io->error('Bundle .app iOS introuvable ou invalide.');

            return 1;
        }

        $deviceId = $hardwareUdid ?? $this->findConnectedPhysicalDeviceIdentifier();
        if (null === $deviceId) {
            $io->error('Aucun iPhone ou iPad physique connecté (USB, appareil déverrouillé et « Faire confiance à cet ordinateur »).');

            return 1;
        }

        $devicectl = new Process(
            ['xcrun', 'devicectl', 'device', 'install', 'app', '--device', $deviceId, $appPath],
            null,
            null,
            null,
            300
        );
        $io->writeln($devicectl->getCommandLine());
        $devicectl->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if ($devicectl->isSuccessful()) {
            return 0;
        }

        $io->warning('devicectl a échoué, tentative avec ios-deploy si disponible…');
        $finder = new ExecutableFinder();
        $iosDeploy = $finder->find('ios-deploy');
        if (null === $iosDeploy) {
            $io->error($devicectl->getErrorOutput() ?: $devicectl->getOutput());

            return $devicectl->getExitCode() ?: 1;
        }

        $deploy = new Process([$iosDeploy, '-i', $deviceId, '--bundle', $appPath, '--noninteractive'], null, null, null, 300);
        $io->writeln($deploy->getCommandLine());
        $deploy->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });
        if (!$deploy->isSuccessful()) {
            $io->error($deploy->getErrorOutput() ?: $deploy->getOutput());

            return $deploy->getExitCode() ?: 1;
        }

        return 0;
    }

    /**
     * @return list<array{udid: string, label: string}>
     */
    public function listConnectedPhysicalDevices(): array
    {
        $byUdid = [];
        $process = new Process(['xcrun', 'devicectl', 'list', 'devices', '--json-output', '-'], null, null, null, 45);
        $process->run();
        if ($process->isSuccessful()) {
            $json = json_decode($process->getOutput(), true);
            if (\is_array($json)) {
                $this->mergePhysicalDevicesFromJsonNode($json, $byUdid);
            }
        }
        if ([] === $byUdid) {
            $process = new Process(['xcrun', 'devicectl', 'list', 'devices'], null, null, null, 45);
            $process->run();
            $text = $process->getOutput().$process->getErrorOutput();
            $id = $this->extractDeviceIdFromTextListing($text);
            if (null !== $id) {
                $byUdid[$id] = ['udid' => $id, 'label' => $id];
            }
        }
        if ([] === $byUdid) {
            $finder = new ExecutableFinder();
            $iosDeploy = $finder->find('ios-deploy');
            if (null !== $iosDeploy) {
                $c = new Process([$iosDeploy, '-c'], null, null, null, 30);
                $c->run();
                $id = $this->extractDeviceIdFromIosDeploy($c->getOutput());
                if (null !== $id) {
                    $byUdid[$id] = ['udid' => $id, 'label' => $id];
                }
            }
        }
        $list = array_values($byUdid);
        usort($list, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $list;
    }

    public function findConnectedPhysicalDeviceIdentifier(): ?string
    {
        $list = $this->listConnectedPhysicalDevices();

        return $list[0]['udid'] ?? null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array{udid: string, label: string}> $byUdid
     */
    private function mergePhysicalDevicesFromJsonNode(array $node, array &$byUdid): void
    {
        $conn = $node['connectionProperties'] ?? null;
        if (\is_array($conn)) {
            $tunnel = (string) ($conn['tunnelState'] ?? '');
            $pairing = (string) ($conn['pairingState'] ?? '');
            $connected = 'connected' === $tunnel || 'paired' === $pairing;
            $name = '';
            if (isset($node['deviceProperties']) && \is_array($node['deviceProperties'])) {
                $name = trim((string) ($node['deviceProperties']['name'] ?? ''));
            }
            $udid = null;
            $hp = $node['hardwareProperties'] ?? null;
            if (\is_array($hp) && isset($hp['udid']) && \is_string($hp['udid'])) {
                $u = trim($hp['udid']);
                if ('' !== $u) {
                    $udid = $u;
                }
            }
            if (null === $udid && isset($node['identifier']) && \is_string($node['identifier'])) {
                $candidate = trim($node['identifier']);
                if ($this->isLikelyIosHardwareUdid($candidate)) {
                    $udid = $candidate;
                }
            }
            if ($connected && !str_contains($name, 'Simulator') && null !== $udid) {
                $marketing = '';
                if (\is_array($hp) && isset($hp['marketingName'])) {
                    $marketing = trim((string) $hp['marketingName']);
                }
                $label = '' !== $name ? $name : ('' !== $marketing ? $marketing : $udid);
                $byUdid[$udid] = ['udid' => $udid, 'label' => $label];
            }
        }
        foreach ($node as $v) {
            if (\is_array($v)) {
                $this->mergePhysicalDevicesFromJsonNode($v, $byUdid);
            }
        }
    }

    private function isLikelyIosHardwareUdid(string $id): bool
    {
        if (preg_match('/^00008[0-9A-F]{2}-[0-9A-F-]+$/i', $id)) {
            return true;
        }
        if (preg_match('/^[0-9A-F]{40}$/i', $id)) {
            return true;
        }

        return false;
    }

    private function extractDeviceIdFromTextListing(string $text): ?string
    {
        foreach (explode("\n", $text) as $line) {
            if (str_contains($line, 'Simulator')) {
                continue;
            }
            if (!str_contains($line, 'connected') && !str_contains($line, 'Connected')) {
                continue;
            }
            if (preg_match_all('/\b([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})\b/i', $line, $matches)) {
                foreach ($matches[1] as $cand) {
                    if ($this->isLikelyIosHardwareUdid($cand)) {
                        return $cand;
                    }
                }
            }
        }

        return null;
    }

    private function extractDeviceIdFromIosDeploy(string $output): ?string
    {
        if (preg_match('/[{,]\s*\"device_identifier\"\s*:\s*\"([0-9A-Fa-f-]+)\"/', $output, $m)) {
            return $m[1];
        }

        return null;
    }
}
