import AVFoundation
import HotwireNative
import UIKit

/// Native side of the `bridge--barcode-scanner` Stimulus controller: scans a QR code with the camera and
/// replies to the "scan" event with `{"code": "…"}` (no code when the scan is closed or the camera refused).
/// Needs `NSCameraUsageDescription` in Info.plist.
final class BarcodeScannerComponent: BridgeComponent {
    override nonisolated class var name: String { "barcode-scanner" }

    override func onReceive(message: Message) {
        guard message.event == "scan", let screen = delegate?.destination as? UIViewController else { return }

        let scanner = BarcodeScannerViewController { [weak self] code in
            self?.reply(to: "scan", with: ScanData(code: code))
        }
        screen.present(UINavigationController(rootViewController: scanner), animated: true)
    }
}

private extension BarcodeScannerComponent {
    struct ScanData: Encodable {
        let code: String?
    }
}

/// Camera preview that ends on the first QR code read, or when closed.
final class BarcodeScannerViewController: UIViewController, AVCaptureMetadataOutputObjectsDelegate {
    private let session = AVCaptureSession()
    private let preview = AVCaptureVideoPreviewLayer()
    private let onResult: (String?) -> Void
    private var finished = false

    init(onResult: @escaping (String?) -> Void) {
        self.onResult = onResult
        super.init(nibName: nil, bundle: nil)
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) {
        fatalError("init(coder:) is not supported")
    }

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .black
        title = "Scanner un QR code"
        navigationItem.leftBarButtonItem = UIBarButtonItem(systemItem: .close, primaryAction: UIAction { [weak self] _ in
            self?.finish(with: nil)
        })

        preview.session = session
        preview.videoGravity = .resizeAspectFill
        view.layer.addSublayer(preview)

        AVCaptureDevice.requestAccess(for: .video) { [weak self] granted in
            DispatchQueue.main.async {
                if granted {
                    self?.startCamera()
                } else {
                    self?.showUnavailable()
                }
            }
        }
    }

    override func viewDidLayoutSubviews() {
        super.viewDidLayoutSubviews()
        preview.frame = view.bounds
    }

    override func viewDidDisappear(_ animated: Bool) {
        super.viewDidDisappear(animated)
        // Swiped down: same as closed.
        finish(with: nil)
    }

    func metadataOutput(
        _ output: AVCaptureMetadataOutput,
        didOutput metadataObjects: [AVMetadataObject],
        from connection: AVCaptureConnection
    ) {
        guard let code = (metadataObjects.first as? AVMetadataMachineReadableCodeObject)?.stringValue else { return }
        finish(with: code)
    }

    private func startCamera() {
        guard let camera = AVCaptureDevice.default(for: .video),
              let input = try? AVCaptureDeviceInput(device: camera),
              session.canAddInput(input) else {
            showUnavailable()
            return
        }

        let output = AVCaptureMetadataOutput()
        session.addInput(input)
        session.addOutput(output)
        output.setMetadataObjectsDelegate(self, queue: .main)
        output.metadataObjectTypes = [.qr]

        let session = session
        DispatchQueue.global(qos: .userInitiated).async {
            session.startRunning()
        }
    }

    /// No camera (simulator) or access refused in Settings.
    private func showUnavailable() {
        let label = UILabel()
        label.text = "Caméra indisponible. Autorise-la dans Réglages, ou saisis le code à la main."
        label.textColor = .white
        label.textAlignment = .center
        label.numberOfLines = 0
        label.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(label)
        NSLayoutConstraint.activate([
            label.centerYAnchor.constraint(equalTo: view.centerYAnchor),
            label.leadingAnchor.constraint(equalTo: view.layoutMarginsGuide.leadingAnchor),
            label.trailingAnchor.constraint(equalTo: view.layoutMarginsGuide.trailingAnchor),
        ])
    }

    private func finish(with code: String?) {
        guard !finished else { return }
        finished = true

        if session.isRunning {
            session.stopRunning()
        }
        onResult(code)
        if presentingViewController != nil {
            dismiss(animated: true)
        }
    }
}
