package %NATIVE_APPLICATION_ID%

import dev.hotwire.core.config.Hotwire

data class NativeTab(val label: String, val path: String, val icon: String)

object PathConfigurationLoader {
    fun loadTabs(): List<NativeTab> {
        val tabs = Hotwire.config.pathConfiguration.settings["tabs"] as? List<*>
            ?: return emptyList()

        return tabs.mapNotNull { item ->
            val obj = item as? Map<*, *> ?: return@mapNotNull null
            val label = (obj["label"] as? String)?.takeIf { it.isNotBlank() } ?: return@mapNotNull null
            val path = (obj["path"] as? String)?.takeIf { it.isNotBlank() } ?: return@mapNotNull null
            val icon = (obj["icon"] as? String)?.takeIf { it.isNotBlank() } ?: return@mapNotNull null
            NativeTab(label, path, icon)
        }
    }
}
