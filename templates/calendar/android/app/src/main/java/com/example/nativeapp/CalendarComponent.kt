package %NATIVE_APPLICATION_ID%

import android.content.ActivityNotFoundException
import android.content.Intent
import android.provider.CalendarContract
import android.util.Log
import dev.hotwire.core.bridge.BridgeComponent
import dev.hotwire.core.bridge.BridgeDelegate
import dev.hotwire.core.bridge.Message
import dev.hotwire.navigation.destinations.HotwireDestination
import org.json.JSONObject

// Native side of the `bridge--calendar` Stimulus controller: opens the phone's calendar app on a new
// event pre-filled with the title, dates and location sent by the "add" event.
// No permission: the calendar app does the writing.
class CalendarComponent(
    name: String,
    private val bridgeDelegate: BridgeDelegate<HotwireDestination>
) : BridgeComponent<HotwireDestination>(name, bridgeDelegate) {

    override fun onReceive(message: Message) {
        if (message.event != "add") {
            Log.w(TAG, "Unknown event for message: $message")
            return
        }
        val data = JSONObject(message.jsonData)
        val startsAt = data.optLong("startsAt")
        if (startsAt <= 0L) return
        val endsAt = data.optLong("endsAt")
        val activity = bridgeDelegate.destination.fragment.activity ?: return

        val intent = Intent(Intent.ACTION_INSERT)
            .setData(CalendarContract.Events.CONTENT_URI)
            .putExtra(CalendarContract.Events.TITLE, data.optString("title"))
            .putExtra(CalendarContract.EXTRA_EVENT_BEGIN_TIME, startsAt)
            .putExtra(CalendarContract.EXTRA_EVENT_END_TIME, if (endsAt > startsAt) endsAt else startsAt + TWO_HOURS)
        val location = data.optString("location")
        if (location.isNotEmpty()) {
            intent.putExtra(CalendarContract.Events.EVENT_LOCATION, location)
        }

        try {
            activity.startActivity(intent)
        } catch (error: ActivityNotFoundException) {
            Log.e(TAG, "No calendar app", error)
        }
    }

    private companion object {
        const val TAG = "Calendar"
        const val TWO_HOURS = 2 * 60 * 60 * 1000L
    }
}
