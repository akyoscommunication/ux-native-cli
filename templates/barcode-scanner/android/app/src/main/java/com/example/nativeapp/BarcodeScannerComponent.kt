package %NATIVE_APPLICATION_ID%

import android.util.Log
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.codescanner.GmsBarcodeScannerOptions
import com.google.mlkit.vision.codescanner.GmsBarcodeScanning
import dev.hotwire.core.bridge.BridgeComponent
import dev.hotwire.core.bridge.BridgeDelegate
import dev.hotwire.core.bridge.Message
import dev.hotwire.navigation.destinations.HotwireDestination
import org.json.JSONObject

// Native side of the `bridge--barcode-scanner` Stimulus controller: scans a QR code and replies to the
// "scan" event with {"code": "…"} (null when the scan is closed or fails).
// Uses Google's code scanner: Play services draws the camera screen, so the app needs neither the
// CAMERA permission nor a layout. Devices without Play services get no result.
class BarcodeScannerComponent(
    name: String,
    private val bridgeDelegate: BridgeDelegate<HotwireDestination>
) : BridgeComponent<HotwireDestination>(name, bridgeDelegate) {

    override fun onReceive(message: Message) {
        when (message.event) {
            "scan" -> scan()
            "connect" -> Unit
            else -> Log.w(TAG, "Unknown event for message: $message")
        }
    }

    private fun scan() {
        val activity = bridgeDelegate.destination.fragment.activity ?: return
        val options = GmsBarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_QR_CODE)
            .build()

        GmsBarcodeScanning.getClient(activity, options).startScan()
            .addOnSuccessListener { barcode -> reply(barcode.rawValue) }
            .addOnCanceledListener { reply(null) }
            .addOnFailureListener { error ->
                Log.e(TAG, "Unable to scan", error)
                reply(null)
            }
    }

    private fun reply(code: String?) {
        replyTo("scan", JSONObject().put("code", code ?: JSONObject.NULL).toString())
    }

    private companion object {
        const val TAG = "BarcodeScanner"
    }
}
