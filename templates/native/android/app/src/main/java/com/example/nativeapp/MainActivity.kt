package %NATIVE_APPLICATION_ID%

import android.os.Bundle%NATIVE_NOTIF_ANDROID_INTENT_IMPORT%
import android.view.View
import androidx.activity.enableEdgeToEdge
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import com.google.android.material.bottomnavigation.BottomNavigationView
import dev.hotwire.core.config.Hotwire
import dev.hotwire.core.turbo.config.PathConfigurationLoadState
import dev.hotwire.navigation.activities.HotwireActivity
import dev.hotwire.navigation.navigator.NavigatorConfiguration
import dev.hotwire.navigation.navigator.NavigatorHost
import dev.hotwire.navigation.util.applyDefaultImeWindowInsets
import kotlinx.coroutines.launch

class MainActivity : HotwireActivity() {
    private val baseUrl = "%NATIVE_BASE_URL%"
    private var tabs: List<NativeTab> = emptyList()%NATIVE_NOTIF_ANDROID_PENDING_URL%

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        findViewById<View>(R.id.main_nav_host).applyDefaultImeWindowInsets()

        val bottomNav = findViewById<BottomNavigationView>(R.id.bottom_navigation)
        bottomNav.visibility = View.GONE%NATIVE_NOTIF_ANDROID_READ_INTENT%

        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                Hotwire.config.pathConfiguration.loadState.collect { state ->
                    if (state is PathConfigurationLoadState.Loaded) {
                        applyTabs(bottomNav)%NATIVE_NOTIF_ANDROID_CONSUME_URL%
                    }
                }
            }
        }
    }

    private fun applyTabs(bottomNav: BottomNavigationView) {
        val newTabs = PathConfigurationLoader.loadTabs()
        if (newTabs.isEmpty() || newTabs == tabs) return

        tabs = newTabs
        bottomNav.menu.clear()

        tabs.forEachIndexed { index, tab ->
            bottomNav.menu.add(0, index, index, tab.label)
                .setIcon(resolveIcon(tab.icon))
        }
        bottomNav.setOnItemSelectedListener { item ->
            tabs.getOrNull(item.itemId)?.let { tab ->
                routeTo(tab.path)
            }
            true
        }
        bottomNav.visibility = View.VISIBLE
    }

    private fun resolveIcon(name: String): Int {
        val resId = resources.getIdentifier(name, "drawable", packageName)
        return if (resId != 0) resId else 0
    }

    private fun routeTo(path: String) {
        val host = supportFragmentManager.findFragmentById(R.id.main_nav_host) as? NavigatorHost
            ?: return
        val absolute = if (path.startsWith("http")) path else baseUrl + path
        host.navigator.route(absolute)
    }%NATIVE_NOTIF_ANDROID_ON_NEW_INTENT%

    override fun navigatorConfigurations() = listOf(
        NavigatorConfiguration(
            name = "main",
            startLocation = baseUrl + (PathConfigurationLoader.loadTabs().firstOrNull()?.path ?: "/"),
            navigatorHostId = R.id.main_nav_host,
        ),
    )
}
