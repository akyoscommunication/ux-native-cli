<?php

namespace Akyos\UxNativeCliBundle\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

class InitScaffolder
{
    private const TEMPLATE_ANDROID = 'templates/native/android';

    private const TEMPLATE_IOS = 'templates/native/ios';

    private const TEMPLATE_NOTIFICATION = 'templates/notification';

    private const TEMPLATE_BARCODE = 'templates/barcode-scanner';

    private const TEMPLATE_ADMOB = 'templates/admob';

    private const DEFAULT_JAVA_PACKAGE_PATH = 'com/example/nativeapp';

    public function __construct(
        private Filesystem $filesystem,
        private KernelInterface $kernel,
        private array $config,
        private string $bundleRoot,
        private OfflineScaffolder $offlineScaffolder,
    ) {
    }

    public function scaffold(array $overrides, bool $force, bool $offline, SymfonyStyle $io, bool $notification = false, bool $barcodeScanner = false, bool $admob = false): int
    {
        $config = array_merge($this->config, $overrides);
        $projectDir = $this->kernel->getProjectDir();
        $androidTarget = $config['android_path'] ?? Path::join($projectDir, 'native', 'android');
        $iosTarget = $config['ios_path'] ?? Path::join($projectDir, 'native', 'ios');

        $androidSource = Path::join($this->bundleRoot, self::TEMPLATE_ANDROID);
        $iosSource = Path::join($this->bundleRoot, self::TEMPLATE_IOS);

        if (!$this->filesystem->exists($androidSource) || !$this->filesystem->exists($iosSource)) {
            $io->error('Bundle templates are missing.');

            return 1;
        }

        foreach ([['path' => $androidTarget, 'label' => 'Android'], ['path' => $iosTarget, 'label' => 'iOS']] as $target) {
            if ($this->filesystem->exists($target['path']) && !is_dir($target['path'])) {
                $io->error(sprintf('%s output path exists and is not a directory: %s', $target['label'], $target['path']));

                return 1;
            }
            if ($this->filesystem->exists($target['path'])) {
                $iterator = new \FilesystemIterator($target['path']);
                if ($iterator->valid() && !$force) {
                    $io->error(sprintf('%s output directory is not empty: %s (use --force)', $target['label'], $target['path']));

                    return 1;
                }
            }
        }

        if ($force) {
            $this->filesystem->remove([$androidTarget, $iosTarget]);
        }

        $tokens = $this->buildTokens($config, $notification, $barcodeScanner, $admob);
        $this->mirrorAndSubstitute($androidSource, $androidTarget, $tokens);
        $this->mirrorAndSubstitute($iosSource, $iosTarget, $tokens);
        if ($notification) {
            $this->mirrorAndSubstitute(Path::join($this->bundleRoot, self::TEMPLATE_NOTIFICATION, 'android'), $androidTarget, $tokens);
            $this->mirrorAndSubstitute(Path::join($this->bundleRoot, self::TEMPLATE_NOTIFICATION, 'ios'), $iosTarget, $tokens);
        }
        if ($barcodeScanner) {
            $this->mirrorAndSubstitute(Path::join($this->bundleRoot, self::TEMPLATE_BARCODE, 'android'), $androidTarget, $tokens);
            $this->mirrorAndSubstitute(Path::join($this->bundleRoot, self::TEMPLATE_BARCODE, 'ios'), $iosTarget, $tokens);
        }
        if ($admob) {
            $this->mirrorAndSubstitute(Path::join($this->bundleRoot, self::TEMPLATE_ADMOB, 'android'), $androidTarget, $tokens);
            $this->mirrorAndSubstitute(Path::join($this->bundleRoot, self::TEMPLATE_ADMOB, 'ios'), $iosTarget, $tokens);
        }
        $this->relocateAndroidJavaPackage($androidTarget, $config['application_id']);

        $gradlew = Path::join($androidTarget, 'gradlew');
        if ($this->filesystem->exists($gradlew)) {
            $this->filesystem->chmod($gradlew, 0755);
        }

        $this->resolveIosPackageDependencies($iosTarget, $config, $io);

        $io->success('Native shells scaffolded.');
        $io->note('Open the Android project in Android Studio and the iOS project in Xcode. See https://native.hotwired.dev/ for Hotwire Native setup.');

        if ($notification) {
            $this->installBridgeComponentsJs($projectDir, $io, $barcodeScanner);
            $io->note([
                'Bridge notification-token ajouté : utilisez data-controller="bridge--notification-token" et data-action="bridge--notification-token#get" (voir le README du bundle).',
                'Android : déposez google-services.json (projet Firebase) dans '.Path::join($androidTarget, 'app').' — sans lui, le build passe mais aucun token FCM.',
                'iOS : choisissez une Team Apple Developer dans Xcode (Signing & Capabilities) pour activer Push Notifications.',
            ]);
        }

        if ($barcodeScanner || $admob) {
            $this->requireImportmapPackages($projectDir, ['@hotwired/hotwire-native-bridge'], $io);
        }
        if ($barcodeScanner) {
            $this->installStimulusController($projectDir, Path::join($this->bundleRoot, self::TEMPLATE_BARCODE, 'web', 'barcode_scanner_controller.js'), 'barcode_scanner_controller.js', $io);
            $io->note('Bridge barcode-scanner ajouté : data-controller="bridge--barcode-scanner" et data-action="bridge--barcode-scanner#scan" (voir le README du bundle).');
        }
        if ($admob) {
            $this->installStimulusController($projectDir, Path::join($this->bundleRoot, self::TEMPLATE_ADMOB, 'web', 'admob_controller.js'), 'admob_controller.js', $io);
            $io->note([
                'Bridge admob ajouté : data-controller="bridge--admob" (voir le README du bundle).',
                'Identifiants d\'app AdMob écrits dans le manifeste : valeurs de test Google par défaut. Remplacez native.admob.android_app_id et native.admob.ios_app_id avant la production, puis relancez native:init --force.',
            ]);
        }

        if ($offline) {
            return $this->offlineScaffolder->scaffold($config, $force, $iosTarget, $androidTarget, $io);
        }

        return 0;
    }

    private function resolveIosPackageDependencies(string $iosTarget, array $config, SymfonyStyle $io): void
    {
        if ('Darwin' !== \PHP_OS_FAMILY) {
            return;
        }
        $project = $config['ios_project'] ?? 'NativeApp.xcodeproj';
        $scheme = $config['ios_scheme'] ?? 'NativeApp';
        $projectPath = Path::join($iosTarget, $project);
        if (!$this->filesystem->exists($projectPath)) {
            return;
        }
        $process = new Process(
            ['xcodebuild', '-project', $project, '-scheme', $scheme, '-resolvePackageDependencies'],
            $iosTarget,
            null,
            null,
            600
        );
        $process->run();
        if (!$process->isSuccessful()) {
            $io->warning('xcodebuild -resolvePackageDependencies a échoué (les dépendances SPM peuvent être incomplètes). Ouvrez Xcode → File → Packages → Reset Package Caches si l’erreur « Missing package product » persiste.');
        }
    }

    private function buildTokens(array $config, bool $notification, bool $barcodeScanner, bool $admob): array
    {
        $notificationTokens = self::notificationSnippets();
        $barcodeTokens = self::barcodeSnippets();
        $admobTokens = self::admobSnippets();

        $metadata = '';
        $plist = '';
        if ($barcodeScanner) {
            $metadata .= "\n        <meta-data android:name=\"com.google.mlkit.vision.DEPENDENCIES\" android:value=\"barcode_ui\" />";
            $description = htmlspecialchars((string) ($config['camera_usage_description'] ?? 'Scanner un QR code.'), \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
            $plist .= "\n\t<key>NSCameraUsageDescription</key>\n\t<string>".$description.'</string>';
        }
        if ($admob) {
            $metadata .= "\n        <meta-data android:name=\"com.google.android.gms.ads.APPLICATION_ID\" android:value=\"".$config['admob']['android_app_id'].'" />';
            $plist .= "\n\t<key>GADApplicationIdentifier</key>\n\t<string>".$config['admob']['ios_app_id'].'</string>';
        }

        return [
            '%NATIVE_APP_NAME%' => $config['app_name'],
            '%NATIVE_BASE_URL%' => $config['url'],
            '%NATIVE_APPLICATION_ID%' => $config['application_id'],
            '%NATIVE_BUNDLE_ID%' => $config['bundle_id'],
            '%NATIVE_IOS_TEAM%' => null !== ($config['ios_development_team'] ?? null) ? "\n\t\t\t\tDEVELOPMENT_TEAM = ".$config['ios_development_team'].';'."\n\t\t\t\t\"CODE_SIGN_IDENTITY[sdk=iphoneos*]\" = \"Apple Development\";" : '',
            '%NATIVE_ANDROID_FACTORY_IMPORT%' => ($notification || $barcodeScanner || $admob) ? "\nimport dev.hotwire.core.bridge.BridgeComponentFactory" : '',
            '%NATIVE_ANDROID_APP_METADATA%' => $metadata,
            '%NATIVE_IOS_PLIST_EXTRA%' => $plist,
        ]
            + ($notification ? $notificationTokens : array_fill_keys(array_keys($notificationTokens), ''))
            + ($barcodeScanner ? $barcodeTokens : array_fill_keys(array_keys($barcodeTokens), ''))
            + ($admob ? $admobTokens : array_fill_keys(array_keys($admobTokens), ''));
    }

    /**
     * Each snippet is appended to the end of an existing template line, so an empty substitution leaves no blank line.
     *
     * @return array<string, string>
     */
    private static function notificationSnippets(): array
    {
        return [
            '%NATIVE_NOTIF_IOS_IMPORT%' => "\nimport UserNotifications",
            '%NATIVE_NOTIF_IOS_COMPONENTS%' => ' + [NotificationTokenComponent.self]',
            '%NATIVE_NOTIF_IOS_SETUP%' => "\n        UNUserNotificationCenter.current().delegate = self",
            '%NATIVE_NOTIF_IOS_DELEGATE%' => <<<'SWIFT'


extension AppDelegate: UNUserNotificationCenterDelegate {
    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        let token = deviceToken.map { String(format: "%02x", $0) }.joined()
        NotificationCenter.default.post(name: .nativeDidReceiveNotificationToken, object: nil, userInfo: ["token": token])
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        print("[notification-token] APNs registration failed: \(error.localizedDescription)")
        NotificationCenter.default.post(name: .nativeDidReceiveNotificationToken, object: nil, userInfo: ["error": error.localizedDescription])
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        completionHandler([.banner, .list, .sound, .badge])
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        if let url = response.notification.request.content.userInfo["url"] as? String {
            NotificationRouting.pendingURL = url
            NotificationCenter.default.post(name: .nativeOpenNotificationURL, object: nil)
        }
        completionHandler()
    }
}
SWIFT,
            '%NATIVE_NOTIF_IOS_SCENE_PROPERTY%' => "\n    private var notificationURLObserver: NSObjectProtocol?",
            '%NATIVE_NOTIF_IOS_SCENE_SETUP%' => <<<'SWIFT'

        notificationURLObserver = NotificationCenter.default.addObserver(forName: .nativeOpenNotificationURL, object: nil, queue: .main) { [weak self] _ in
            MainActor.assumeIsolated { self?.openPendingNotificationURL() }
        }
        openPendingNotificationURL()
SWIFT,
            '%NATIVE_NOTIF_IOS_SCENE_METHODS%' => <<<'SWIFT'


    /// Relative paths resolve against the app URL; the page opens in the visible tab (or the single navigator).
    private func openPendingNotificationURL() {
        guard let tabs = currentTabs, let path = NotificationRouting.pendingURL,
              let url = URL(string: path, relativeTo: rootURL)?.absoluteURL else { return }
        NotificationRouting.pendingURL = nil
        (tabs.isEmpty ? navigator : tabBarController.activeNavigator).route(url)
    }
SWIFT,
            '%NATIVE_NOTIF_PBX_BUILDFILE%' => "\n\t\tB20100000000000000000008 /* NotificationTokenComponent.swift in Sources */ = {isa = PBXBuildFile; fileRef = B20100000000000000000017 /* NotificationTokenComponent.swift */; };",
            '%NATIVE_NOTIF_PBX_FILEREF%' => "\n\t\tB20100000000000000000017 /* NotificationTokenComponent.swift */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = NotificationTokenComponent.swift; sourceTree = \"<group>\"; };"
                ."\n\t\tB20100000000000000000018 /* NativeApp.entitlements */ = {isa = PBXFileReference; lastKnownFileType = text.plist.entitlements; path = NativeApp.entitlements; sourceTree = \"<group>\"; };",
            '%NATIVE_NOTIF_PBX_GROUP%' => "\n\t\t\t\tB20100000000000000000017 /* NotificationTokenComponent.swift */,\n\t\t\t\tB20100000000000000000018 /* NativeApp.entitlements */,",
            '%NATIVE_NOTIF_PBX_SOURCES%' => "\n\t\t\t\tB20100000000000000000008 /* NotificationTokenComponent.swift in Sources */,",
            '%NATIVE_NOTIF_PBX_ENTITLEMENTS%' => "\n\t\t\t\tCODE_SIGN_ENTITLEMENTS = NativeApp/NativeApp.entitlements;",
            '%NATIVE_NOTIF_ANDROID_COMPONENTS%' => ', BridgeComponentFactory("notification-token", ::NotificationTokenComponent)',
            '%NATIVE_NOTIF_ANDROID_PERMISSION%' => "\n    <uses-permission android:name=\"android.permission.POST_NOTIFICATIONS\" />",
            // singleTask: tapping a notification while the app runs reaches onNewIntent instead of stacking a second MainActivity.
            '%NATIVE_NOTIF_ANDROID_LAUNCH_MODE%' => "\n            android:launchMode=\"singleTask\"",
            '%NATIVE_NOTIF_ANDROID_SERVICE%' => <<<'XML'

        <service
            android:name=".NativeMessagingService"
            android:exported="false">
            <intent-filter>
                <action android:name="com.google.firebase.MESSAGING_EVENT" />
            </intent-filter>
        </service>
XML,
            '%NATIVE_NOTIF_ANDROID_INTENT_IMPORT%' => "\nimport android.content.Intent",
            '%NATIVE_NOTIF_ANDROID_PENDING_URL%' => "\n    private var pendingUrl: String? = null",
            '%NATIVE_NOTIF_ANDROID_READ_INTENT%' => "\n        pendingUrl = intent.getStringExtra(\"url\")",
            '%NATIVE_NOTIF_ANDROID_CONSUME_URL%' => "\n                        pendingUrl?.let { pendingUrl = null; routeTo(it) }",
            '%NATIVE_NOTIF_ANDROID_ON_NEW_INTENT%' => <<<'KOTLIN'


    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        intent.getStringExtra("url")?.let { routeTo(it) }
    }
KOTLIN,
            '%NATIVE_NOTIF_GRADLE_ROOT_PLUGIN%' => "\n    id(\"com.google.gms.google-services\") version \"4.4.2\" apply false",
            '%NATIVE_NOTIF_GRADLE_APP_PLUGIN%' => "\n    id(\"com.google.gms.google-services\")",
            '%NATIVE_NOTIF_GRADLE_APP_CONFIG%' => <<<'KTS'


// Build still succeeds without google-services.json; FCM just returns no token until it is added.
googleServices {
    missingGoogleServicesStrategy = com.google.gms.googleservices.GoogleServicesPlugin.MissingGoogleServicesStrategy.WARN
}
KTS,
            '%NATIVE_NOTIF_GRADLE_APP_DEPS%' => "\n    implementation(platform(\"com.google.firebase:firebase-bom:33.10.0\"))\n    implementation(\"com.google.firebase:firebase-messaging\")",
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function barcodeSnippets(): array
    {
        return [
            '%NATIVE_BARCODE_IOS_COMPONENTS%' => ' + [BarcodeScannerComponent.self]',
            '%NATIVE_BARCODE_ANDROID_COMPONENTS%' => ', BridgeComponentFactory("barcode-scanner", ::BarcodeScannerComponent)',
            '%NATIVE_BARCODE_GRADLE_DEPS%' => "\n    implementation(\"com.google.android.gms:play-services-code-scanner:16.1.0\")",
            '%NATIVE_BARCODE_PBX_BUILDFILE%' => "\n\t\tB20100000000000000000009 /* BarcodeScannerComponent.swift in Sources */ = {isa = PBXBuildFile; fileRef = B20100000000000000000019 /* BarcodeScannerComponent.swift */; };",
            '%NATIVE_BARCODE_PBX_FILEREF%' => "\n\t\tB20100000000000000000019 /* BarcodeScannerComponent.swift */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = BarcodeScannerComponent.swift; sourceTree = \"<group>\"; };",
            '%NATIVE_BARCODE_PBX_GROUP%' => "\n\t\t\t\tB20100000000000000000019 /* BarcodeScannerComponent.swift */,",
            '%NATIVE_BARCODE_PBX_SOURCES%' => "\n\t\t\t\tB20100000000000000000009 /* BarcodeScannerComponent.swift in Sources */,",
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function admobSnippets(): array
    {
        return [
            '%NATIVE_ADMOB_IOS_COMPONENTS%' => ' + [AdMobComponent.self]',
            '%NATIVE_ADMOB_ANDROID_COMPONENTS%' => ', BridgeComponentFactory("admob", ::AdMobComponent)',
            '%NATIVE_ADMOB_GRADLE_DEPS%' => "\n    implementation(\"com.google.android.gms:play-services-ads:25.5.0\")",
            '%NATIVE_ADMOB_PBX_BUILDFILE%' => "\n\t\tB2010000000000000000000A /* AdMobComponent.swift in Sources */ = {isa = PBXBuildFile; fileRef = B2010000000000000000001A /* AdMobComponent.swift */; };"
                ."\n\t\tB2010000000000000000000B /* GoogleMobileAds in Frameworks */ = {isa = PBXBuildFile; productRef = B20100000000000000000024 /* GoogleMobileAds */; };",
            '%NATIVE_ADMOB_PBX_FILEREF%' => "\n\t\tB2010000000000000000001A /* AdMobComponent.swift */ = {isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = AdMobComponent.swift; sourceTree = \"<group>\"; };",
            '%NATIVE_ADMOB_PBX_FRAMEWORKS%' => "\n\t\t\t\tB2010000000000000000000B /* GoogleMobileAds in Frameworks */,",
            '%NATIVE_ADMOB_PBX_GROUP%' => "\n\t\t\t\tB2010000000000000000001A /* AdMobComponent.swift */,",
            '%NATIVE_ADMOB_PBX_PRODUCTS%' => "\n\t\t\t\tB20100000000000000000024 /* GoogleMobileAds */,",
            '%NATIVE_ADMOB_PBX_PACKAGES%' => "\n\t\t\t\tB20100000000000000000025 /* XCRemoteSwiftPackageReference \"swift-package-manager-google-mobile-ads\" */,",
            '%NATIVE_ADMOB_PBX_SOURCES%' => "\n\t\t\t\tB2010000000000000000000A /* AdMobComponent.swift in Sources */,",
            '%NATIVE_ADMOB_PBX_PACKAGE_REF%' => <<<'PBX'


		B20100000000000000000025 /* XCRemoteSwiftPackageReference "swift-package-manager-google-mobile-ads" */ = {
			isa = XCRemoteSwiftPackageReference;
			repositoryURL = "https://github.com/googleads/swift-package-manager-google-mobile-ads";
			requirement = {
				kind = upToNextMajorVersion;
				minimumVersion = 13.0.0;
			};
		};
PBX,
            '%NATIVE_ADMOB_PBX_PRODUCT_DEP%' => <<<'PBX'


		B20100000000000000000024 /* GoogleMobileAds */ = {
			isa = XCSwiftPackageProductDependency;
			package = B20100000000000000000025 /* XCRemoteSwiftPackageReference "swift-package-manager-google-mobile-ads" */;
			productName = GoogleMobileAds;
		};
PBX,
        ];
    }

    private function installBridgeComponentsJs(string $projectDir, SymfonyStyle $io, bool $excludeBarcodeScanner = false): void
    {
        $this->requireImportmapPackages($projectDir, ['@hotwired/hotwire-native-bridge', '@joemasilotti/bridge-components'], $io);

        $bootstrap = Path::join($projectDir, 'assets', 'stimulus_bootstrap.js');
        if (!$this->filesystem->exists($bootstrap)) {
            $io->warning('assets/stimulus_bootstrap.js introuvable — chargez les contrôleurs de @joemasilotti/bridge-components manuellement.');

            return;
        }
        $content = (string) file_get_contents($bootstrap);
        $load = $excludeBarcodeScanner
            ? '$2.load(bridgeControllers.filter((controller) => controller.identifier !== "bridge--barcode-scanner"));'
            : '$2.load(bridgeControllers);';
        if (str_contains($content, '@joemasilotti/bridge-components')) {
            $synced = $this->syncBarcodeScannerExclusion($content, $excludeBarcodeScanner);
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

    private function installStimulusController(string $projectDir, string $source, string $filename, SymfonyStyle $io): void
    {
        $controllers = Path::join($projectDir, 'assets', 'controllers');
        if (!$this->filesystem->exists($controllers)) {
            $io->warning(sprintf('assets/controllers introuvable — copiez %s manuellement.', $filename));

            return;
        }
        $destination = Path::join($controllers, 'bridge', $filename);
        $this->filesystem->mkdir(\dirname($destination));
        $this->filesystem->copy($source, $destination, true);
        $io->writeln(sprintf('<info>Contrôleur copié dans assets/controllers/bridge/%s</info>', $filename));
    }

    private function syncBarcodeScannerExclusion(string $content, bool $excludeBarcodeScanner): string
    {
        $filtered = '.load(bridgeControllers.filter((controller) => controller.identifier !== "bridge--barcode-scanner"))';
        $plain = '.load(bridgeControllers)';
        if ($excludeBarcodeScanner) {
            return str_contains($content, $filtered) ? $content : str_replace($plain, $filtered, $content);
        }

        return str_replace($filtered, $plain, $content);
    }

    private function mirrorAndSubstitute(string $source, string $destination, array $tokens): void
    {
        $this->filesystem->mkdir($destination);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $relative = substr($fileInfo->getPathname(), \strlen($source) + 1);
            $targetPath = Path::join($destination, $relative);
            if ($fileInfo->isDir()) {
                $this->filesystem->mkdir($targetPath);

                continue;
            }
            $this->filesystem->mkdir(\dirname($targetPath));
            if ($this->shouldSubstitute($fileInfo->getPathname())) {
                $content = file_get_contents($fileInfo->getPathname());
                $content = strtr($content, $tokens);
                file_put_contents($targetPath, $content);
            } else {
                $this->filesystem->copy($fileInfo->getPathname(), $targetPath, true);
            }
        }
    }

    private function shouldSubstitute(string $path): bool
    {
        $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        $allowed = ['kt', 'kts', 'xml', 'gradle', 'properties', 'md', 'swift', 'plist', 'pbxproj', 'pro', 'html', 'json', 'txt', 'java', 'yaml', 'yml', 'toml', 'xcconfig'];

        return \in_array($ext, $allowed, true);
    }

    private function relocateAndroidJavaPackage(string $androidRoot, string $applicationId): void
    {
        $newPath = str_replace('.', '/', $applicationId);
        if ($newPath === self::DEFAULT_JAVA_PACKAGE_PATH) {
            return;
        }
        $oldDir = Path::join($androidRoot, 'app', 'src', 'main', 'java', self::DEFAULT_JAVA_PACKAGE_PATH);
        $newDir = Path::join($androidRoot, 'app', 'src', 'main', 'java', $newPath);
        if (!$this->filesystem->exists($oldDir)) {
            return;
        }
        $this->filesystem->mkdir(\dirname($newDir));
        $this->filesystem->rename($oldDir, $newDir);
    }
}
