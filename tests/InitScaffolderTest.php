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
}
