package %NATIVE_APPLICATION_ID%

import android.app.Activity
import android.graphics.Color
import android.graphics.Typeface
import android.util.Log
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.Button
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import com.google.android.gms.ads.AdError
import com.google.android.gms.ads.AdListener
import com.google.android.gms.ads.AdLoader
import com.google.android.gms.ads.AdRequest
import com.google.android.gms.ads.AdSize
import com.google.android.gms.ads.AdView
import com.google.android.gms.ads.FullScreenContentCallback
import com.google.android.gms.ads.LoadAdError
import com.google.android.gms.ads.MobileAds
import com.google.android.gms.ads.interstitial.InterstitialAd
import com.google.android.gms.ads.interstitial.InterstitialAdLoadCallback
import com.google.android.gms.ads.nativead.NativeAd
import com.google.android.gms.ads.nativead.NativeAdView
import com.google.android.gms.ads.rewarded.RewardItem
import com.google.android.gms.ads.rewarded.RewardedAd
import com.google.android.gms.ads.rewarded.RewardedAdLoadCallback
import dev.hotwire.core.bridge.BridgeComponent
import dev.hotwire.core.bridge.BridgeDelegate
import dev.hotwire.core.bridge.Message
import dev.hotwire.navigation.destinations.HotwireDestination
import org.json.JSONObject
import kotlin.math.roundToInt

// Native side of the `bridge--admob` Stimulus controller: Google AdMob ads asked for by a web page.
//
//   "rewarded"      {adUnitId}            full screen -> {status: "earned", reward: {type, amount}} | "dismissed" | "failed"
//   "interstitial"  {adUnitId}            full screen -> {status: "dismissed"} | "failed"
//   "banner"        {adUnitId, position}  anchored "top" | "bottom" -> {status: "loaded", height} | "failed"
//   "native"        {adUnitId, position}  small native ad card, anchored the same way -> same replies
//   "hide"                                removes the anchored ad (banner or native)
//
// A failure carries {error}. Heights are in CSS pixels. Needs the AdMob app id in AndroidManifest.xml
// (com.google.android.gms.ads.APPLICATION_ID), or the app stops at launch.
class AdMobComponent(
    name: String,
    private val bridgeDelegate: BridgeDelegate<HotwireDestination>
) : BridgeComponent<HotwireDestination>(name, bridgeDelegate) {

    private var anchored: View? = null
    private var nativeAd: NativeAd? = null

    override fun onReceive(message: Message) {
        val data = JSONObject(message.jsonData)
        val adUnitId = data.optString("adUnitId")
        val position = data.optString("position", "bottom")

        when (message.event) {
            "rewarded" -> showRewarded(adUnitId)
            "interstitial" -> showInterstitial(adUnitId)
            "banner" -> showBanner(adUnitId, position)
            "native" -> showNative(adUnitId, position)
            "hide" -> hideAnchored()
            "connect" -> Unit
            else -> Log.w(TAG, "Unknown event for message: $message")
        }
    }

    override fun onStop() {
        // The screen that asked for the anchored ad is left: the ad goes with it.
        hideAnchored()
    }

    private fun showRewarded(adUnitId: String) {
        val activity = activity() ?: return fail("rewarded", "No screen to show the ad on.")

        RewardedAd.load(activity, adUnitId, AdRequest.Builder().build(), object : RewardedAdLoadCallback() {
            override fun onAdLoaded(ad: RewardedAd) {
                var reward: RewardItem? = null
                ad.fullScreenContentCallback = object : FullScreenContentCallback() {
                    // Answer once the ad is closed: the page is visible again to show what was earned.
                    override fun onAdDismissedFullScreenContent() {
                        val earned = reward
                        if (null == earned) {
                            reply("rewarded", JSONObject().put("status", "dismissed"))
                        } else {
                            reply("rewarded", JSONObject().put("status", "earned").put("reward", JSONObject().put("type", earned.type).put("amount", earned.amount)))
                        }
                    }

                    override fun onAdFailedToShowFullScreenContent(error: AdError) = fail("rewarded", error.message)
                }
                ad.show(activity) { reward = it }
            }

            override fun onAdFailedToLoad(error: LoadAdError) = fail("rewarded", error.message)
        })
    }

    private fun showInterstitial(adUnitId: String) {
        val activity = activity() ?: return fail("interstitial", "No screen to show the ad on.")

        InterstitialAd.load(activity, adUnitId, AdRequest.Builder().build(), object : InterstitialAdLoadCallback() {
            override fun onAdLoaded(ad: InterstitialAd) {
                ad.fullScreenContentCallback = object : FullScreenContentCallback() {
                    override fun onAdDismissedFullScreenContent() = reply("interstitial", JSONObject().put("status", "dismissed"))

                    override fun onAdFailedToShowFullScreenContent(error: AdError) = fail("interstitial", error.message)
                }
                ad.show(activity)
            }

            override fun onAdFailedToLoad(error: LoadAdError) = fail("interstitial", error.message)
        })
    }

    private fun showBanner(adUnitId: String, position: String) {
        val activity = activity() ?: return fail("banner", "No screen to show the ad on.")
        val metrics = activity.resources.displayMetrics
        val size = AdSize.getLargeAnchoredAdaptiveBannerAdSize(activity, (metrics.widthPixels / metrics.density).toInt())

        val banner = AdView(activity)
        banner.adUnitId = adUnitId
        banner.setAdSize(size)
        banner.adListener = object : AdListener() {
            override fun onAdLoaded() = reply("banner", JSONObject().put("status", "loaded").put("height", size.height))

            override fun onAdFailedToLoad(error: LoadAdError) {
                hideAnchored()
                fail("banner", error.message)
            }
        }
        anchor(activity, banner, position)
        banner.loadAd(AdRequest.Builder().build())
    }

    private fun showNative(adUnitId: String, position: String) {
        val activity = activity() ?: return fail("native", "No screen to show the ad on.")

        AdLoader.Builder(activity, adUnitId)
            .forNativeAd { ad ->
                val card = nativeAdCard(activity, ad)
                anchor(activity, card, position)
                nativeAd = ad
                reply("native", JSONObject().put("status", "loaded").put("height", NATIVE_CARD_HEIGHT))
            }
            .withAdListener(object : AdListener() {
                override fun onAdFailedToLoad(error: LoadAdError) = fail("native", error.message)
            })
            .build()
            .loadAd(AdRequest.Builder().build())
    }

    // Small native ad: icon, "Annonce" label, headline, body, call to action. The SDK adds the AdChoices mark.
    private fun nativeAdCard(activity: Activity, ad: NativeAd): NativeAdView {
        fun dp(value: Int) = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, value.toFloat(), activity.resources.displayMetrics).roundToInt()

        val icon = ImageView(activity).apply { setImageDrawable(ad.icon?.drawable) }
        val label = TextView(activity).apply {
            text = "Annonce"
            textSize = 10f
            setTextColor(Color.parseColor("#6B6B6B"))
        }
        val headline = TextView(activity).apply {
            text = ad.headline
            textSize = 14f
            maxLines = 1
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(Color.parseColor("#15161A"))
        }
        val body = TextView(activity).apply {
            text = ad.body
            textSize = 12f
            maxLines = 1
            setTextColor(Color.parseColor("#6B6B6B"))
        }
        val callToAction = Button(activity).apply {
            text = ad.callToAction
            textSize = 12f
            isAllCaps = false
        }

        val texts = LinearLayout(activity).apply {
            orientation = LinearLayout.VERTICAL
            addView(label)
            addView(headline)
            addView(body)
        }
        val row = LinearLayout(activity).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setBackgroundColor(Color.WHITE)
            setPadding(dp(12), dp(8), dp(12), dp(8))
            addView(icon, LinearLayout.LayoutParams(dp(44), dp(44)))
            addView(texts, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f).apply { marginStart = dp(12); marginEnd = dp(12) })
            addView(callToAction, LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(40)))
        }

        return NativeAdView(activity).apply {
            addView(row, ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(NATIVE_CARD_HEIGHT)))
            iconView = icon
            headlineView = headline
            bodyView = body
            callToActionView = callToAction
            setNativeAd(ad)
        }
    }

    // Over the web screen, against the top or bottom edge, clear of the system bars.
    private fun anchor(activity: Activity, view: View, position: String) {
        hideAnchored()

        val content = activity.findViewById<FrameLayout>(android.R.id.content)
        val bars = ViewCompat.getRootWindowInsets(content)?.getInsets(WindowInsetsCompat.Type.systemBars())
        val top = "top" == position
        val params = FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, if (top) Gravity.TOP else Gravity.BOTTOM)
        params.topMargin = if (top) bars?.top ?: 0 else 0
        params.bottomMargin = if (top) 0 else bars?.bottom ?: 0

        content.addView(view, params)
        anchored = view
    }

    private fun hideAnchored() {
        anchored?.let { view ->
            (view.parent as? ViewGroup)?.removeView(view)
            (view as? AdView)?.destroy()
        }
        anchored = null
        nativeAd?.destroy()
        nativeAd = null
    }

    private fun activity(): Activity? {
        val activity = bridgeDelegate.destination.fragment.activity ?: return null
        if (!initialized) {
            initialized = true
            MobileAds.initialize(activity)
        }

        return activity
    }

    private fun reply(event: String, data: JSONObject) {
        replyTo(event, data.toString())
    }

    private fun fail(event: String, error: String) {
        Log.w(TAG, "$event ad failed: $error")
        reply(event, JSONObject().put("status", "failed").put("error", error))
    }

    private companion object {
        const val TAG = "AdMob"
        const val NATIVE_CARD_HEIGHT = 64
        var initialized = false
    }
}
