<?php

namespace Akyos\UxNativeCliBundle\Tests;

use Akyos\UxNativeCliBundle\Service\InitScaffolder;
use Akyos\UxNativeCliBundle\Service\NativeProjectUpdater;
use Akyos\UxNativeCliBundle\Service\OfflineScaffolder;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\ExecutableFinder;

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
        $scaffolder = new InitScaffolder($filesystem, $kernel, $config, $bundleRoot, new OfflineScaffolder($filesystem, $kernel, $bundleRoot), new NativeProjectUpdater($filesystem, $bundleRoot));

        try {
            self::assertSame(0, $scaffolder->scaffold([], false, false, new SymfonyStyle(new ArrayInput([]), new NullOutput()), $notification));

            $all = '';
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($projectDir.'/native', \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!str_ends_with($file->getFilename(), '.jar')) {
                    $all .= file_get_contents($file->getPathname());
                }
            }

            self::assertStringNotContainsString('%NATIVE_', $all);
            self::assertStringContainsString('LOAD_NO_CACHE', $all);
            self::assertStringContainsString('FLAG_DEBUGGABLE', $all);
            self::assertStringContainsString('WKWebsiteDataTypeDiskCache', $all);
            self::assertStringNotContainsString('allWebsiteDataTypes', $all);
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
        $scaffolder = new InitScaffolder($filesystem, $kernel, $config, \dirname(__DIR__), new OfflineScaffolder($filesystem, $kernel, \dirname(__DIR__)), new NativeProjectUpdater($filesystem, \dirname(__DIR__)));

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
            self::assertFileDoesNotExist($projectDir.'/assets/controllers/bridge/barcode_scanner_controller.js');

            self::assertSame($admob, is_file($projectDir.'/native/android/app/src/main/java/com/acme/demo/AdMobComponent.kt'));
            self::assertSame($admob, is_file($projectDir.'/native/ios/NativeApp/AdMobComponent.swift'));
            self::assertSame($admob, str_contains($all, 'play-services-ads:25.5.0'));
            self::assertSame($admob, str_contains($all, 'ca-app-pub-111~222'));
            self::assertSame($admob, str_contains($all, 'GADApplicationIdentifier'));
            self::assertSame($admob, str_contains($all, 'ca-app-pub-333~444'));
            self::assertSame($admob, str_contains($all, 'swift-package-manager-google-mobile-ads'));
            self::assertSame($admob, str_contains($all, 'GoogleMobileAds in Frameworks'));
            self::assertFileDoesNotExist($projectDir.'/assets/controllers/bridge/admob_controller.js');

            $bootstrap = (string) file_get_contents($projectDir.'/assets/stimulus_bootstrap.js');
            self::assertStringContainsString('controller.identifier !== "bridge--notification-token"', $bootstrap);
            self::assertFileDoesNotExist($projectDir.'/assets/controllers/bridge/notification_token_controller.js');
            self::assertFileDoesNotExist($projectDir.'/assets/controllers/bridge/live_action.js');
            if ($barcodeScanner) {
                self::assertStringContainsString('controller.identifier !== "bridge--barcode-scanner"', $bootstrap);
            } else {
                self::assertStringNotContainsString('bridge--barcode-scanner', $bootstrap);
            }
        } finally {
            $filesystem->remove($projectDir);
        }
    }

    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    public function testCalendarAndGameFlags(bool $calendar, bool $game): void
    {
        $filesystem = new Filesystem();
        $projectDir = sys_get_temp_dir().'/native-cli-test-'.bin2hex(random_bytes(4));
        $filesystem->mkdir($projectDir);
        $scaffolder = $this->scaffolder($filesystem, $projectDir, ['calendar_usage_description' => 'Ajoutez ce match à votre agenda.']);

        try {
            self::assertSame(0, $scaffolder->scaffold([], false, false, new SymfonyStyle(new ArrayInput([]), new NullOutput()), false, false, false, $calendar, $game));

            $all = $this->readProject($projectDir);
            $java = $projectDir.'/native/android/app/src/main/java/com/acme/demo/';
            $manifest = (string) file_get_contents($projectDir.'/native/android/app/src/main/AndroidManifest.xml');
            $plist = (string) file_get_contents($projectDir.'/native/ios/NativeApp/Info.plist');
            $delegate = (string) file_get_contents($projectDir.'/native/ios/NativeApp/AppDelegate.swift');

            self::assertStringNotContainsString('%NATIVE_', $all);
            self::assertSame(1, substr_count($all, 'import dev.hotwire.core.bridge.BridgeComponentFactory'));

            self::assertSame($calendar, is_file($java.'CalendarComponent.kt'));
            self::assertSame($calendar, is_file($projectDir.'/native/ios/NativeApp/CalendarComponent.swift'));
            self::assertSame($calendar, str_contains($all, 'BridgeComponentFactory("calendar", ::CalendarComponent)'));
            self::assertSame($calendar, str_contains($delegate, 'CalendarComponent.self'));
            self::assertSame($calendar, str_contains($plist, 'NSCalendarsFullAccessUsageDescription'));
            self::assertSame($calendar, str_contains($plist, '<string>Ajoutez ce match à votre agenda.</string>'));
            self::assertSame($calendar ? 2 : 0, substr_count($plist, '<!--###calendar_usage###-->') / 2);
            self::assertSame($calendar, str_contains($all, 'CalendarComponent.swift in Sources'));
            self::assertSame($calendar, str_contains(
                (string) file_get_contents($projectDir.'/native/ios/NativeApp.xcodeproj/project.pbxproj'),
                'path = CalendarComponent.swift'
            ));

            self::assertSame($game, is_file($java.'GameComponent.kt'));
            self::assertSame($game, is_file($projectDir.'/native/ios/NativeApp/GameComponent.swift'));
            self::assertSame($game, str_contains($all, 'BridgeComponentFactory("game", ::GameComponent)'));
            self::assertSame($game, str_contains($delegate, 'GameComponent.self'));
            self::assertSame($game, str_contains($delegate, 'GameMode.orientations'));
            self::assertSame($game, str_contains($delegate, 'HotwireWebViewController, GameScreen {'));
            self::assertSame($game, str_contains($delegate, 'var isGame = false'));
            self::assertSame($game, str_contains($plist, 'UIInterfaceOrientationLandscapeLeft'));
            self::assertSame($game, str_contains($manifest, 'android:configChanges="orientation|screenSize|screenLayout|smallestScreenSize"'));
            self::assertSame(!$game, str_contains($manifest, 'android:configChanges="orientation|screenSize"'));
            self::assertSame($game, str_contains($all, 'swiperefreshlayout:1.1.0'));
            self::assertSame($game, str_contains($all, 'GameComponent.swift in Sources'));
        } finally {
            $filesystem->remove($projectDir);
        }
    }

    public function testUpdateAddsCalendarAndGameOnceToAnExistingProject(): void
    {
        $filesystem = new Filesystem();
        $projectDir = sys_get_temp_dir().'/native-cli-test-'.bin2hex(random_bytes(4));
        $filesystem->mkdir($projectDir);
        $scaffolder = $this->scaffolder($filesystem, $projectDir, []);
        $updater = new NativeProjectUpdater($filesystem, \dirname(__DIR__));
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $android = $projectDir.'/native/android';
        $ios = $projectDir.'/native/ios';
        $config = ['app_name' => 'Demo', 'url' => 'http://127.0.0.1:8000', 'top_bar' => true];

        try {
            self::assertSame(0, $scaffolder->scaffold([], false, false, $io));
            $before = $this->readProject($projectDir);
            self::assertStringNotContainsString('CalendarComponent', $before);
            self::assertStringNotContainsString('GameComponent', $before);
            self::assertStringNotContainsString('GameScreen', $before);

            // Game first, then the notification bridge: its launchMode must still land on the changed configChanges.
            foreach ([[false, false, true, true], [true, false, true, true]] as [$notification, $barcode, $calendar, $game]) {
                self::assertSame(0, $updater->apply($config, $projectDir, $android, $ios, $io, $notification, $barcode, false, $calendar, $game));
            }

            $all = $this->readProject($projectDir);
            self::assertSame(1, substr_count($all, 'BridgeComponentFactory("calendar", ::CalendarComponent)'));
            self::assertSame(1, substr_count($all, 'BridgeComponentFactory("game", ::GameComponent)'));
            self::assertSame(1, substr_count($all, 'CalendarComponent.swift in Sources */,'));
            self::assertSame(1, substr_count($all, 'GameComponent.swift in Sources */,'));
            self::assertSame(1, substr_count($all, 'swiperefreshlayout:1.1.0'));
            self::assertSame(1, substr_count($all, 'func application(_ application: UIApplication, supportedInterfaceOrientationsFor'));
            self::assertSame(1, substr_count($all, ', GameScreen'));
            self::assertSame(1, substr_count($all, 'var isGame = false'));
            self::assertSame(1, substr_count($all, 'UIInterfaceOrientationLandscapeLeft'));
            self::assertSame(1, substr_count($all, '<key>NSCalendarsUsageDescription</key>'));

            $manifest = (string) file_get_contents($android.'/app/src/main/AndroidManifest.xml');
            self::assertSame(1, substr_count($manifest, 'screenLayout|smallestScreenSize'));
            self::assertSame(1, substr_count($manifest, 'android:launchMode="singleTask"'));
        } finally {
            $filesystem->remove($projectDir);
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function scaffolder(Filesystem $filesystem, string $projectDir, array $extra): InitScaffolder
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($projectDir);
        $bundleRoot = \dirname(__DIR__);

        return new InitScaffolder($filesystem, $kernel, [
            'app_name' => 'Demo',
            'url' => 'http://127.0.0.1:8000',
            'application_id' => 'com.acme.demo',
            'bundle_id' => 'com.acme.demo',
            'android_path' => null,
            'ios_path' => null,
            'ios_project' => 'skip-xcodebuild.xcodeproj',
            'ios_development_team' => null,
        ] + $extra, $bundleRoot, new OfflineScaffolder($filesystem, $kernel, $bundleRoot), new NativeProjectUpdater($filesystem, $bundleRoot));
    }

    private function readProject(string $projectDir): string
    {
        $all = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($projectDir.'/native', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!str_ends_with($file->getFilename(), '.jar')) {
                $all .= file_get_contents($file->getPathname());
            }
        }

        return $all;
    }

    public function testIconBecomesAdaptiveLauncherIcon(): void
    {
        if (null === (new ExecutableFinder())->find('sips')) {
            self::markTestSkipped('sips (macOS) requis.');
        }
        $filesystem = new Filesystem();
        $projectDir = sys_get_temp_dir().'/native-cli-test-'.bin2hex(random_bytes(4));
        $filesystem->mkdir($projectDir);
        $icon = $projectDir.'/logo.png';
        file_put_contents($icon, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
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
            'ios_development_team' => null,
            'icon' => $icon,
            'icon_background' => '#1e293b',
        ];
        $scaffolder = new InitScaffolder($filesystem, $kernel, $config, $bundleRoot, new OfflineScaffolder($filesystem, $kernel, $bundleRoot), new NativeProjectUpdater($filesystem, $bundleRoot));
        $res = $projectDir.'/native/android/app/src/main/res';

        try {
            self::assertSame(0, $scaffolder->scaffold([], false, false, new SymfonyStyle(new ArrayInput([]), new NullOutput())));

            foreach (['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'] as $density) {
                self::assertFileExists($res.'/mipmap-'.$density.'/ic_launcher_logo.png');
                self::assertFileDoesNotExist($res.'/mipmap-'.$density.'/ic_launcher.png');
            }
            self::assertStringContainsString('<inset android:drawable="@mipmap/ic_launcher_logo"', (string) file_get_contents($res.'/mipmap-anydpi-v26/ic_launcher.xml'));
            self::assertStringContainsString('<color name="ic_launcher_bg">#1E293B</color>', (string) file_get_contents($res.'/values/ic_launcher_bg.xml'));
            self::assertStringContainsString('android:icon="@mipmap/ic_launcher"', (string) file_get_contents($projectDir.'/native/android/app/src/main/AndroidManifest.xml'));
            self::assertFileExists($projectDir.'/native/ios/NativeApp/Assets.xcassets/AppIcon.appiconset/icon-1024.png');
        } finally {
            $filesystem->remove($projectDir);
        }
    }

    public function testUpdateRewritesMarkersWithoutTouchingHandEdits(): void
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
            'ios_development_team' => null,
            'top_bar' => true,
            'camera_usage_description' => 'Scannez ce QR code.',
            'admob' => [
                'android_app_id' => 'ca-app-pub-111~222',
                'ios_app_id' => 'ca-app-pub-333~444',
            ],
        ];
        $updater = new NativeProjectUpdater($filesystem, $bundleRoot);
        $scaffolder = new InitScaffolder($filesystem, $kernel, $config, $bundleRoot, new OfflineScaffolder($filesystem, $kernel, $bundleRoot), $updater);
        $activity = $projectDir.'/native/android/app/src/main/java/com/acme/demo/MainActivity.kt';

        try {
            self::assertSame(0, $scaffolder->scaffold([], false, false, new SymfonyStyle(new ArrayInput([]), new NullOutput())));
            $original = (string) file_get_contents($activity);
            self::assertStringContainsString('/*###url###*/"http://127.0.0.1:8000"/*###url###*/', $original);
            file_put_contents($activity, $original."\n// hand-edit-survives\n");

            $config['url'] = 'http://10.0.0.8:8000';
            $config['app_name'] = 'Renamed';
            $config['top_bar'] = false;
            self::assertSame(0, $updater->apply($config, $projectDir, $projectDir.'/native/android', $projectDir.'/native/ios', new SymfonyStyle(new ArrayInput([]), new NullOutput()), false, true, false));
            self::assertSame(0, $updater->apply($config, $projectDir, $projectDir.'/native/android', $projectDir.'/native/ios', new SymfonyStyle(new ArrayInput([]), new NullOutput()), false, true, false));

            $updated = (string) file_get_contents($activity);
            self::assertStringContainsString('// hand-edit-survives', $updated);
            self::assertStringContainsString('/*###url###*/"http://10.0.0.8:8000"/*###url###*/', $updated);
            self::assertStringNotContainsString('127.0.0.1:8000', $updated);
            self::assertStringContainsString('/*###top_bar###*/false/*###top_bar###*/', $updated);
            self::assertSame(1, substr_count((string) file_get_contents($projectDir.'/native/android/app/build.gradle.kts'), 'play-services-code-scanner:16.1.0'));
            self::assertStringContainsString('<!--###camera_usage###-->', (string) file_get_contents($projectDir.'/native/ios/NativeApp/Info.plist'));
            self::assertStringContainsString('<string>Scannez ce QR code.</string>', (string) file_get_contents($projectDir.'/native/ios/NativeApp/Info.plist'));
            self::assertStringContainsString('<!--###app_name###-->Renamed<!--###app_name###-->', (string) file_get_contents($projectDir.'/native/android/app/src/main/res/values/strings.xml'));
        } finally {
            $filesystem->remove($projectDir);
        }
    }
}
