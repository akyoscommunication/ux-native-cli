import HotwireNative
import UIKit

class SceneDelegate: UIResponder, UIWindowSceneDelegate {
    var window: UIWindow?

    private let rootURL = URL(string: "%NATIVE_BASE_URL%")!

    private lazy var navigator = Navigator(configuration: .init(
        name: "main",
        startLocation: rootURL,
    ))

    func scene(
        _ scene: UIScene,
        willConnectTo session: UISceneSession,
        options connectionOptions: UIScene.ConnectionOptions
    ) {
        guard let windowScene = scene as? UIWindowScene else { return }
        let window = UIWindow(windowScene: windowScene)
        self.window = window
        window.rootViewController = navigator.rootViewController
        window.makeKeyAndVisible()
        navigator.start()
    }
}
