package %NATIVE_APPLICATION_ID%

import android.Manifest
import android.os.Build
import android.util.Log
import androidx.core.app.ActivityCompat
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import dev.hotwire.core.bridge.BridgeComponent
import dev.hotwire.core.bridge.BridgeDelegate
import dev.hotwire.core.bridge.Message
import dev.hotwire.navigation.destinations.HotwireDestination
import org.json.JSONObject

// Native side of the MIT `bridge--notification-token` Stimulus controller from @joemasilotti/bridge-components.
// ponytail: no FirebaseMessagingService; FCM displays `notification` payloads itself while the app is in background.
// Add a service if you need data messages, foreground display or onNewToken refreshes.
class NotificationTokenComponent(
    name: String,
    private val bridgeDelegate: BridgeDelegate<HotwireDestination>
) : BridgeComponent<HotwireDestination>(name, bridgeDelegate) {

    override fun onReceive(message: Message) {
        when (message.event) {
            "get" -> handleGet()
            "connect" -> Unit
            else -> Log.w(TAG, "Unknown event for message: $message")
        }
    }

    private fun handleGet() {
        val activity = bridgeDelegate.destination.fragment.activity ?: return

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            // The FCM token does not depend on this permission, only displaying notifications does.
            ActivityCompat.requestPermissions(activity, arrayOf(Manifest.permission.POST_NOTIFICATIONS), 0)
        }

        if (FirebaseApp.getApps(activity).isEmpty()) {
            Log.e(TAG, "Firebase is not configured: add google-services.json to the app/ directory and rebuild.")
            return
        }

        FirebaseMessaging.getInstance().token
            .addOnSuccessListener { token -> replyTo("get", JSONObject().put("token", token).toString()) }
            .addOnFailureListener { error -> Log.e(TAG, "Unable to fetch FCM token", error) }
    }

    private companion object {
        const val TAG = "NotificationToken"
    }
}
