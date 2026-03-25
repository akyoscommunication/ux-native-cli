<?php

namespace Akyos\UxNativeCliBundle\Service;

use Symfony\Component\Process\Process;

class NativeIosRunDestinationCatalog
{
    public function __construct(
        private NativeIosDeviceInstaller $deviceInstaller,
    ) {
    }

    /**
     * @return list<array{udid: string, label: string}>
     */
    public function listPhysicalDevices(): array
    {
        return $this->deviceInstaller->listConnectedPhysicalDevices();
    }

    /**
     * @return list<array{udid: string, label: string}>
     */
    public function listBootedSimulators(): array
    {
        $process = new Process(['xcrun', 'simctl', 'list', 'devices', 'booted', '-j'], null, null, null, 30);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }
        $json = json_decode($process->getOutput(), true);
        if (!\is_array($json) || !isset($json['devices']) || !\is_array($json['devices'])) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($json['devices'] as $devices) {
            if (!\is_array($devices)) {
                continue;
            }
            foreach ($devices as $d) {
                if (!\is_array($d)) {
                    continue;
                }
                if ('Booted' !== ($d['state'] ?? '')) {
                    continue;
                }
                $udid = trim((string) ($d['udid'] ?? ''));
                if ('' === $udid || isset($seen[$udid])) {
                    continue;
                }
                $seen[$udid] = true;
                $name = trim((string) ($d['name'] ?? ''));
                $out[] = [
                    'udid' => $udid,
                    'label' => '' !== $name ? $name : $udid,
                ];
            }
        }

        return $out;
    }
}
