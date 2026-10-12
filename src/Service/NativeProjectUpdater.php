<?php

namespace Akyos\UxNativeCliBundle\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class NativeProjectUpdater
{
    public function __construct(
        private Filesystem $filesystem,
        private string $bundleRoot,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function apply(array $config, string $projectDir, string $androidRoot, string $iosRoot, SymfonyStyle $io, bool $notification = false, bool $barcodeScanner = false, bool $admob = false): int
    {
        if (!is_dir($androidRoot) || !is_dir($iosRoot)) {
            $io->error('Projet natif introuvable. Lancez native:init d\'abord.');

            return 1;
        }

        $package = $this->androidPackage($androidRoot);
        if (null === $package) {
            $io->error('Impossible de lire le package Kotlin dans MainActivity.kt.');

            return 1;
        }

        if ($notification) {
            $this->ensureNotification($androidRoot, $iosRoot, $package, $io);
        }
        if ($barcodeScanner) {
            $this->ensureBarcodeScanner($androidRoot, $iosRoot, $package, $io);
        }
        if ($admob) {
            $this->ensureAdmob($androidRoot, $iosRoot, $package, $io);
        }

        $counts = $this->replaceMarkers($androidRoot, $iosRoot, [
            'url' => (string) $config['url'],
            'app_name' => (string) $config['app_name'],
            'top_bar' => ($config['top_bar'] ?? true) ? 'true' : 'false',
            'camera_usage' => (string) ($config['camera_usage_description'] ?? 'Scanner un QR code.'),
            'admob_android_app_id' => (string) ($config['admob']['android_app_id'] ?? 'ca-app-pub-3940256099942544~3347511713'),
            'admob_ios_app_id' => (string) ($config['admob']['ios_app_id'] ?? 'ca-app-pub-3940256099942544~1458002511'),
        ]);
        foreach (['url' => '###url###', 'app_name' => '###app_name###', 'top_bar' => '###top_bar###'] as $key => $label) {
            if (0 === ($counts[$key] ?? 0)) {
                $io->warning(sprintf('Repère %s introuvable dans le projet natif.', $label));
            }
        }

        $this->disableWebViewCache($androidRoot, $iosRoot, $package);
        $this->installIcon($config, $projectDir, $androidRoot, $iosRoot, $io);
        $this->installWeb($projectDir, $io, $notification, $barcodeScanner, $admob);

        return 0;
    }

    private function ensureNotification(string $androidRoot, string $iosRoot, string $package, SymfonyStyle $io): void
    {
        $snippets = InitScaffolder::notificationSnippets();
        $this->copyKotlin('templates/notification/android/app/src/main/java/com/example/nativeapp/NotificationTokenComponent.kt', 'NotificationTokenComponent.kt', $androidRoot, $package);
        $this->copyKotlin('templates/notification/android/app/src/main/java/com/example/nativeapp/NativeMessagingService.kt', 'NativeMessagingService.kt', $androidRoot, $package);
        $this->copyFile('templates/notification/ios/NativeApp/NotificationTokenComponent.swift', Path::join($iosRoot, 'NativeApp', 'NotificationTokenComponent.swift'));
        $this->copyFile('templates/notification/ios/NativeApp/NativeApp.entitlements', Path::join($iosRoot, 'NativeApp', 'NativeApp.entitlements'));

        $this->patch(Path::join($androidRoot, 'build.gradle.kts'), function (string $content) use ($snippets): string {
            return $this->insertAfter($content, 'id("org.jetbrains.kotlin.android") version "2.3.0" apply false', $snippets['%NATIVE_NOTIF_GRADLE_ROOT_PLUGIN%'], 'com.google.gms.google-services');
        });
        $this->patch(Path::join($androidRoot, 'app', 'build.gradle.kts'), function (string $content) use ($snippets): string {
            $content = $this->insertAfter($content, '    id("org.jetbrains.kotlin.android")', $snippets['%NATIVE_NOTIF_GRADLE_APP_PLUGIN%'], 'id("com.google.gms.google-services")');
            if (!str_contains($content, 'googleServices')) {
                $content = str_replace('dependencies {', $snippets['%NATIVE_NOTIF_GRADLE_APP_CONFIG%']."\n\ndependencies {", $content);
            }
            $content = $this->insertAfter($content, 'implementation("com.github.joemasilotti:bridge-components:0.13.2")', $snippets['%NATIVE_NOTIF_GRADLE_APP_DEPS%'], 'firebase-messaging');

            return $content;
        });
        $this->patch(Path::join($androidRoot, 'app', 'src', 'main', 'AndroidManifest.xml'), function (string $content) use ($snippets): string {
            $content = $this->insertAfter($content, '<uses-permission android:name="android.permission.INTERNET" />', $snippets['%NATIVE_NOTIF_ANDROID_PERMISSION%'], 'POST_NOTIFICATIONS');
            $content = $this->insertAfter($content, 'android:configChanges="orientation|screenSize"', $snippets['%NATIVE_NOTIF_ANDROID_LAUNCH_MODE%'], 'android:launchMode');
            if (!str_contains($content, 'NativeMessagingService')) {
                $content = str_replace('</application>', $snippets['%NATIVE_NOTIF_ANDROID_SERVICE%']."\n    </application>", $content);
            }

            return $content;
        });
        $activity = $this->kotlinFile($androidRoot, $package, 'MainActivity.kt');
        $this->patch($activity, function (string $content) use ($snippets): string {
            $content = $this->insertAfter($content, 'import android.os.Bundle', $snippets['%NATIVE_NOTIF_ANDROID_INTENT_IMPORT%'], 'import android.content.Intent');
            $content = $this->insertAfter($content, 'private var tabs: List<NativeTab> = emptyList()', $snippets['%NATIVE_NOTIF_ANDROID_PENDING_URL%'], 'pendingUrl');
            $content = $this->insertAfter($content, 'bottomNav.visibility = View.GONE', $snippets['%NATIVE_NOTIF_ANDROID_READ_INTENT%'], 'pendingUrl = intent');
            $content = $this->insertAfter($content, 'applyTabs(bottomNav)', $snippets['%NATIVE_NOTIF_ANDROID_CONSUME_URL%'], 'pendingUrl?.let');
            if (!str_contains($content, 'override fun onNewIntent')) {
                $content = str_replace('override fun navigatorConfigurations()', $snippets['%NATIVE_NOTIF_ANDROID_ON_NEW_INTENT%']."\n\n    override fun navigatorConfigurations()", $content);
            }

            return $content;
        });
        $this->patch($this->kotlinFile($androidRoot, $package, 'NativeApplication.kt'), function (string $content) use ($snippets): string {
            return $this->registerAndroidComponent($content, $snippets['%NATIVE_NOTIF_ANDROID_COMPONENTS%'], '::NotificationTokenComponent');
        });

        $this->patch(Path::join($iosRoot, 'NativeApp', 'AppDelegate.swift'), function (string $content) use ($snippets): string {
            $content = $this->insertAfter($content, 'import UIKit', $snippets['%NATIVE_NOTIF_IOS_IMPORT%'], 'import UserNotifications');
            $content = $this->registerIosComponent($content, $snippets['%NATIVE_NOTIF_IOS_COMPONENTS%'], 'NotificationTokenComponent.self');
            if (!str_contains($content, 'UNUserNotificationCenter.current()')) {
                $content = preg_replace('/^(.*Hotwire\.registerBridgeComponents\(.*\))$/m', '$1'.$snippets['%NATIVE_NOTIF_IOS_SETUP%'], $content, 1) ?? $content;
            }
            if (!str_contains($content, 'UNUserNotificationCenterDelegate')) {
                $content .= $snippets['%NATIVE_NOTIF_IOS_DELEGATE%'];
            }

            return $content;
        });
        $this->patch(Path::join($iosRoot, 'NativeApp', 'SceneDelegate.swift'), function (string $content) use ($snippets): string {
            $content = $this->insertAfter($content, 'private var currentTabs: [TabConfiguration]?', $snippets['%NATIVE_NOTIF_IOS_SCENE_PROPERTY%'], 'notificationURLObserver');
            $content = $this->insertAfter($content, 'window.makeKeyAndVisible()', $snippets['%NATIVE_NOTIF_IOS_SCENE_SETUP%'], 'nativeOpenNotificationURL');
            if (!str_contains($content, 'openPendingNotificationURL')) {
                $end = strrpos($content, '}');
                if (false !== $end) {
                    $content = substr($content, 0, $end).$snippets['%NATIVE_NOTIF_IOS_SCENE_METHODS%']."\n}";
                }
            }

            return $content;
        });
        $this->patchPbx($iosRoot, function (string $content) use ($snippets): string {
            if (str_contains($content, 'NotificationTokenComponent.swift')) {
                return $content;
            }
            $content = $this->insertAfter($content, 'B20100000000000000000007 /* TabConfigurationProvider.swift in Sources */ = {isa = PBXBuildFile; fileRef = B20100000000000000000016 /* TabConfigurationProvider.swift */; };', $snippets['%NATIVE_NOTIF_PBX_BUILDFILE%'], 'NotificationTokenComponent.swift in Sources');
            $content = $this->insertAfter($content, 'B20100000000000000000016 /* TabConfigurationProvider.swift */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = TabConfigurationProvider.swift; sourceTree = "<group>"; };', $snippets['%NATIVE_NOTIF_PBX_FILEREF%'], 'NotificationTokenComponent.swift */');
            $content = $this->insertAfter($content, 'B20100000000000000000016 /* TabConfigurationProvider.swift */,', $snippets['%NATIVE_NOTIF_PBX_GROUP%'], 'NotificationTokenComponent.swift */,');
            $content = $this->insertAfter($content, 'B20100000000000000000007 /* TabConfigurationProvider.swift in Sources */,', $snippets['%NATIVE_NOTIF_PBX_SOURCES%'], 'NotificationTokenComponent.swift in Sources */,');
            if (!str_contains($content, 'CODE_SIGN_ENTITLEMENTS')) {
                $content = str_replace('CODE_SIGN_STYLE = Automatic;', 'CODE_SIGN_STYLE = Automatic;'.$snippets['%NATIVE_NOTIF_PBX_ENTITLEMENTS%'], $content);
            }

            return $content;
        }, $io);
    }

    private function ensureBarcodeScanner(string $androidRoot, string $iosRoot, string $package, SymfonyStyle $io): void
    {
        $snippets = InitScaffolder::barcodeSnippets();
        $this->copyKotlin('templates/barcode-scanner/android/app/src/main/java/com/example/nativeapp/BarcodeScannerComponent.kt', 'BarcodeScannerComponent.kt', $androidRoot, $package);
        $this->copyFile('templates/barcode-scanner/ios/NativeApp/BarcodeScannerComponent.swift', Path::join($iosRoot, 'NativeApp', 'BarcodeScannerComponent.swift'));
        $this->patch(Path::join($androidRoot, 'app', 'build.gradle.kts'), function (string $content) use ($snippets): string {
            return $this->insertAfter($content, 'implementation("com.github.joemasilotti:bridge-components:0.13.2")', $snippets['%NATIVE_BARCODE_GRADLE_DEPS%'], 'play-services-code-scanner');
        });
        $this->patch(Path::join($androidRoot, 'app', 'src', 'main', 'AndroidManifest.xml'), function (string $content): string {
            if (str_contains($content, 'barcode_ui')) {
                return $content;
            }

            return str_replace('</application>', '        '.InitScaffolder::barcodeManifestMetadata()."\n    </application>", $content);
        });
        $this->patch($this->kotlinFile($androidRoot, $package, 'NativeApplication.kt'), function (string $content) use ($snippets): string {
            return $this->registerAndroidComponent($content, $snippets['%NATIVE_BARCODE_ANDROID_COMPONENTS%'], '::BarcodeScannerComponent');
        });
        $this->patch(Path::join($iosRoot, 'NativeApp', 'AppDelegate.swift'), function (string $content) use ($snippets): string {
            return $this->registerIosComponent($content, $snippets['%NATIVE_BARCODE_IOS_COMPONENTS%'], 'BarcodeScannerComponent.self');
        });
        $this->patch(Path::join($iosRoot, 'NativeApp', 'Info.plist'), function (string $content): string {
            if (str_contains($content, 'NSCameraUsageDescription')) {
                return $content;
            }

            return $this->insertBeforeLast($content, "</dict>\n</plist>", "\n".InitScaffolder::cameraPlistExtra()."\n");
        });
        $this->patchPbx($iosRoot, function (string $content) use ($snippets): string {
            if (str_contains($content, 'BarcodeScannerComponent.swift')) {
                return $content;
            }
            $content = $this->insertAfter($content, 'B20100000000000000000007 /* TabConfigurationProvider.swift in Sources */ = {isa = PBXBuildFile; fileRef = B20100000000000000000016 /* TabConfigurationProvider.swift */; };', $snippets['%NATIVE_BARCODE_PBX_BUILDFILE%'], 'BarcodeScannerComponent.swift in Sources');
            $content = $this->insertAfter($content, 'B20100000000000000000016 /* TabConfigurationProvider.swift */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = TabConfigurationProvider.swift; sourceTree = "<group>"; };', $snippets['%NATIVE_BARCODE_PBX_FILEREF%'], 'path = BarcodeScannerComponent.swift');
            $content = $this->insertAfter($content, 'B20100000000000000000016 /* TabConfigurationProvider.swift */,', $snippets['%NATIVE_BARCODE_PBX_GROUP%'], 'BarcodeScannerComponent.swift */,');

            return $this->insertAfter($content, 'B20100000000000000000007 /* TabConfigurationProvider.swift in Sources */,', $snippets['%NATIVE_BARCODE_PBX_SOURCES%'], 'BarcodeScannerComponent.swift in Sources */,');
        }, $io);
    }

    private function ensureAdmob(string $androidRoot, string $iosRoot, string $package, SymfonyStyle $io): void
    {
        $snippets = InitScaffolder::admobSnippets();
        $this->copyKotlin('templates/admob/android/app/src/main/java/com/example/nativeapp/AdMobComponent.kt', 'AdMobComponent.kt', $androidRoot, $package);
        $this->copyFile('templates/admob/ios/NativeApp/AdMobComponent.swift', Path::join($iosRoot, 'NativeApp', 'AdMobComponent.swift'));
        $this->patch(Path::join($androidRoot, 'app', 'build.gradle.kts'), function (string $content) use ($snippets): string {
            return $this->insertAfter($content, 'implementation("com.github.joemasilotti:bridge-components:0.13.2")', $snippets['%NATIVE_ADMOB_GRADLE_DEPS%'], 'play-services-ads');
        });
        $this->patch(Path::join($androidRoot, 'app', 'src', 'main', 'AndroidManifest.xml'), function (string $content): string {
            if (str_contains($content, 'com.google.android.gms.ads.APPLICATION_ID')) {
                return $content;
            }

            return str_replace('</application>', '        '.InitScaffolder::admobManifestMetadata()."\n    </application>", $content);
        });
        $this->patch($this->kotlinFile($androidRoot, $package, 'NativeApplication.kt'), function (string $content) use ($snippets): string {
            return $this->registerAndroidComponent($content, $snippets['%NATIVE_ADMOB_ANDROID_COMPONENTS%'], '::AdMobComponent');
        });
        $this->patch(Path::join($iosRoot, 'NativeApp', 'AppDelegate.swift'), function (string $content) use ($snippets): string {
            return $this->registerIosComponent($content, $snippets['%NATIVE_ADMOB_IOS_COMPONENTS%'], 'AdMobComponent.self');
        });
        $this->patch(Path::join($iosRoot, 'NativeApp', 'Info.plist'), function (string $content): string {
            if (str_contains($content, 'GADApplicationIdentifier')) {
                return $content;
            }

            return $this->insertBeforeLast($content, "</dict>\n</plist>", "\n".InitScaffolder::admobPlistExtra()."\n");
        });
        $this->patchPbx($iosRoot, function (string $content) use ($snippets): string {
            if (!str_contains($content, 'AdMobComponent.swift')) {
                $content = $this->insertAfter($content, 'B20100000000000000000007 /* TabConfigurationProvider.swift in Sources */ = {isa = PBXBuildFile; fileRef = B20100000000000000000016 /* TabConfigurationProvider.swift */; };', $snippets['%NATIVE_ADMOB_PBX_BUILDFILE%'], 'AdMobComponent.swift in Sources');
                $content = $this->insertAfter($content, 'B20100000000000000000016 /* TabConfigurationProvider.swift */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = TabConfigurationProvider.swift; sourceTree = "<group>"; };', $snippets['%NATIVE_ADMOB_PBX_FILEREF%'], 'AdMobComponent.swift */');
                $content = $this->insertAfter($content, 'B20100000000000000000016 /* TabConfigurationProvider.swift */,', $snippets['%NATIVE_ADMOB_PBX_GROUP%'], 'AdMobComponent.swift */,');
                $content = $this->insertAfter($content, 'B20100000000000000000007 /* TabConfigurationProvider.swift in Sources */,', $snippets['%NATIVE_ADMOB_PBX_SOURCES%'], 'AdMobComponent.swift in Sources */,');
            }
            if (!str_contains($content, 'GoogleMobileAds in Frameworks */,')) {
                $content = $this->insertAfter($content, 'B20100000000000000000005 /* BridgeComponents in Frameworks */,', $snippets['%NATIVE_ADMOB_PBX_FRAMEWORKS%'], 'GoogleMobileAds in Frameworks */,');
            }
            if (!str_contains($content, 'B20100000000000000000024 /* GoogleMobileAds */,')) {
                $content = $this->insertAfter($content, 'B20100000000000000000022 /* BridgeComponents */,', $snippets['%NATIVE_ADMOB_PBX_PRODUCTS%'], 'B20100000000000000000024 /* GoogleMobileAds */,');
            }
            if (!str_contains($content, 'swift-package-manager-google-mobile-ads')) {
                $content = $this->insertAfter($content, 'B20100000000000000000023 /* XCRemoteSwiftPackageReference "bridge-components" */,', $snippets['%NATIVE_ADMOB_PBX_PACKAGES%'], 'swift-package-manager-google-mobile-ads');
                $content = str_replace('/* End XCRemoteSwiftPackageReference section */', $snippets['%NATIVE_ADMOB_PBX_PACKAGE_REF%']."\n/* End XCRemoteSwiftPackageReference section */", $content);
                $content = str_replace('/* End XCSwiftPackageProductDependency section */', $snippets['%NATIVE_ADMOB_PBX_PRODUCT_DEP%']."\n/* End XCSwiftPackageProductDependency section */", $content);
            }

            return $content;
        }, $io);
    }

    private function registerAndroidComponent(string $content, string $snippet, string $sentinel): string
    {
        if (str_contains($content, $sentinel)) {
            return $content;
        }
        if (!str_contains($content, 'import dev.hotwire.core.bridge.BridgeComponentFactory')) {
            $content = str_replace(
                "import com.masilotti.bridgecomponents.shared.Bridgework\n",
                "import com.masilotti.bridgecomponents.shared.Bridgework\nimport dev.hotwire.core.bridge.BridgeComponentFactory\n",
                $content
            );
        }

        return str_replace(
            'Hotwire.registerBridgeComponents(*Bridgework.coreComponents',
            'Hotwire.registerBridgeComponents(*Bridgework.coreComponents'.$snippet,
            $content
        );
    }

    private function registerIosComponent(string $content, string $snippet, string $sentinel): string
    {
        if (str_contains($content, $sentinel)) {
            return $content;
        }

        return str_replace('Bridgework.coreComponents', 'Bridgework.coreComponents'.$snippet, $content);
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, int>
     */
    private function replaceMarkers(string $androidRoot, string $iosRoot, array $values): array
    {
        $counts = [];
        foreach ([$androidRoot, $iosRoot] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if (!$file->isFile() || preg_match('#/(build|DerivedDataCli|\.gradle)/#', $path)) {
                    continue;
                }
                $extension = strtolower($file->getExtension());
                if (!\in_array($extension, ['kt', 'kts', 'xml', 'swift', 'plist', 'gradle'], true)) {
                    continue;
                }
                $content = (string) file_get_contents($path);
                if (!str_contains($content, '###')) {
                    continue;
                }
                $updated = $content;
                foreach ($values as $key => $value) {
                    $found = 0;
                    $updated = $this->replaceMarker($updated, $extension, $key, $value, $found);
                    $counts[$key] = ($counts[$key] ?? 0) + $found;
                }
                if ($updated !== $content) {
                    file_put_contents($path, $updated);
                }
            }
        }

        return $counts;
    }

    private function replaceMarker(string $content, string $extension, string $key, string $value, int &$count): string
    {
        $q = preg_quote($key, '/');
        $bool = 'top_bar' === $key;
        $plain = $bool ? ('false' === $value ? 'false' : 'true') : $value;
        $xml = $bool ? $plain : htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        $code = $bool ? $plain : str_replace(['\\', '"'], ['\\\\', '\\"'], $plain);

        $content = preg_replace_callback(
            '/\/\*###'.$q.'###\*\/("(?:\\\\.|[^"\\\\])*"|true|false)\/\*###'.$q.'###\*\//',
            static function (array $match) use ($key, $code, &$count): string {
                ++$count;
                $literal = str_starts_with($match[1], '"') ? '"'.$code.'"' : $code;

                return '/*###'.$key.'###*/'.$literal.'/*###'.$key.'###*/';
            },
            $content
        ) ?? $content;

        $content = preg_replace_callback(
            '/<!--###'.$q.'###-->(.*?)<!--###'.$q.'###-->/s',
            static function (array $match) use ($key, $xml, $extension, &$count): string {
                ++$count;
                if ('plist' === $extension && preg_match('/^(.*<string>)(.*?)(<\/string>.*)$/s', $match[1], $parts)) {
                    return '<!--###'.$key.'###-->'.$parts[1].$xml.$parts[3].'<!--###'.$key.'###-->';
                }
                if ('plist' === $extension) {
                    return '###'.$key.'###'.$xml.'###'.$key.'###';
                }

                return '<!--###'.$key.'###-->'.$xml.'<!--###'.$key.'###-->';
            },
            $content
        ) ?? $content;

        $content = preg_replace_callback(
            '/="[^"]*"(\s*\/>\s*)<!--###'.$q.'###-->/',
            static function (array $match) use ($key, $xml, &$count): string {
                ++$count;

                return '="'.$xml.'"'.$match[1].'<!--###'.$key.'###-->';
            },
            $content
        ) ?? $content;

        if (!\in_array($extension, ['xml', 'plist'], true)) {
            $content = preg_replace_callback(
                '/"###'.$q.'###(.*?)###'.$q.'###([^"]*)"/s',
                static function (array $match) use ($key, $code, &$count): string {
                    ++$count;
                    $literal = '/*###'.$key.'###*/"'.$code.'"/*###'.$key.'###*/';
                    if ('' !== $match[2]) {
                        return $literal.' + "'.str_replace(['\\', '"'], ['\\\\', '\\"'], $match[2]).'"';
                    }

                    return $literal;
                },
                $content
            ) ?? $content;
        }

        if ($bool) {
            $content = preg_replace_callback(
                '/###'.$q.'###(?:true|false)###'.$q.'###/',
                static function () use ($code, &$count): string {
                    ++$count;

                    return '/*###top_bar###*/'.$code.'/*###top_bar###*/';
                },
                $content
            ) ?? $content;
        }

        $content = preg_replace_callback(
            '/>###'.$q.'###(.*?)###'.$q.'###</s',
            static function () use ($key, $xml, $extension, &$count): string {
                ++$count;
                if ('plist' === $extension) {
                    return '>###'.$key.'###'.$xml.'###'.$key.'###<';
                }

                return '><!--###'.$key.'###-->'.$xml.'<!--###'.$key.'###--><';
            },
            $content
        ) ?? $content;

        return preg_replace_callback(
            '/="###'.$q.'###(.*?)###'.$q.'###"(\s*\/>)/s',
            static function (array $match) use ($key, $xml, &$count): string {
                ++$count;

                return '="'.$xml.'"'.$match[2].' <!--###'.$key.'###-->';
            },
            $content
        ) ?? $content;
    }

    private function disableWebViewCache(string $androidRoot, string $iosRoot, string $package): void
    {
        $application = $this->kotlinFile($androidRoot, $package, 'NativeApplication.kt');
        $this->patch($application, function (string $content): string {
            if (str_contains($content, 'LOAD_NO_CACHE')) {
                return $content;
            }
            if (!str_contains($content, 'import android.webkit.WebSettings')) {
                $content = str_replace(
                    "import android.app.Application\n",
                    "import android.app.Application\nimport android.webkit.WebSettings\n",
                    $content
                );
            }
            if (!str_contains($content, 'import dev.hotwire.core.turbo.webview.HotwireWebView')) {
                $content = str_replace(
                    "import dev.hotwire.core.turbo.config.PathConfiguration\n",
                    "import dev.hotwire.core.turbo.config.PathConfiguration\nimport dev.hotwire.core.turbo.webview.HotwireWebView\n",
                    $content
                );
            }
            $content = str_replace(
                "        Hotwire.config.jsonConverter = KotlinXJsonConverter()\n",
                "        Hotwire.config.jsonConverter = KotlinXJsonConverter()\n        Hotwire.config.makeCustomWebView = { context ->\n            HotwireWebView(context).apply {\n                settings.cacheMode = WebSettings.LOAD_NO_CACHE\n                clearCache(true)\n            }\n        }\n",
                $content
            );

            return preg_replace(
                '/(remoteFileUrl = \/\*###url###\*\/.*?\/\*###url###\*\/ \+ "\/config\/android_v1\.json",\s*\),)\s*\)/s',
                "$1\n            options = PathConfiguration.LoaderOptions(httpHeaders = mapOf(\"Cache-Control\" to \"no-cache\")),\n        )",
                $content,
                1
            ) ?? $content;
        });

        $this->patch(Path::join($iosRoot, 'NativeApp', 'AppDelegate.swift'), function (string $content): string {
            if (str_contains($content, 'WKWebsiteDataStore')) {
                return $content;
            }
            if (!str_contains($content, 'import WebKit')) {
                $content = str_replace("import UIKit\n", "import UIKit\nimport WebKit\n", $content);
            }

            return str_replace(
                ") -> Bool {\n",
                ") -> Bool {\n        URLCache.shared.removeAllCachedResponses()\n        WKWebsiteDataStore.default().removeData(ofTypes: WKWebsiteDataStore.allWebsiteDataTypes(), modifiedSince: Date(timeIntervalSince1970: 0)) {}\n",
                $content
            );
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private function installIcon(array $config, string $projectDir, string $androidRoot, string $iosRoot, SymfonyStyle $io): void
    {
        $icon = $config['icon'] ?? null;
        if (!\is_string($icon) || '' === $icon) {
            return;
        }
        if (!Path::isAbsolute($icon)) {
            $icon = Path::join($projectDir, $icon);
        }
        if (!is_file($icon)) {
            $io->warning(sprintf('Icône introuvable : %s', $icon));

            return;
        }
        if (!$this->hasSips()) {
            $io->warning('sips est introuvable : l\'icône n\'a pas été générée.');

            return;
        }

        // Icône adaptative (API 26+, minSdk 28) : un simple ic_launcher.png serait masqué par le
        // mipmap-anydpi-v26/ic_launcher.xml que bridge-components embarque dans son AAR (un pont).
        // Le logo est dessiné dans la zone sûre (66 dp sur 108) par un inset de 20 %.
        $res = Path::join($androidRoot, 'app', 'src', 'main', 'res');
        $densities = ['mipmap-mdpi' => 108, 'mipmap-hdpi' => 162, 'mipmap-xhdpi' => 216, 'mipmap-xxhdpi' => 324, 'mipmap-xxxhdpi' => 432];
        foreach ($densities as $folder => $size) {
            $directory = Path::join($res, $folder);
            $this->filesystem->mkdir($directory);
            $this->filesystem->remove(Path::join($directory, 'ic_launcher.png'));
            if (!$this->resize($icon, $size, Path::join($directory, 'ic_launcher_logo.png'), $io)) {
                return;
            }
        }
        $this->filesystem->dumpFile(Path::join($res, 'mipmap-anydpi-v26', 'ic_launcher.xml'), <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_bg" />
    <foreground>
        <inset android:drawable="@mipmap/ic_launcher_logo" android:inset="20%" />
    </foreground>
</adaptive-icon>

XML);
        $this->filesystem->dumpFile(Path::join($res, 'values', 'ic_launcher_bg.xml'), sprintf(<<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_bg">%s</color>
</resources>

XML, strtoupper((string) ($config['icon_background'] ?? '#FFFFFF'))));
        $this->patch(Path::join($androidRoot, 'app', 'src', 'main', 'AndroidManifest.xml'), function (string $content): string {
            if (str_contains($content, 'android:icon=')) {
                return $content;
            }

            return str_replace(
                'android:label="@string/app_name"',
                "android:label=\"@string/app_name\"\n        android:icon=\"@mipmap/ic_launcher\"",
                $content
            );
        });

        $appIcon = Path::join($iosRoot, 'NativeApp', 'Assets.xcassets', 'AppIcon.appiconset');
        $this->filesystem->mkdir($appIcon);
        if (!$this->resize($icon, 1024, Path::join($appIcon, 'icon-1024.png'), $io)) {
            return;
        }
        file_put_contents(Path::join($appIcon, 'Contents.json'), json_encode([
            'images' => [[
                'filename' => 'icon-1024.png',
                'idiom' => 'universal',
                'platform' => 'ios',
                'size' => '1024x1024',
            ]],
            'info' => ['author' => 'xcode', 'version' => 1],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
        $this->registerIosAssetCatalog($iosRoot, $io);
    }

    private function registerIosAssetCatalog(string $iosRoot, SymfonyStyle $io): void
    {
        $this->patchPbx($iosRoot, function (string $content): string {
            if (str_contains($content, 'Assets.xcassets')) {
                return $content;
            }
            $content = str_replace(
                '/* End PBXBuildFile section */',
                "\t\tB2010000000000000000002C /* Assets.xcassets in Resources */ = {isa = PBXBuildFile; fileRef = B2010000000000000000002D /* Assets.xcassets */; };\n/* End PBXBuildFile section */",
                $content
            );
            $content = str_replace(
                '/* End PBXFileReference section */',
                "\t\tB2010000000000000000002D /* Assets.xcassets */ = {isa = PBXFileReference; lastKnownFileType = folder.assetcatalog; path = Assets.xcassets; sourceTree = \"<group>\"; };\n/* End PBXFileReference section */",
                $content
            );
            $content = str_replace(
                'B20100000000000000000014 /* path-configuration.json */,',
                "B20100000000000000000014 /* path-configuration.json */,\n\t\t\t\tB2010000000000000000002D /* Assets.xcassets */,",
                $content
            );
            $content = str_replace(
                'B20100000000000000000003 /* path-configuration.json in Resources */,',
                "B20100000000000000000003 /* path-configuration.json in Resources */,\n\t\t\t\tB2010000000000000000002C /* Assets.xcassets in Resources */,",
                $content
            );
            if (!str_contains($content, 'ASSETCATALOG_COMPILER_APPICON_NAME')) {
                $content = str_replace(
                    'PRODUCT_BUNDLE_IDENTIFIER = ',
                    "ASSETCATALOG_COMPILER_APPICON_NAME = AppIcon;\n\t\t\t\tPRODUCT_BUNDLE_IDENTIFIER = ",
                    $content
                );
            }

            return $content;
        }, $io);
    }

    private function resize(string $source, int $size, string $destination, SymfonyStyle $io): bool
    {
        $png = $this->sourcePng($source);
        if (null === $png) {
            $io->warning('Impossible de lire l\'icône. Donnez un PNG carré.');

            return false;
        }
        $ok = $this->fitSquare($png, $size, $destination);
        if ($png !== $source) {
            @unlink($png);
        }
        if (!$ok) {
            $io->warning('Impossible de recadrer l\'icône.');
        }

        return $ok;
    }

    private function sourcePng(string $source): ?string
    {
        $data = (string) file_get_contents($source);
        if (str_starts_with($data, "\x89PNG")) {
            return $source;
        }
        if (\strlen($data) < 6 || "\x00\x00\x01\x00" !== substr($data, 0, 4)) {
            return $this->convertWithSips($source);
        }
        $count = unpack('v', substr($data, 4, 2))[1];
        $best = null;
        $bestWidth = 0;
        for ($i = 0; $i < $count; ++$i) {
            $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbitcount/Vsize/Voffset', substr($data, 6 + ($i * 16), 16));
            $blob = substr($data, $entry['offset'], $entry['size']);
            if (!str_starts_with($blob, "\x89PNG")) {
                continue;
            }
            $width = $entry['width'] ?: 256;
            if ($width < $bestWidth) {
                continue;
            }
            $bestWidth = $width;
            $best = $blob;
        }
        if (null === $best) {
            return $this->convertWithSips($source);
        }
        $path = tempnam(sys_get_temp_dir(), 'native-icon').'.png';
        file_put_contents($path, $best);

        return $path;
    }

    private function convertWithSips(string $source): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'native-icon').'.png';
        $process = new Process(['sips', '-s', 'format', 'png', $source, '--out', $path]);
        $process->run();
        if (!$process->isSuccessful() || !is_file($path)) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    private function fitSquare(string $pngPath, int $size, string $destination): bool
    {
        $src = @imagecreatefrompng($pngPath);
        if (false === $src) {
            return false;
        }
        $width = imagesx($src);
        $height = imagesy($src);
        $minX = $width;
        $minY = $height;
        $maxX = 0;
        $maxY = 0;
        $found = false;
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $rgba = imagecolorat($src, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha > 16) {
                    continue;
                }
                $found = true;
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }
        if (!$found) {
            $minX = 0;
            $minY = 0;
            $maxX = $width - 1;
            $maxY = $height - 1;
        }
        $cropWidth = $maxX - $minX + 1;
        $cropHeight = $maxY - $minY + 1;
        $canvas = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $white);
        $pad = (int) round($size * 0.08);
        $maxSide = max(1, $size - (2 * $pad));
        $scale = min($maxSide / $cropWidth, $maxSide / $cropHeight);
        $drawWidth = max(1, (int) round($cropWidth * $scale));
        $drawHeight = max(1, (int) round($cropHeight * $scale));
        imagecopyresampled(
            $canvas,
            $src,
            (int) (($size - $drawWidth) / 2),
            (int) (($size - $drawHeight) / 2),
            $minX,
            $minY,
            $drawWidth,
            $drawHeight,
            $cropWidth,
            $cropHeight,
        );
        $written = imagepng($canvas, $destination);

        return $written && str_starts_with((string) file_get_contents($destination), "\x89PNG");
    }

    private function hasSips(): bool
    {
        $process = new Process(['sips', '--help']);
        $process->run();

        return $process->isSuccessful();
    }

    private function installWeb(string $projectDir, SymfonyStyle $io, bool $notification, bool $barcodeScanner, bool $admob): void
    {
        if ($notification) {
            $excluded = ['bridge--notification-token'];
            if ($barcodeScanner) {
                $excluded[] = 'bridge--barcode-scanner';
            }
            $this->installBridgeComponentsJs($projectDir, $io, $excluded);
        }
        if ($barcodeScanner || $admob) {
            $this->requireImportmapPackages($projectDir, ['@hotwired/hotwire-native-bridge'], $io);
        }
    }

    /**
     * @param list<string> $excluded
     */
    private function installBridgeComponentsJs(string $projectDir, SymfonyStyle $io, array $excluded): void
    {
        $this->requireImportmapPackages($projectDir, ['@hotwired/hotwire-native-bridge', '@joemasilotti/bridge-components'], $io);
        $bootstrap = Path::join($projectDir, 'assets', 'stimulus_bootstrap.js');
        if (!$this->filesystem->exists($bootstrap)) {
            $io->warning('assets/stimulus_bootstrap.js introuvable — chargez les contrôleurs de @joemasilotti/bridge-components manuellement.');

            return;
        }
        $content = (string) file_get_contents($bootstrap);
        $load = '$2'.$this->bridgeLoadExpression($excluded).';';
        if (str_contains($content, '@joemasilotti/bridge-components')) {
            $synced = $this->syncBridgeControllerLoad($content, $excluded);
            if ($synced !== $content) {
                file_put_contents($bootstrap, $synced);
            }

            return;
        }
        $patched = preg_replace(
            '/^(\s*const (\w+) = startStimulusApp\(\);?)$/m',
            '$1'."\n".$load,
            'import { controllers as bridgeControllers } from "@joemasilotti/bridge-components";'."\n".$content,
            1,
        );
        if (null === $patched || !str_contains($patched, '.load(bridgeControllers')) {
            $io->warning('Impossible de patcher assets/stimulus_bootstrap.js — ajoutez app.load(controllers) de @joemasilotti/bridge-components manuellement.');

            return;
        }
        file_put_contents($bootstrap, $patched);
        $io->writeln('<info>Contrôleurs bridge--* chargés dans assets/stimulus_bootstrap.js</info>');
    }

    /**
     * @param list<string> $packages
     */
    private function requireImportmapPackages(string $projectDir, array $packages, SymfonyStyle $io): void
    {
        $importmap = Path::join($projectDir, 'importmap.php');
        if (!$this->filesystem->exists($importmap)) {
            return;
        }
        $content = (string) file_get_contents($importmap);
        $missing = array_values(array_filter(
            $packages,
            static fn (string $package) => !str_contains($content, "'".$package."'"),
        ));
        if ([] === $missing) {
            return;
        }
        $process = new Process([\PHP_BINARY, 'bin/console', 'importmap:require', ...$missing], $projectDir, null, null, 300);
        $process->run();
        $io->writeln($process->isSuccessful()
            ? sprintf('<info>importmap : %s ajouté(s)</info>', implode(', ', $missing))
            : sprintf('<comment>importmap:require %s a échoué — lancez-le manuellement.</comment>', implode(' ', $missing)));
    }

    /**
     * @param list<string> $excluded
     */
    private function bridgeLoadExpression(array $excluded): string
    {
        if ([] === $excluded) {
            return '.load(bridgeControllers)';
        }
        $checks = array_map(
            static fn (string $identifier): string => 'controller.identifier !== "'.$identifier.'"',
            $excluded,
        );

        return '.load(bridgeControllers.filter((controller) => '.implode(' && ', $checks).'))';
    }

    /**
     * @param list<string> $excluded
     */
    private function syncBridgeControllerLoad(string $content, array $excluded): string
    {
        $expression = $this->bridgeLoadExpression($excluded);

        return preg_replace(
            '/\.load\(bridgeControllers(?:\.filter\(\(controller\) => [^;]+\))?\)/',
            $expression,
            $content,
            1,
        ) ?? $content;
    }

    private function copyKotlin(string $template, string $filename, string $androidRoot, string $package): void
    {
        $destination = $this->kotlinFile($androidRoot, $package, $filename);
        if ($this->filesystem->exists($destination)) {
            return;
        }
        $content = str_replace('%NATIVE_APPLICATION_ID%', $package, (string) file_get_contents(Path::join($this->bundleRoot, $template)));
        $this->filesystem->mkdir(\dirname($destination));
        file_put_contents($destination, $content);
    }

    private function copyFile(string $template, string $destination): void
    {
        if ($this->filesystem->exists($destination)) {
            return;
        }
        $this->filesystem->mkdir(\dirname($destination));
        $this->filesystem->copy(Path::join($this->bundleRoot, $template), $destination);
    }

    private function kotlinFile(string $androidRoot, string $package, string $filename): string
    {
        return Path::join($androidRoot, 'app', 'src', 'main', 'java', str_replace('.', '/', $package), $filename);
    }

    private function androidPackage(string $androidRoot): ?string
    {
        $java = Path::join($androidRoot, 'app', 'src', 'main', 'java');
        if (!is_dir($java)) {
            return null;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($java, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ('MainActivity.kt' !== $file->getFilename()) {
                continue;
            }
            if (preg_match('/^package\s+([\w.]+)/m', (string) file_get_contents($file->getPathname()), $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function patch(string $path, callable $transform): void
    {
        if (!$this->filesystem->exists($path)) {
            return;
        }
        $content = (string) file_get_contents($path);
        $updated = $transform($content);
        if (\is_string($updated) && $updated !== $content) {
            file_put_contents($path, $updated);
        }
    }

    private function patchPbx(string $iosRoot, callable $transform, SymfonyStyle $io): void
    {
        $path = Path::join($iosRoot, 'NativeApp.xcodeproj', 'project.pbxproj');
        if (!$this->filesystem->exists($path)) {
            $io->warning('project.pbxproj introuvable, le fichier Swift n\'a pas été ajouté au projet Xcode.');

            return;
        }
        $this->patch($path, $transform);
    }

    private function insertAfter(string $content, string $anchor, string $insertion, string $sentinel): string
    {
        if (str_contains($content, $sentinel)) {
            return $content;
        }
        $position = strpos($content, $anchor);
        if (false === $position) {
            return $content;
        }

        return substr_replace($content, $anchor.$insertion, $position, \strlen($anchor));
    }

    private function insertBeforeLast(string $content, string $anchor, string $insertion): string
    {
        $position = strrpos($content, $anchor);
        if (false === $position) {
            return $content;
        }

        return substr_replace($content, $insertion.$anchor, $position, \strlen($anchor));
    }
}
