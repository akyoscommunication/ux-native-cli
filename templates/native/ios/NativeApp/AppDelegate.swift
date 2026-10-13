import BridgeComponents
import HotwireNative
import UIKit
import WebKit%NATIVE_NOTIF_IOS_IMPORT%

@main
class AppDelegate: UIResponder, UIApplicationDelegate {
    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
    ) -> Bool {
        #if DEBUG
        // A debug install keeps the previous HTML and JavaScript otherwise.
        // Caches only: cookies (the session), local storage and service workers stay.
        URLCache.shared.removeAllCachedResponses()
        WKWebsiteDataStore.default().removeData(ofTypes: [WKWebsiteDataTypeDiskCache, WKWebsiteDataTypeMemoryCache], modifiedSince: Date(timeIntervalSince1970: 0)) {}
        #endif
        Hotwire.config.defaultViewController = { url in NativeWebViewController(url: url) }
        Hotwire.registerBridgeComponents(Bridgework.coreComponents%NATIVE_NOTIF_IOS_COMPONENTS%%NATIVE_BARCODE_IOS_COMPONENTS%%NATIVE_ADMOB_IOS_COMPONENTS%)%NATIVE_NOTIF_IOS_SETUP%

        let localPathConfigURL = Bundle.main.url(forResource: "path-configuration", withExtension: "json")!
        let remotePathConfigURL = URL(string: /*###url###*/"http://127.0.0.1:8000"/*###url###*/ + "/config/ios_v1.json")!
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

/// The "top_bar" path property (server JSON, per page) wins over native.top_bar.
final class NativeWebViewController: HotwireWebViewController {
    override func viewWillAppear(_ animated: Bool) {
        super.viewWillAppear(animated)
        let visible = Hotwire.config.pathConfiguration.properties(for: initialVisitableURL)["top_bar"] as? Bool ?? /*###top_bar###*/true/*###top_bar###*/
        navigationController?.setNavigationBarHidden(!visible, animated: animated)
    }
}
