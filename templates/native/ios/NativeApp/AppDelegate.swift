import BridgeComponents
import HotwireNative
import UIKit%NATIVE_NOTIF_IOS_IMPORT%

@main
class AppDelegate: UIResponder, UIApplicationDelegate {
    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
    ) -> Bool {
        Hotwire.registerBridgeComponents(Bridgework.coreComponents%NATIVE_NOTIF_IOS_COMPONENTS%%NATIVE_BARCODE_IOS_COMPONENTS%%NATIVE_ADMOB_IOS_COMPONENTS%)%NATIVE_NOTIF_IOS_SETUP%

        let localPathConfigURL = Bundle.main.url(forResource: "path-configuration", withExtension: "json")!
        let remotePathConfigURL = URL(string: "%NATIVE_BASE_URL%/config/ios_v1.json")!
        // URLSession's heuristic HTTP cache would otherwise keep serving a stale JSON for days.
        let pathConfigurationSession = URLSessionConfiguration.default
        pathConfigurationSession.requestCachePolicy = .reloadIgnoringLocalCacheData
        Hotwire.config.pathConfiguration = PathConfiguration(options: .init(urlSessionConfiguration: pathConfigurationSession))
        Hotwire.loadPathConfiguration(from: [
            .file(localPathConfigURL),
            .server(remotePathConfigURL),
        ])
        return true
    }

    func application(
        _ application: UIApplication,
        configurationForConnecting connectingSceneSession: UISceneSession,
        options: UIScene.ConnectionOptions
    ) -> UISceneConfiguration {
        UISceneConfiguration(name: "Default Configuration", sessionRole: connectingSceneSession.role)
    }
}%NATIVE_NOTIF_IOS_DELEGATE%
