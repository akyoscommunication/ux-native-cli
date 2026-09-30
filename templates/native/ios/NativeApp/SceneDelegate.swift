import HotwireNative
import UIKit

class SceneDelegate: UIResponder, UIWindowSceneDelegate, PathConfigurationDelegate {
    var window: UIWindow?

    private let rootURL = URL(string: "%NATIVE_BASE_URL%")!
    private let tabBarController = HotwireTabBarController()
    private lazy var navigator = Navigator(configuration: .init(
        name: "main",
        startLocation: rootURL
    ))
    private var currentTabs: [TabConfiguration]?%NATIVE_NOTIF_IOS_SCENE_PROPERTY%

    func scene(
        _ scene: UIScene,
        willConnectTo session: UISceneSession,
        options connectionOptions: UIScene.ConnectionOptions
    ) {
        guard let windowScene = scene as? UIWindowScene else { return }
        let window = UIWindow(windowScene: windowScene)
        self.window = window

        Hotwire.config.pathConfiguration.delegate = self
        applyTabs()
        window.makeKeyAndVisible()%NATIVE_NOTIF_IOS_SCENE_SETUP%
    }

    /// Called for the bundled file, the cached copy and the server response: tabs follow the server without a relaunch.
    nonisolated func pathConfigurationDidUpdate() {
        Task { @MainActor [weak self] in
            self?.applyTabs()
        }
    }

    private func applyTabs() {
        let configurations = TabConfigurationProvider.tabs()
        guard let window, configurations != currentTabs else { return }
        currentTabs = configurations

        if configurations.isEmpty {
            window.rootViewController = navigator.rootViewController
            navigator.start()
        } else {
            window.rootViewController = tabBarController
            tabBarController.load(buildHotwireTabs(from: configurations))
        }
    }

    private func buildHotwireTabs(from configurations: [TabConfiguration]) -> [HotwireTab] {
        return configurations.map { config in
            let url = URL(string: config.path, relativeTo: rootURL)?.absoluteURL ?? rootURL
            return HotwireTab(
                title: config.label,
                image: UIImage(systemName: config.icon) ?? UIImage(),
                url: url
            )
        }
    }%NATIVE_NOTIF_IOS_SCENE_METHODS%
}
