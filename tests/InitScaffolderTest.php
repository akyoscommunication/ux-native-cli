<?php

namespace Akyos\UxNativeCliBundle\Tests;

use Akyos\UxNativeCliBundle\Service\InitScaffolder;
use Akyos\UxNativeCliBundle\Service\OfflineScaffolder;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class InitScaffolderTest extends TestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function testNotificationFlag(bool $notification): void
    {
        $filesystem = new Filesystem();
        $projectDir = sys_get_temp_dir().'/native-cli-test-'.bin2hex(random_bytes(4));
        $filesystem->mkdir($projectDir);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($projectDir);
        $bundleRoot = \dirname(__DIR__);
        $config = [
            'app_name' => 'Demo',
            'url' => 'http://127.0.0.1:8000',
            'application_id' => 'com.acme.demo',
            'bundle_id' => 'com.acme.demo',
            'android_path' => null,
            'ios_path' => null,
            'ios_project' => 'skip-xcodebuild.xcodeproj',
            'ios_development_team' => 'ABCDE12345',
        ];
        $scaffolder = new InitScaffolder($filesystem, $kernel, $config, $bundleRoot, new OfflineScaffolder($filesystem, $kernel, $bundleRoot));

        try {
            self::assertSame(0, $scaffolder->scaffold([], false, false, new SymfonyStyle(new ArrayInput([]), new NullOutput()), $notification));

            $all = '';
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($projectDir.'/native', \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!str_ends_with($file->getFilename(), '.jar')) {
                    $all .= file_get_contents($file->getPathname());
                }
            }

            self::assertStringNotContainsString('%NATIVE_', $all);
            self::assertSame(2, substr_count($all, 'DEVELOPMENT_TEAM = ABCDE12345;'));
            self::assertSame($notification, str_contains($all, 'notification-token'));
            self::assertSame($notification, str_contains($all, 'google-services'));
            self::assertSame($notification ? 1 : 0, substr_count($all, 'import dev.hotwire.core.bridge.BridgeComponentFactory'));
            self::assertStringNotContainsString('play-services-code-scanner', $all);
            self::assertStringNotContainsString('play-services-ads', $all);
            self::assertSame($notification, is_file($projectDir.'/native/android/app/src/main/java/com/acme/demo/NotificationTokenComponent.kt'));
            self::assertSame($notification, is_file($projectDir.'/native/ios/NativeApp/NotificationTokenComponent.swift'));
            self::assertSame($notification, is_file($projectDir.'/native/android/app/src/main/java/com/acme/demo/NativeMessagingService.kt'));
            self::assertSame($notification, str_contains((string) file_get_contents($projectDir.'/native/ios/NativeApp/SceneDelegate.swift'), 'openPendingNotificationURL'));
            self::assertSame($notification, str_contains(
                (string) file_get_contents($projectDir.'/native/ios/NativeApp.xcodeproj/project.pbxproj'),
                'CODE_SIGN_ENTITLEMENTS = NativeApp/NativeApp.entitlements;'
            ));
        } finally {
            $filesystem->remove($projectDir);
        }
    }

    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    public function testBarcodeAndAdmobFlags(bool $barcodeScanner, bool $admob): void
    {
        $filesystem = new Filesystem();
        $projectDir = sys_get_temp_dir().'/native-cli-test-'.bin2hex(random_bytes(4));
        $filesystem->mkdir([$projectDir.'/assets/controllers']);
        file_put_contents($projectDir.'/assets/stimulus_bootstrap.js', <<<'JS'
import { startStimulusApp } from '@symfony/stimulus-bundle';

const app = startStimulusApp();
JS);
        file_put_contents($projectDir.'/importmap.php', <<<'PHP'
<?php

return [
    '@hotwired/hotwire-native-bridge' => ['path' => 'already'],
    '@joemasilotti/bridge-components' => ['path' => 'already'],
];
PHP);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($projectDir);
        $config = [
            'app_name' => 'Demo',
            'url' => 'http://127.0.0.1:8000',
            'application_id' => 'com.acme.demo',
            'bundle_id' => 'com.acme.demo',
            'android_path' => null,
            'ios_path' => null,
            'ios_project' => 'skip-xcodebuild.xcodeproj',
            'ios_development_team' => null,
            'camera_usage_description' => 'Scannez ce QR code.',
            'admob' => [
                'android_app_id' => 'ca-app-pub-111~222',
                'ios_app_id' => 'ca-app-pub-333~444',
            ],
        ];
        $scaffolder = new InitScaffolder($filesystem, $kernel, $config, \dirname(__DIR__), new OfflineScaffolder($filesystem, $kernel, \dirname(__DIR__)));

        try {
            self::assertSame(0, $scaffolder->scaffold([], false, false, new SymfonyStyle(new ArrayInput([]), new NullOutput()), true, $barcodeScanner, $admob));

            $all = '';
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($projectDir.'/native', \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!str_ends_with($file->getFilename(), '.jar')) {
                    $all .= file_get_contents($file->getPathname());
                }
            }

            self::assertStringNotContainsString('%NATIVE_', $all);
            self::assertSame(1, substr_count($all, 'import dev.hotwire.core.bridge.BridgeComponentFactory'));
            self::assertSame($barcodeScanner, is_file($projectDir.'/native/android/app/src/main/java/com/acme/demo/BarcodeScannerComponent.kt'));
            self::assertSame($barcodeScanner, is_file($projectDir.'/native/ios/NativeApp/BarcodeScannerComponent.swift'));
            self::assertSame($barcodeScanner, str_contains($all, 'play-services-code-scanner:16.1.0'));
            self::assertSame($barcodeScanner, str_contains($all, 'barcode_ui'));
            self::assertSame($barcodeScanner, str_contains($all, 'NSCameraUsageDescription'));
            self::assertSame($barcodeScanner, str_contains($all, 'Scannez ce QR code.'));
            self::assertSame($barcodeScanner, is_file($projectDir.'/assets/controllers/bridge/barcode_scanner_controller.js'));

            self::assertSame($admob, is_file($projectDir.'/native/android/app/src/main/java/com/acme/demo/AdMobComponent.kt'));
            self::assertSame($admob, is_file($projectDir.'/native/ios/NativeApp/AdMobComponent.swift'));
            self::assertSame($admob, str_contains($all, 'play-services-ads:25.5.0'));
            self::assertSame($admob, str_contains($all, 'ca-app-pub-111~222'));
            self::assertSame($admob, str_contains($all, 'GADApplicationIdentifier'));
            self::assertSame($admob, str_contains($all, 'ca-app-pub-333~444'));
            self::assertSame($admob, str_contains($all, 'swift-package-manager-google-mobile-ads'));
            self::assertSame($admob, str_contains($all, 'GoogleMobileAds in Frameworks'));
            self::assertSame($admob, is_file($projectDir.'/assets/controllers/bridge/admob_controller.js'));

            $bootstrap = (string) file_get_contents($projectDir.'/assets/stimulus_bootstrap.js');
            if ($barcodeScanner) {
                self::assertStringContainsString('controller.identifier !== "bridge--barcode-scanner"', $bootstrap);
            } else {
                self::assertStringNotContainsString('bridge--barcode-scanner', $bootstrap);
                self::assertStringContainsString('.load(bridgeControllers);', $bootstrap);
            }
        } finally {
            $filesystem->remove($projectDir);
        }
    }
}
