package %NATIVE_APPLICATION_ID%

import android.content.pm.ActivityInfo
import android.view.View
import android.view.ViewGroup
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import dev.hotwire.core.bridge.BridgeComponent
import dev.hotwire.core.bridge.BridgeDelegate
import dev.hotwire.core.bridge.Message
import dev.hotwire.navigation.destinations.HotwireDestination

// Native side of the `bridge--game` Stimulus controller. "enter": landscape, system bars hidden (an edge
// swipe shows them for a moment), bottom navigation hidden, no swipe-to-refresh. "leave", or the screen
// being left: everything goes back as it was.
class GameComponent(
    name: String,
    private val bridgeDelegate: BridgeDelegate<HotwireDestination>,
) : BridgeComponent<HotwireDestination>(name, bridgeDelegate) {

    private var active = false
    private var applied = false
    private var previousOrientation = ActivityInfo.SCREEN_ORIENTATION_UNSPECIFIED
    private var previousNavVisibility = View.GONE

    override fun onReceive(message: Message) {
        when (message.event) {
            "enter" -> {
                active = true
                apply(true)
            }
            "leave" -> {
                active = false
                apply(false)
            }
        }
    }

    override fun onStart() {
        if (active) apply(true)
    }

    override fun onStop() {
        if (active) apply(false)
    }

    private fun apply(playing: Boolean) {
        if (playing == applied) return
        val activity = bridgeDelegate.destination.fragment.activity ?: return
        val window = activity.window
        val bars = WindowCompat.getInsetsController(window, window.decorView)
        val nav = activity.findViewById<View>(R.id.bottom_navigation)
        applied = playing

        if (playing) {
            previousOrientation = activity.requestedOrientation
            previousNavVisibility = nav?.visibility ?: View.GONE
            activity.requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE
            bars.systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            bars.hide(WindowInsetsCompat.Type.systemBars())
            nav?.visibility = View.GONE
        } else {
            activity.requestedOrientation = previousOrientation
            bars.show(WindowInsetsCompat.Type.systemBars())
            nav?.visibility = previousNavVisibility
        }
        refreshLayout()?.isEnabled = !playing
    }

    private fun refreshLayout(): SwipeRefreshLayout? {
        val root = bridgeDelegate.destination.fragment.view ?: return null
        return firstSwipeRefresh(root)
    }

    private fun firstSwipeRefresh(view: View): SwipeRefreshLayout? {
        if (view is SwipeRefreshLayout) return view
        if (view !is ViewGroup) return null
        for (index in 0 until view.childCount) {
            firstSwipeRefresh(view.getChildAt(index))?.let { return it }
        }
        return null
    }
}
