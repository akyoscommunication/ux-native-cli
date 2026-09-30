<?php

namespace Akyos\UxNativeCliBundle\Tests;

use Akyos\UxNativeCliBundle\Service\NativeIosDeviceInstaller;
use PHPUnit\Framework\TestCase;

final class NativeIosDeviceInstallerTest extends TestCase
{
    public function testPairedButUnavailableDeviceIsIgnored(): void
    {
        $byUdid = [];
        $this->merge([
            'connectionProperties' => ['pairingState' => 'paired', 'tunnelState' => 'unavailable'],
            'deviceProperties' => ['name' => 'iPhone'],
            'hardwareProperties' => ['udid' => '00008130-000C58C40E21001C'],
        ], $byUdid);

        self::assertSame([], $byUdid);
    }

    public function testConnectedDeviceIsListed(): void
    {
        $byUdid = [];
        $this->merge([
            'connectionProperties' => ['pairingState' => 'paired', 'tunnelState' => 'connected'],
            'deviceProperties' => ['name' => 'iPhone'],
            'hardwareProperties' => ['udid' => '00008130-000C58C40E21001C', 'marketingName' => 'iPhone 15 Pro'],
        ], $byUdid);

        self::assertSame(['00008130-000C58C40E21001C'], array_keys($byUdid));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array{udid: string, label: string}> $byUdid
     */
    private function merge(array $node, array &$byUdid): void
    {
        $method = new \ReflectionMethod(NativeIosDeviceInstaller::class, 'mergePhysicalDevicesFromJsonNode');
        $method->invokeArgs(new NativeIosDeviceInstaller(), [$node, &$byUdid]);
    }
}
