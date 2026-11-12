package de.carmajaperlen.armbandrechner

import android.content.pm.ActivityInfo
import android.content.res.Configuration
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertTextContains
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTextReplacement
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.test.espresso.Espresso.closeSoftKeyboard
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

/** Runs against the real screen/ViewModel, on an isolated offline device only. */
class ProductLoginScreenRegressionTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<MainActivity>()

    @Test
    fun emptyCredentialsShowLocalErrorsAndEditingClearsThem() {
        waitForLogin()
        submit()
        composeRule.onNodeWithTag("login-error")
            .assertTextContains("Bitte Benutzer und Passwort eingeben.").assertIsDisplayed()
        composeRule.onNodeWithTag("login-submit").assertIsEnabled()

        composeRule.onNodeWithTag("login-username").performScrollTo().performTextInput("offline-test")
        composeRule.onNodeWithTag("login-error").assertDoesNotExist()
        closeSoftKeyboard()
        submit()
        composeRule.onNodeWithTag("login-error")
            .assertTextContains("Bitte Passwort eingeben.").assertIsDisplayed()

        composeRule.onNodeWithTag("login-username").performScrollTo().performTextReplacement("   ")
        composeRule.onNodeWithTag("login-password").performScrollTo().performTextInput("synthetic-only")
        composeRule.onNodeWithTag("login-error").assertDoesNotExist()
        closeSoftKeyboard()
        submit()
        composeRule.onNodeWithTag("login-error")
            .assertTextContains("Bitte Benutzer eingeben.").assertIsDisplayed()
        composeRule.onNodeWithTag("login-submit").assertIsEnabled()
    }

    @Test
    fun landscapeControlsStayOutsideSystemBarsAndPreserveInput() {
        waitForLogin()
        composeRule.onNodeWithTag("login-username").performTextInput("offline-rotation")
        closeSoftKeyboard()
        try {
            for (orientation in listOf(
                ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE,
                ActivityInfo.SCREEN_ORIENTATION_REVERSE_LANDSCAPE,
            )) {
                composeRule.runOnUiThread { composeRule.activity.requestedOrientation = orientation }
                composeRule.waitUntil(10_000) {
                    composeRule.activity.resources.configuration.orientation == Configuration.ORIENTATION_LANDSCAPE
                }
                composeRule.waitForIdle()
                composeRule.onNodeWithTag("login-username").performScrollTo()
                    .assertTextContains("offline-rotation")
                for (tag in listOf("login-username", "login-password-visibility", "login-submit")) {
                    val node = composeRule.onNodeWithTag(tag).performScrollTo().assertIsDisplayed()
                    val bounds = node.fetchSemanticsNode().boundsInWindow
                    composeRule.runOnIdle {
                        val decor = composeRule.activity.window.decorView
                        val insets = requireNotNull(ViewCompat.getRootWindowInsets(decor)).getInsets(
                            WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout(),
                        )
                        assertTrue("$tag overlaps the left system area", bounds.left >= insets.left)
                        assertTrue("$tag overlaps the right system area", bounds.right <= decor.width - insets.right)
                        assertTrue("$tag overlaps the top system area", bounds.top >= insets.top)
                        assertTrue("$tag overlaps the bottom system area", bounds.bottom <= decor.height - insets.bottom)
                    }
                }
            }
        } finally {
            composeRule.runOnUiThread { composeRule.activity.requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_UNSPECIFIED }
        }
    }

    private fun waitForLogin() {
        composeRule.waitUntil(10_000) {
            composeRule.onAllNodes(androidx.compose.ui.test.hasTestTag("login-submit"))
                .fetchSemanticsNodes().isNotEmpty()
        }
    }

    private fun submit() {
        composeRule.onNodeWithTag("login-submit").performScrollTo().performClick()
    }
}
