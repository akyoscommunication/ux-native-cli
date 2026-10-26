import EventKit
import EventKitUI
import HotwireNative
import UIKit

/// Native side of the `bridge--calendar` Stimulus controller: opens the system "add event" sheet,
/// pre-filled with the title, dates and location sent by the page with the "add" event.
/// Needs `NSCalendarsUsageDescription` and `NSCalendarsFullAccessUsageDescription` in Info.plist.
final class CalendarComponent: BridgeComponent {
    override nonisolated class var name: String { "calendar" }

    private let eventStore = EKEventStore()
    private var editorDelegate: EditorDelegate?

    override func onReceive(message: Message) {
        guard message.event == "add",
              let payload: CalendarPayload = message.data(),
              payload.startsAt > 0,
              let screen = delegate?.destination as? UIViewController else { return }

        requestAccess { [weak self] granted in
            guard let self else { return }
            guard granted else {
                let alert = UIAlertController(title: "Agenda", message: "Autorisez l’accès à l’agenda pour ajouter cet événement.", preferredStyle: .alert)
                alert.addAction(UIAlertAction(title: "OK", style: .default))
                screen.present(alert, animated: true)
                return
            }

            let item = EKEvent(eventStore: self.eventStore)
            item.title = payload.title
            item.startDate = Date(timeIntervalSince1970: payload.startsAt / 1000)
            item.endDate = Date(timeIntervalSince1970: (payload.endsAt > payload.startsAt ? payload.endsAt : payload.startsAt + 2 * 60 * 60 * 1000) / 1000)
            item.location = payload.location.isEmpty ? nil : payload.location
            item.calendar = self.eventStore.defaultCalendarForNewEvents

            let editor = EKEventEditViewController()
            editor.event = item
            editor.eventStore = self.eventStore
            let editDelegate = EditorDelegate { [weak self] in
                self?.editorDelegate = nil
            }
            self.editorDelegate = editDelegate
            editor.editViewDelegate = editDelegate
            screen.present(editor, animated: true)
        }
    }

    private func requestAccess(completion: @escaping (Bool) -> Void) {
        if #available(iOS 17.0, *) {
            eventStore.requestFullAccessToEvents { granted, _ in
                DispatchQueue.main.async { completion(granted) }
            }
        } else {
            eventStore.requestAccess(to: .event) { granted, _ in
                DispatchQueue.main.async { completion(granted) }
            }
        }
    }
}

private extension CalendarComponent {
    struct CalendarPayload: Decodable {
        let title: String
        let startsAt: Double
        let endsAt: Double
        let location: String
    }

    final class EditorDelegate: NSObject, EKEventEditViewDelegate {
        private let onDone: () -> Void

        init(onDone: @escaping () -> Void) {
            self.onDone = onDone
        }

        func eventEditViewController(_ controller: EKEventEditViewController, didCompleteWith action: EKEventEditViewAction) {
            controller.dismiss(animated: true)
            onDone()
        }
    }
}
