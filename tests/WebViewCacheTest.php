<?php

namespace Akyos\UxNativeCliBundle\Tests;

use Akyos\UxNativeCliBundle\Service\NativeProjectUpdater;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WebViewCacheTest extends TestCase
{
    private const APPLICATION = <<<'KOTLIN'
        package com.acme.demo

        import android.app.Application
        import android.webkit.WebSettings
        import com.masilotti.bridgecomponents.shared.Bridgework
        import dev.hotwire.core.bridge.KotlinXJsonConverter
        import dev.hotwire.core.config.Hotwire
        import dev.hotwire.core.turbo.config.PathConfiguration
        import dev.hotwire.core.turbo.webview.HotwireWebView
        import dev.hotwire.navigation.config.registerBridgeComponents

        class NativeApplication : Application() {
            override fun onCreate() {
                super.onCreate()
                Hotwire.config.jsonConverter = KotlinXJsonConverter()
        %CACHE%        Hotwire.registerBridgeComponents(*Bridgework.coreComponents)
            }
        }

        KOTLIN;

    private const APP_DELEGATE = <<<'SWIFT'
        import UIKit

        @main
        class AppDelegate: UIResponder, UIApplicationDelegate {
            func application(
                _ application: UIApplication,
                didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
            ) -> Bool {
        %CACHE%        return true
            }
        }

        SWIFT;

    /** Projects generated with 0.5 cleared every website data type, cookies included, in release builds too. */
    public function testUnconditionalCacheClearingIsMigratedToDebugBuilds(): void
    {
        $android = <<<'KOTLIN'
                    // A debug install keeps the previous HTML and JavaScript otherwise.
                    Hotwire.config.makeCustomWebView = { context ->
                        HotwireWebView(context).apply {
                            settings.cacheMode = WebSettings.LOAD_NO_CACHE
                            clearCache(true)
                        }
                    }

            KOTLIN;
        $ios = <<<'SWIFT'
                    URLCache.shared.removeAllCachedResponses()
                    WKWebsiteDataStore.default().removeData(ofTypes: WKWebsiteDataStore.allWebsiteDataTypes(), modifiedSince: Date(timeIntervalSince1970: 0)) {}

            SWIFT;

        [$application, $delegate] = $this->update(str_replace('%CACHE%', $android, self::APPLICATION), str_replace('%CACHE%', $ios, "import UIKit\nimport WebKit\n".substr(self::APP_DELEGATE, \strlen("import UIKit\n"))));

        $this->assertDebugOnly($application, $delegate);
    }

    public function testProjectWithoutCacheClearingGetsTheDebugOnlyVersion(): void
    {
        [$application, $delegate] = $this->update(
            str_replace(["import android.webkit.WebSettings\n", "import dev.hotwire.core.turbo.webview.HotwireWebView\n", '%CACHE%'], '', self::APPLICATION),
            str_replace('%CACHE%', '', self::APP_DELEGATE),
        );

        $this->assertDebugOnly($application, $delegate);
        self::assertStringContainsString("import UIKit\nimport WebKit\n", $delegate);
    }

    public function testUpdateIsIdempotent(): void
    {
        [$application, $delegate] = $this->update(str_replace('%CACHE%', '', self::APPLICATION), str_replace('%CACHE%', '', self::APP_DELEGATE));
        [$again, $delegateAgain] = $this->update($application, $delegate);

        self::assertSame($application, $again);
        self::assertSame($delegate, $delegateAgain);
    }

    private function assertDebugOnly(string $application, string $delegate): void
    {
        self::assertStringContainsString("if ((applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0) {\n            Hotwire.config.makeCustomWebView", $application);
        self::assertSame(1, substr_count($application, 'LOAD_NO_CACHE'));
        self::assertSame(1, substr_count($application, "import android.content.pm.ApplicationInfo\n"));
        self::assertSame(1, substr_count($application, "import android.webkit.WebSettings\n"));
        self::assertSame(1, substr_count($application, "import dev.hotwire.core.turbo.webview.HotwireWebView\n"));

        self::assertStringNotContainsString('allWebsiteDataTypes', $delegate);
        self::assertSame(1, substr_count($delegate, 'removeAllCachedResponses'));
        self::assertMatchesRegularExpression('/#if DEBUG\n(?:\s*\/\/.*\n)*\s*URLCache\.shared\.removeAllCachedResponses\(\)\n\s*WKWebsiteDataStore\.default\(\)\.removeData\(ofTypes: \[WKWebsiteDataTypeDiskCache, WKWebsiteDataTypeMemoryCache\].*\n\s*#endif\n/', $delegate);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function update(string $application, string $delegate): array
    {
        $filesystem = new Filesystem();
        $root = sys_get_temp_dir().'/native-cache-'.bin2hex(random_bytes(4));
        $applicationPath = $root.'/android/app/src/main/java/com/acme/demo/NativeApplication.kt';
        $delegatePath = $root.'/ios/NativeApp/AppDelegate.swift';
        $filesystem->dumpFile($applicationPath, $application);
        $filesystem->dumpFile($delegatePath, $delegate);

        try {
            (new \ReflectionMethod(NativeProjectUpdater::class, 'disableWebViewCache'))
                ->invoke(new NativeProjectUpdater($filesystem, \dirname(__DIR__)), $root.'/android', $root.'/ios', 'com.acme.demo');

            return [(string) file_get_contents($applicationPath), (string) file_get_contents($delegatePath)];
        } finally {
            $filesystem->remove($root);
        }
    }
}
