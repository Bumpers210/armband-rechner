package de.carmajaperlen.armbandrechner

import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.TextFieldValue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Test

class ProductLoginSecurityTest {
    @Test
    fun emptyCredentialsReportBothMissingFields() {
        assertEquals("Bitte Benutzer und Passwort eingeben.", ProductLoginEditorState().validationError())
    }

    @Test
    fun blankUsernameIsRejectedWithoutExposingThePassword() {
        val state = ProductLoginEditorState(
            username = TextFieldValue(" \t\n"),
            password = TextFieldValue("synthetic-password"),
        )
        assertEquals("Bitte Benutzer eingeben.", state.validationError())
    }

    @Test
    fun emptyPasswordReportsOnlyTheMissingPassword() {
        val state = ProductLoginEditorState(username = TextFieldValue("synthetic-user"))
        assertEquals("Bitte Passwort eingeben.", state.validationError())
    }

    @Test
    fun validationDoesNotTrimOrModifyEnteredCredentials() {
        val state = ProductLoginEditorState(
            username = TextFieldValue(" synthetic-user "),
            password = TextFieldValue(" "),
        )
        // A password's spaces are significant; only a truly empty password is missing.
        assertNull(state.validationError())
        assertEquals(" synthetic-user ", state.username.text)
        assertEquals(" ", state.password.text)
    }

    @Test
    fun passwordKeyboardDisablesSuggestionsAndAutocorrection() {
        assertEquals(KeyboardType.Password, securePasswordKeyboardOptions.keyboardType)
        assertEquals(
            KeyboardCapitalization.None,
            securePasswordKeyboardOptions.capitalization,
        )
        assertEquals(false, securePasswordKeyboardOptions.autoCorrectEnabled)
    }

    @Test
    fun passwordIsRedactedFromLoginAndUiStateDumps() {
        val secret = "nicht-ausgeben"
        val loginState = ProductLoginEditorState(password = TextFieldValue(secret))
        val uiState = ProductUiState(loginEditor = loginState)

        assertFalse(loginState.toString().contains(secret))
        assertFalse(uiState.toString().contains(secret))
    }
}
