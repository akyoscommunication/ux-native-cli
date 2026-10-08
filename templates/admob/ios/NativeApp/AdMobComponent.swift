import GoogleMobileAds
import HotwireNative
import UIKit

/// Native side of the `bridge--admob` Stimulus controller: Google AdMob ads asked for by a web page.
///
///     "rewarded"      {adUnitId}            full screen -> {status: "earned", reward: {type, amount}} | "dismissed" | "failed"
///     "interstitial"  {adUnitId}            full screen -> {status: "dismissed"} | "failed"
///     "banner"        {adUnitId, position}  anchored "top" | "bottom" -> {status: "loaded", height} | "failed"
///     "native"        {adUnitId, position}  small native ad card, anchored the same way -> same replies
///     "hide"                                removes the anchored ad (banner or native)
///
/// A failure carries `{error}`. Needs `GADApplicationIdentifier` in Info.plist, or the app stops at launch.
final class AdMobComponent: BridgeComponent {
    override nonisolated class var name: String { "admob" }

    private static var started = false
    private static let nativeCardHeight: CGFloat = 64

    private var fullScreenAd: FullScreenPresentingAd?
    private var fullScreenEvent = "rewarded"
    private var earnedReward: AdReward?
    private var adLoader: AdLoader?
    private var anchored: UIView?
    private var anchoredPosition = "bottom"
    private lazy var listener: AdMobListener = {
        let listener = AdMobListener()
        listener.component = self
        return listener
    }()

    private var screen: UIViewController? {
        delegate?.destination as? UIViewController
    }

    override func onReceive(message: Message) {
        let data: Payload? = message.data()
        let adUnitId = data?.adUnitId ?? ""
        let position = data?.position ?? "bottom"

        if !Self.started {
            Self.started = true
            MobileAds.shared.start(completionHandler: nil)
        }

        switch message.event {
        case "rewarded": showRewarded(adUnitId)
        case "interstitial": showInterstitial(adUnitId)
        case "banner": showBanner(adUnitId, position: position)
        case "native": showNative(adUnitId, position: position)
        case "hide": hideAnchored()
        default: break
        }
    }

    private func showRewarded(_ adUnitId: String) {
        RewardedAd.load(with: adUnitId, request: Request()) { [weak self] ad, error in
            DispatchQueue.main.async {
                guard let self else { return }
                guard let ad, let screen = self.screen else {
                    self.fail("rewarded", error?.localizedDescription ?? "No screen to show the ad on.")
                    return
                }

                self.fullScreenAd = ad
                self.fullScreenEvent = "rewarded"
                self.earnedReward = nil
                ad.fullScreenContentDelegate = self.listener
                ad.present(from: screen) { [weak self] in
                    self?.earnedReward = ad.adReward
                }
            }
        }
    }

    private func showInterstitial(_ adUnitId: String) {
        InterstitialAd.load(with: adUnitId, request: Request()) { [weak self] ad, error in
            DispatchQueue.main.async {
                guard let self else { return }
                guard let ad, let screen = self.screen else {
                    self.fail("interstitial", error?.localizedDescription ?? "No screen to show the ad on.")
                    return
                }

                self.fullScreenAd = ad
                self.fullScreenEvent = "interstitial"
                self.earnedReward = nil
                ad.fullScreenContentDelegate = self.listener
                ad.present(from: screen)
            }
        }
    }

    private func showBanner(_ adUnitId: String, position: String) {
        guard let screen else { return fail("banner", "No screen to show the ad on.") }

        let banner = BannerView(adSize: largeAnchoredAdaptiveBanner(width: screen.view.bounds.width))
        banner.adUnitID = adUnitId
        banner.rootViewController = screen
        banner.delegate = listener
        anchor(banner, position: position, height: nil)
        banner.load(Request())
    }

    private func showNative(_ adUnitId: String, position: String) {
        guard let screen else { return fail("native", "No screen to show the ad on.") }

        anchoredPosition = position
        let loader = AdLoader(adUnitID: adUnitId, rootViewController: screen, adTypes: [.native], options: nil)
        loader.delegate = listener
        adLoader = loader
        loader.load(Request())
    }

    /// Small native ad: icon, "Annonce" label, headline, body, call to action. The SDK adds the AdChoices mark.
    private func nativeAdCard(for ad: NativeAd) -> NativeAdView {
        let icon = UIImageView(image: ad.icon?.image)
        icon.contentMode = .scaleAspectFit

        let label = UILabel()
        label.text = "Annonce"
        label.font = .systemFont(ofSize: 10)
        label.textColor = .secondaryLabel

        let headline = UILabel()
        headline.text = ad.headline
        headline.font = .boldSystemFont(ofSize: 14)

        let body = UILabel()
        body.text = ad.body
        body.font = .systemFont(ofSize: 12)
        body.textColor = .secondaryLabel

        let callToAction = UIButton(type: .system)
        callToAction.setTitle(ad.callToAction, for: .normal)
        callToAction.titleLabel?.font = .boldSystemFont(ofSize: 13)
        // The SDK handles the tap on the whole ad view.
        callToAction.isUserInteractionEnabled = false
        callToAction.setContentHuggingPriority(.required, for: .horizontal)
        callToAction.setContentCompressionResistancePriority(.required, for: .horizontal)

        let texts = UIStackView(arrangedSubviews: [label, headline, body])
        texts.axis = .vertical

        let row = UIStackView(arrangedSubviews: [icon, texts, callToAction])
        row.axis = .horizontal
        row.alignment = .center
        row.spacing = 12
        row.isLayoutMarginsRelativeArrangement = true
        row.layoutMargins = UIEdgeInsets(top: 8, left: 12, bottom: 8, right: 12)
        row.translatesAutoresizingMaskIntoConstraints = false

        let card = NativeAdView()
        card.backgroundColor = .white
        card.addSubview(row)
        NSLayoutConstraint.activate([
            icon.widthAnchor.constraint(equalToConstant: 44),
            icon.heightAnchor.constraint(equalToConstant: 44),
            row.topAnchor.constraint(equalTo: card.topAnchor),
            row.bottomAnchor.constraint(equalTo: card.bottomAnchor),
            row.leadingAnchor.constraint(equalTo: card.leadingAnchor),
            row.trailingAnchor.constraint(equalTo: card.trailingAnchor),
        ])

        card.iconView = icon
        card.headlineView = headline
        card.bodyView = body
        card.callToActionView = callToAction
        card.nativeAd = ad

        return card
    }

    /// Over the web screen, against the top or bottom edge, clear of the notch and of the home indicator.
    private func anchor(_ view: UIView, position: String, height: CGFloat?) {
        hideAnchored()
        guard let container = screen?.view else { return }

        view.translatesAutoresizingMaskIntoConstraints = false
        container.addSubview(view)
        var constraints = [
            view.leadingAnchor.constraint(equalTo: container.leadingAnchor),
            view.trailingAnchor.constraint(equalTo: container.trailingAnchor),
            position == "top"
                ? view.topAnchor.constraint(equalTo: container.safeAreaLayoutGuide.topAnchor)
                : view.bottomAnchor.constraint(equalTo: container.safeAreaLayoutGuide.bottomAnchor),
        ]
        if let height {
            constraints.append(view.heightAnchor.constraint(equalToConstant: height))
        }
        NSLayoutConstraint.activate(constraints)
        anchored = view
    }

    private func hideAnchored() {
        anchored?.removeFromSuperview()
        anchored = nil
        adLoader = nil
    }

    fileprivate func fail(_ event: String, _ error: String) {
        print("[admob] \(event) ad failed: \(error)")
        reply(to: event, with: Reply(status: "failed", error: error))
    }
}

/// The SDK delegates must be Objective-C objects, which a bridge component is not: this one hands every
/// callback over to the component.
private final class AdMobListener: NSObject, FullScreenContentDelegate, BannerViewDelegate, NativeAdLoaderDelegate {
    weak var component: AdMobComponent?

    func adDidDismissFullScreenContent(_ ad: FullScreenPresentingAd) {
        component?.fullScreenAdDismissed()
    }

    func ad(_ ad: FullScreenPresentingAd, didFailToPresentFullScreenContentWithError error: Error) {
        component?.fullScreenAdFailed(error)
    }

    func bannerViewDidReceiveAd(_ bannerView: BannerView) {
        component?.bannerLoaded(bannerView)
    }

    func bannerView(_ bannerView: BannerView, didFailToReceiveAdWithError error: Error) {
        component?.anchoredAdFailed("banner", error)
    }

    func adLoader(_ adLoader: AdLoader, didReceive nativeAd: NativeAd) {
        component?.nativeAdLoaded(nativeAd)
    }

    func adLoader(_ adLoader: AdLoader, didFailToReceiveAdWithError error: Error) {
        component?.anchoredAdFailed("native", error)
    }
}

extension AdMobComponent {
    /// Answer once the ad is closed: the page is visible again to show what was earned.
    fileprivate func fullScreenAdDismissed() {
        fullScreenAd = nil
        if let reward = earnedReward {
            reply(to: fullScreenEvent, with: Reply(status: "earned", reward: Reward(type: reward.type, amount: reward.amount.intValue)))
        } else {
            reply(to: fullScreenEvent, with: Reply(status: "dismissed"))
        }
    }

    fileprivate func fullScreenAdFailed(_ error: Error) {
        fullScreenAd = nil
        fail(fullScreenEvent, error.localizedDescription)
    }

    fileprivate func bannerLoaded(_ banner: BannerView) {
        reply(to: "banner", with: Reply(status: "loaded", height: Int(banner.adSize.size.height)))
    }

    fileprivate func nativeAdLoaded(_ ad: NativeAd) {
        anchor(nativeAdCard(for: ad), position: anchoredPosition, height: Self.nativeCardHeight)
        reply(to: "native", with: Reply(status: "loaded", height: Int(Self.nativeCardHeight)))
    }

    fileprivate func anchoredAdFailed(_ event: String, _ error: Error) {
        hideAnchored()
        fail(event, error.localizedDescription)
    }
}

private extension AdMobComponent {
    struct Payload: Decodable {
        let adUnitId: String?
        let position: String?
    }

    struct Reward: Encodable {
        let type: String
        let amount: Int
    }

    struct Reply: Encodable {
        let status: String
        var reward: Reward?
        var height: Int?
        var error: String?
    }
}
