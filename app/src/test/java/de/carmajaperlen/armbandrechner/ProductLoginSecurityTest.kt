package de.carmajaperlen.armbandrechner

import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.TextFieldValue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

class ProductLoginSecurityTest {
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
