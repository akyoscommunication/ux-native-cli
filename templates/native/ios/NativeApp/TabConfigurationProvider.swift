import Foundation
import HotwireNative

struct TabConfiguration: Equatable {
    let label: String
    let path: String
    let icon: String
}

enum TabConfigurationProvider {
    static func tabs() -> [TabConfiguration] {
        let settings = Hotwire.config.pathConfiguration.settings
        guard let raw = settings["tabs"] as? [[String: AnyHashable]] else {
            return []
        }
        return raw.compactMap { entry in
            guard let label = entry["label"] as? String,
                  let icon = entry["icon"] as? String,
                  let path = entry["path"] as? String else {
                return nil
            }
            return TabConfiguration(label: label, path: path, icon: icon)
        }
    }
}
