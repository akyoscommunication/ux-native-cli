<?php

namespace Akyos\UxNativeCliBundle\Tests;

use Akyos\UxNativeCliBundle\Service\NativeProjectUpdater;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

final class IconInstallTest extends TestCase
{
    public function testConfiguredTicketIconIsTheLauncherImage(): void
    {
        $source = \dirname(__DIR__, 4).'/assets/icons/ticket.png';
        self::assertFileExists($source);

        $filesystem = new Filesystem();
        $projectDir = sys_get_temp_dir().'/native-icon-'.bin2hex(random_bytes(4));
        $android = $projectDir.'/native/android';
        $ios = $projectDir.'/native/ios';
        $filesystem->mkdir([$android, $ios]);
        $updater = new NativeProjectUpdater($filesystem, \dirname(__DIR__));
        $install = new \ReflectionMethod(NativeProjectUpdater::class, 'installIcon');

        try {
            $install->invoke(
                $updater,
                ['icon' => $source],
                $projectDir,
                $android,
                $ios,
                new SymfonyStyle(new ArrayInput([]), new NullOutput()),
            );

            foreach ([
                $android.'/app/src/main/res/mipmap-xxxhdpi/ic_launcher_logo.png',
                $ios.'/NativeApp/Assets.xcassets/AppIcon.appiconset/icon-1024.png',
            ] as $png) {
                self::assertFileExists($png);
                $colors = $this->sample($png);
                self::assertGreaterThan(50, $colors['red'], $png);
                self::assertGreaterThan(50, $colors['blue'], $png);
                self::assertSame([255, 255, 255], $colors['corner'], $png);
            }
        } finally {
            $filesystem->remove($projectDir);
        }
    }

    /**
     * @return array{red: int, blue: int, corner: array{0: int, 1: int, 2: int}}
     */
    private function sample(string $png): array
    {
        $image = imagecreatefrompng($png);
        self::assertNotFalse($image);
        $width = imagesx($image);
        $height = imagesy($image);
        $red = 0;
        $blue = 0;
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if ($r > 180 && $g < 80 && $b < 80) {
                    ++$red;
                }
                if ($b > 140 && $r < 80 && $g < 120) {
                    ++$blue;
                }
            }
        }
        $corner = imagecolorat($image, 0, 0);

        return [
            'red' => $red,
            'blue' => $blue,
            'corner' => [($corner >> 16) & 0xFF, ($corner >> 8) & 0xFF, $corner & 0xFF],
        ];
    }
}
