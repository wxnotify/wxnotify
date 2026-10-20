import Foundation
import UIKit

enum BadgeCountFunctions {

    // MARK: - BadgeCount.Set

    /// Set the app icon badge number
    /// Parameters:
    ///   - count: int - the number to show (negative or missing is treated as 0, which clears it)
    /// Returns:
    ///   - count: int - the number that was set
    class SetCount: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let count = max(0, (parameters["count"] as? NSNumber)?.intValue ?? 0)

            DispatchQueue.main.async {
                UIApplication.shared.applicationIconBadgeNumber = count
            }

            return BridgeResponse.success(data: ["count": count])
        }
    }

    // MARK: - BadgeCount.Timezone

    /// The phone's current timezone
    /// Returns:
    ///   - timezone: string - IANA id, e.g. "America/Chicago"
    class Timezone: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return BridgeResponse.success(data: ["timezone": TimeZone.current.identifier])
        }
    }
}
