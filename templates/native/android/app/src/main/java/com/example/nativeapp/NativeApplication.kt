package %NATIVE_APPLICATION_ID%

import android.app.Application
import com.masilotti.bridgecomponents.shared.Bridgework%NATIVE_ANDROID_FACTORY_IMPORT%
import dev.hotwire.core.bridge.KotlinXJsonConverter
import dev.hotwire.core.config.Hotwire
import dev.hotwire.core.turbo.config.PathConfiguration
import dev.hotwire.navigation.config.registerBridgeComponents

class NativeApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        Hotwire.config.jsonConverter = KotlinXJsonConverter()
        Hotwire.registerBridgeComponents(*Bridgework.coreComponents%NATIVE_NOTIF_ANDROID_COMPONENTS%%NATIVE_BARCODE_ANDROID_COMPONENTS%%NATIVE_ADMOB_ANDROID_COMPONENTS%)
        Hotwire.loadPathConfiguration(
            context = this,
            location = PathConfiguration.Location(
                remoteFileUrl = "%NATIVE_BASE_URL%/config/android_v1.json",
            ),
        )
    }
}
