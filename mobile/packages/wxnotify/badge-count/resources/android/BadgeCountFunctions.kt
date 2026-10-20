package com.wxnotify.plugins.badge_count

import android.content.Context
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse

/**
 * Functions related to the app icon badge
 * Namespace: "BadgeCount.*"
 */
object BadgeCountFunctions {

    /**
     * Set the app icon badge number. Android has no badge number (the launcher shows its own dot for
     * notifications in the tray), so this is a no-op that answers success.
     * Parameters:
     *   - count: int - ignored on Android
     */
    class SetCount(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return BridgeResponse.success(mapOf("count" to 0))
        }
    }
}
