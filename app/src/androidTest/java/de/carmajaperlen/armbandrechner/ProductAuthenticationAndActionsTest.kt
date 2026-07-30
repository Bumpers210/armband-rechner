package de.carmajaperlen.armbandrechner

import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsFocused
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue
import java.util.concurrent.atomic.AtomicBoolean
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class ProductAuthenticationAndActionsTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun loginIsFirstScreenAndRememberSessionIsOptIn() {
        composeRule.setContent {
            var rememberSession by remember { mutableStateOf(false) }
            MaterialTheme {
                ProductLoginScreen(
                    state = ProductUiState(
                        sessionChecked = true,
                        loginEditor = ProductLoginEditorState(
                            rememberSession = rememberSession,
                        ),
                    ),
                    actions = ProductUiActions(
                        onRememberSessionChange = { rememberSession = it },
                    ),
                )
            }
        }

        composeRule.onNodeWithTag("login-test-environment").assertIsDisplayed()
        composeRule.onNodeWithTag("login-api-environment").assertIsDisplayed()
        composeRule.onNodeWithTag("login-remember-session").assertIsOff()
        composeRule.onNodeWithTag("login-remember-session").performClick()
        composeRule.onNodeWithTag("login-remember-session").assertIsOn()
    }

    @Test
    fun passwordVisibilityTogglePreservesValueCursorAndFocus() {
        val initialPassword = TextFieldValue(
            text = "Sicheres Passwort",
            selection = TextRange(8),
        )
        var currentPassword = initialPassword
        composeRule.setContent {
            var password by remember { mutableStateOf(initialPassword) }
            currentPassword = password
            MaterialTheme {
                ProductLoginScreen(
                    state = ProductUiState(
                        sessionChecked = true,
                        loginEditor = ProductLoginEditorState(password = password),
                    ),
                    actions = ProductUiActions(
                        onPasswordChange = { password = it },
                    ),
                )
            }
        }

        val passwordField = composeRule.onNodeWithTag("login-password")
        passwordField.performClick().assertIsFocused()
        composeRule.onNodeWithText("Anzeigen").assertIsDisplayed()
        var valueBeforeToggle = initialPassword
        composeRule.runOnIdle {
            valueBeforeToggle = currentPassword
        }

        composeRule.onNodeWithTag("login-password-visibility").performClick()

        passwordField.assertIsFocused()
        composeRule.onNodeWithText("Verbergen").assertIsDisplayed()
        composeRule.runOnIdle {
            assertEquals(valueBeforeToggle, currentPassword)
        }

        composeRule.onNodeWithTag("login-password-visibility").performClick()

        passwordField.assertIsFocused()
        composeRule.onNodeWithText("Anzeigen").assertIsDisplayed()
        composeRule.runOnIdle {
            assertEquals(valueBeforeToggle, currentPassword)
        }
    }

    @Test
    fun unsavedDraftOffersExplicitDiscardAction() {
        val discarded = AtomicBoolean(false)
        val draft = publishedDraft().copy(status = ProductStatus.Draft, sku = null)
        composeRule.setContent {
            MaterialTheme {
                ProductDraftForm(
                    draft = draft,
                    editor = ProductDraftEditorState.fromDraft(draft),
                    fieldErrors = emptyMap(),
                    busy = false,
                    actions = ProductUiActions(
                        onDiscardSelected = { discarded.set(true) },
                    ),
                    onPickImages = {},
                    hasUnsavedChanges = true,
                )
            }
        }

        composeRule.onNodeWithTag("product-discard-unsaved").performClick()

        assertTrue(discarded.get())
    }

    @Test
    fun publishedProductShowsExactlyItsThreeActions() {
        composeRule.setContent {
            MaterialTheme {
                PublishedProductView(
                    draft = publishedDraft(),
                    busy = false,
                    actions = ProductUiActions.noop(),
                )
            }
        }

        composeRule.onNodeWithText("Verkauft").assertIsDisplayed()
        composeRule.onNodeWithText("Deaktivieren").assertIsDisplayed()
        composeRule.onNodeWithText("Bearbeiten").assertIsDisplayed()
        composeRule.onNodeWithText("Auf Testwebsite veröffentlichen").assertDoesNotExist()
        composeRule.onNodeWithText("Speichern").assertDoesNotExist()
        composeRule.onNodeWithText("Speichern und synchronisieren").assertDoesNotExist()
    }

    @Test
    fun editActionCanOpenEditorWithControlledRepublishAndWithoutCareInput() {
        composeRule.setContent {
            var editing by remember { mutableStateOf(false) }
            val draft = remember { publishedDraft() }
            MaterialTheme {
                if (editing) {
                    ProductDraftForm(
                        draft = draft,
                        editor = ProductDraftEditorState.fromDraft(draft),
                        fieldErrors = emptyMap(),
                        busy = false,
                        actions = ProductUiActions.noop(),
                        onPickImages = {},
                        isPublishedEdit = true,
                    )
                } else {
                    PublishedProductView(
                        draft = draft,
                        busy = false,
                        actions = ProductUiActions(
                            onEdit = { editing = true },
                        ),
                    )
                }
            }
        }

        composeRule.onNodeWithTag("published-edit").performClick()

        composeRule.onNodeWithText("Änderungen erneut veröffentlichen").assertIsDisplayed()
        composeRule.onNodeWithTag("product-pearls").assertIsDisplayed()
        composeRule.onNodeWithTag("product-spacers").assertIsDisplayed()
        composeRule.onNodeWithText("Pflegehinweise").assertDoesNotExist()
        composeRule.onNodeWithText("Verkauft").assertDoesNotExist()
        composeRule.onNodeWithText("Deaktivieren").assertDoesNotExist()
    }

    private fun publishedDraft(): ProductDraft {
        return ProductDraft(
            draftId = "019fa2e6-cf3c-7073-9275-7d3b566f54ee",
            sku = "CP-2026-0001",
            version = 7,
            status = ProductStatus.Published,
            name = "Rosenquarz Armband",
            materials = listOf("Rosenquarz"),
            metalElements = listOf("Spacer Blume Edelstahl"),
            shortDescription = "Testbeschreibung",
            internalCalculation = CalculationSnapshot(
                quantities = emptyMap(),
                workMinutes = "0",
                hourlyRate = "0",
                otherCosts = "0",
                markupPercent = "0",
                materialCosts = "0.00",
                laborCosts = "0.00",
                totalCosts = "0.00",
                recommendedSalePrice = "0.00",
                createdAtMillis = 1L,
            ),
            createdAtMillis = 1L,
            updatedAtMillis = 1L,
        )
    }
}
