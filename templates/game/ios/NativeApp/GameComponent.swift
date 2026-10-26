import HotwireNative
import UIKit

/// Orientation allowed by the app: portrait everywhere, landscape only while a game page is shown.
/// Read by `AppDelegate.application(_:supportedInterfaceOrientationsFor:)`.
@MainActor
enum GameMode {
    static var orientations: UIInterfaceOrientationMask = .portrait
}

/// A screen that can go full screen for a game page. `NativeWebViewController` conforms to it;
/// a custom web view controller conforms by implementing `isGame` (see the README).
@MainActor
protocol GameScreen: AnyObject {
    var isGame: Bool { get set }
}

/// Native side of the `bridge--game` Stimulus controller. "enter": landscape, tab bar and status bar hidden,
/// home indicator faded, no pull-to-refresh and no edge swipe back. "leave", or the screen going away: everything
/// goes back as it was.
final class GameComponent: BridgeComponent {
    override nonisolated class var name: String { "game" }

    private var active = false

    override func onReceive(message: Message) {
        switch message.event {
        case "enter":
            active = true
            apply(true)
        case "leave":
            active = false
            apply(false)
        default:
            break
        }
    }

    override func onViewWillAppear() {
        if active { apply(true) }
    }

    override func onViewWillDisappear() {
        if active { apply(false) }
    }

    private func apply(_ playing: Bool) {
        guard let screen = delegate?.destination as? (UIViewController & GameScreen) else { return }
        screen.isGame = playing
        (screen as? HotwireWebViewController)?.visitableView.allowsPullToRefresh = !playing
        screen.tabBarController?.tabBar.isHidden = playing
        GameMode.orientations = playing ? .landscape : .portrait
        rotate(screen, to: playing ? .landscape : .portrait)
    }

    private func rotate(_ screen: UIViewController, to mask: UIInterfaceOrientationMask) {
        let root = screen.view.window?.rootViewController
        if #available(iOS 16.0, *) {
            root?.setNeedsUpdateOfSupportedInterfaceOrientations()
            screen.view.window?.windowScene?.requestGeometryUpdate(.iOS(interfaceOrientations: mask)) { error in
                print("[game] rotation refused: \(error.localizedDescription)")
            }
        } else {
            let orientation: UIInterfaceOrientation = mask == .portrait ? .portrait : .landscapeRight
            UIDevice.current.setValue(orientation.rawValue, forKey: "orientation")
            UIViewController.attemptRotationToDeviceOrientation()
        }
    }
}
