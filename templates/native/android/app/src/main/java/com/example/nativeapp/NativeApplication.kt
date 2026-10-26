package %NATIVE_APPLICATION_ID%

import android.app.Application
import android.content.pm.ApplicationInfo
import android.webkit.WebSettings
import com.masilotti.bridgecomponents.shared.Bridgework%NATIVE_ANDROID_FACTORY_IMPORT%
import dev.hotwire.core.bridge.KotlinXJsonConverter
import dev.hotwire.core.config.Hotwire
import dev.hotwire.core.turbo.config.PathConfiguration
import dev.hotwire.core.turbo.webview.HotwireWebView
import dev.hotwire.navigation.config.registerBridgeComponents

class NativeApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        Hotwire.config.jsonConverter = KotlinXJsonConverter()
        // A debug install keeps the previous HTML and JavaScript otherwise. Release builds keep the HTTP cache.
        if ((applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0) {
            Hotwire.config.makeCustomWebView = { context ->
                HotwireWebView(context).apply {
                    settings.cacheMode = WebSettings.LOAD_NO_CACHE
                    clearCache(true)
                }
            }
        }
        Hotwire.registerBridgeComponents(*Bridgework.coreComponents%NATIVE_NOTIF_ANDROID_COMPONENTS%%NATIVE_BARCODE_ANDROID_COMPONENTS%%NATIVE_ADMOB_ANDROID_COMPONENTS%%NATIVE_CALENDAR_ANDROID_COMPONENTS%%NATIVE_GAME_ANDROID_COMPONENTS%)
        Hotwire.loadPathConfiguration(
            context = this,
            location = PathConfiguration.Location(
                remoteFileUrl = /*###url###*/"http://127.0.0.1:8000"/*###url###*/ + "/config/android_v1.json",
            ),
            options = PathConfiguration.LoaderOptions(httpHeaders = mapOf("Cache-Control" to "no-cache")),
        )
    }
}
