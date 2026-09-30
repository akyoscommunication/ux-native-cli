import HotwireNative
import UIKit
import UserNotifications

extension Notification.Name {
    /// Posted by `AppDelegate` with `userInfo["token"]` (hex APNs device token) or `userInfo["error"]` (registration failure).
    static let nativeDidReceiveNotificationToken = Notification.Name("NativeDidReceiveNotificationToken")
    /// Posted by `AppDelegate` when a tapped notification carries a "url"; `SceneDelegate` routes to it.
    static let nativeOpenNotificationURL = Notification.Name("NativeOpenNotificationURL")
}

/// Holds the tapped notification's "url" until a navigator can route it (the tap may arrive before the scene exists).
@MainActor
enum NotificationRouting {
    static var pendingURL: String?
}

/// Native side of the MIT `bridge--notification-token` Stimulus controller from `@joemasilotti/bridge-components`.
final class NotificationTokenComponent: BridgeComponent {
    override nonisolated class var name: String { "notification-token" }

    private var observer: NSObjectProtocol?
    private var waitingForAPNs = false

    deinit {
        observer.map(NotificationCenter.default.removeObserver)
    }

    override func onReceive(message: Message) {
        guard message.event == "get" else { return }

        if observer == nil {
            observer = NotificationCenter.default.addObserver(
                forName: .nativeDidReceiveNotificationToken,
                object: nil,
                queue: .main
            ) { [weak self] notification in
                let token = notification.userInfo?["token"] as? String
                let error = notification.userInfo?["error"] as? String
                MainActor.assumeIsolated {
                    self?.waitingForAPNs = false
                    if let token {
                        self?.reply(to: "get", with: TokenData(token: token))
                    } else if let error {
                        self?.showError(error)
                    }
                }
            }
        }

        // iOS calls neither didRegister nor didFail when APNs is unreachable (simulator, unsigned build): surface it.
        waitingForAPNs = true
        DispatchQueue.main.asyncAfter(deadline: .now() + 10) { [weak self] in
            MainActor.assumeIsolated {
                guard let self, self.waitingForAPNs else { return }
                self.waitingForAPNs = false
                self.showError("APNs n’a renvoyé aucun token après 10 s. Le simulateur et les builds sans Team Apple Developer ne reçoivent pas de token : testez sur un iPhone signé avec votre Team.")
            }
        }

        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound]) { _, error in
            if let error {
                print("[notification-token] Authorization failed: \(error.localizedDescription)")
            }
            DispatchQueue.main.async {
                UIApplication.shared.registerForRemoteNotifications()
            }
        }
    }

    private func showError(_ message: String) {
        let alert = UIAlertController(title: "Notifications indisponibles", message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: "OK", style: .default))
        (delegate?.destination as? UIViewController)?.present(alert, animated: true)
    }
}

private extension NotificationTokenComponent {
    struct TokenData: Encodable {
        let token: String
    }
}
