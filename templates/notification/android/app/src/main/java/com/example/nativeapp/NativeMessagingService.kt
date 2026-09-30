package %NATIVE_APPLICATION_ID%

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

// In background FCM displays `notification` payloads itself and hands `data` (incl. "url") to MainActivity's launch intent.
// In foreground it only calls onMessageReceived, so the notification is built here, with the same "url" extra.
class NativeMessagingService : FirebaseMessagingService() {

    override fun onMessageReceived(message: RemoteMessage) {
        val notification = message.notification ?: return
        val manager = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(NotificationChannel(CHANNEL_ID, "Notifications", NotificationManager.IMPORTANCE_HIGH))
        }

        val intent = Intent(this, MainActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
            message.data["url"]?.let { putExtra("url", it) }
        }
        val requestCode = (message.messageId ?: System.currentTimeMillis().toString()).hashCode()
        val pendingIntent = PendingIntent.getActivity(this, requestCode, intent, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)

        manager.notify(
            requestCode,
            NotificationCompat.Builder(this, CHANNEL_ID)
                .setSmallIcon(applicationInfo.icon.takeIf { it != 0 } ?: android.R.drawable.ic_dialog_info)
                .setContentTitle(notification.title)
                .setContentText(notification.body)
                .setStyle(NotificationCompat.BigTextStyle().bigText(notification.body))
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(pendingIntent)
                .build(),
        )
    }

    // ponytail: a rotated token is picked up the next time a page calls bridge--notification-token#get.
    // Post it to /native-push/devices here if the app can stay closed for months.
    override fun onNewToken(token: String) = Unit

    private companion object {
        const val CHANNEL_ID = "default"
    }
}
