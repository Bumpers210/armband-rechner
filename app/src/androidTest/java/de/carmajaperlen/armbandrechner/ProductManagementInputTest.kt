package de.carmajaperlen.armbandrechner

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.key.Key
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.test.assertTextContains
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performKeyInput
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTextInputSelection
import androidx.compose.ui.test.performTextReplacement
import androidx.compose.ui.test.pressKey
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue
import org.junit.Rule
import org.junit.Test

class ProductManagementInputTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun productTextsKeepSpacesUmlautsAndHyphensAcrossFieldChanges() {
        setEditorContent()

        enterText("product-name", "Handgefertigtes Edelsteinarmband")
        enterText("product-pearls", "Roter Dragon-Veins-Achat und Erdbeerquarz")
        enterText("product-bracelet-size-cm", "17,5")
        enterText("product-short-description", "Persönliche Anfertigung auf Anfrage")

        composeRule.onNodeWithTag("product-name")
            .performScrollTo()
            .performClick()
            .assertTextContains("Handgefertigtes Edelsteinarmband")
        composeRule.onNodeWithTag("product-pearls")
            .performScrollTo()
            .assertTextContains("Roter Dragon-Veins-Achat und Erdbeerquarz")
        composeRule.onNodeWithTag("product-bracelet-size-cm")
            .performScrollTo()
            .assertTextContains("17,5")
        composeRule.onNodeWithTag("product-short-description")
            .performScrollTo()
            .assertTextContains("Persönliche Anfertigung auf Anfrage")
    }

    @Test
    fun cursorSelectionSurvivesInsertionDeletionAndRecomposition() {
        setEditorContent()
        val name = composeRule.onNodeWithTag("product-name")

        name.performScrollTo()
        name.performTextReplacement("Roter Achat")
        name.performTextInputSelection(TextRange(6))
        composeRule.onNodeWithTag("force-product-recomposition").performClick()
        name.performTextInput("Dragon-Veins-")
        name.assertTextContains("Roter Dragon-Veins-Achat")

        name.performTextReplacement("Achatband")
        name.performTextInputSelection(TextRange(5))
        name.performKeyInput { pressKey(Key.Backspace) }
        name.assertTextContains("Achaband")
    }

    @Test
    fun existingDraftEditorSurvivesDraftSwitchAndReturn() {
        setEditorContent(includeDraftSwitch = true)

        enterText("product-name", "Bestehender Entwurf mit Änderung")
        composeRule.onNodeWithTag("select-second-draft").performClick()
        enterText("product-name", "Zweiter Entwurf")
        composeRule.onNodeWithTag("select-first-draft").performClick()

        composeRule.onNodeWithTag("product-name")
            .performScrollTo()
            .assertTextContains("Bestehender Entwurf mit Änderung")
    }

    private fun enterText(tag: String, value: String) {
        val field = composeRule.onNodeWithTag(tag)
        field.performScrollTo()
        field.performTextClearance()
        field.performTextInput(value)
        field.assertTextContains(value)
    }

    private fun setEditorContent(includeDraftSwitch: Boolean = false) {
        composeRule.setContent {
            val firstDraft = remember { testDraft(FIRST_DRAFT_ID, "Bestehender Entwurf") }
            val secondDraft = remember { testDraft(SECOND_DRAFT_ID, "") }
            var selectedDraftId by remember { mutableStateOf(FIRST_DRAFT_ID) }
            var editors by remember {
                mutableStateOf(
                    mapOf(
                        firstDraft.draftId to ProductDraftEditorState.fromDraft(firstDraft),
                        secondDraft.draftId to ProductDraftEditorState.fromDraft(secondDraft),
                    ),
                )
            }
            var recompositionTick by remember { mutableIntStateOf(0) }
            val draft = if (selectedDraftId == FIRST_DRAFT_ID) firstDraft else secondDraft
            val editor = requireNotNull(editors[selectedDraftId])

            fun update(field: ProductEditorField, value: TextFieldValue) {
                val current = requireNotNull(editors[selectedDraftId])
                editors = editors + (selectedDraftId to current.update(field, value))
            }

            MaterialTheme {
                Column(modifier = Modifier.verticalScroll(rememberScrollState())) {
                    Text("Neuzusammensetzung $recompositionTick")
                    Button(
                        onClick = { recompositionTick++ },
                        modifier = Modifier.testTag("force-product-recomposition"),
                    ) {
                        Text("Neu zusammensetzen")
                    }
                    if (includeDraftSwitch) {
                        Button(
                            onClick = { selectedDraftId = FIRST_DRAFT_ID },
                            modifier = Modifier.testTag("select-first-draft"),
                        ) {
                            Text("Erster Entwurf")
                        }
                        Button(
                            onClick = { selectedDraftId = SECOND_DRAFT_ID },
                            modifier = Modifier.testTag("select-second-draft"),
                        ) {
                            Text("Zweiter Entwurf")
                        }
                    }
                    ProductDraftForm(
                        draft = draft,
                        editor = editor,
                        fieldErrors = emptyMap(),
                        busy = false,
                        actions = ProductUiActions(
                            onNameChange = { update(ProductEditorField.Name, it) },
                            onMaterialsChange = { update(ProductEditorField.Materials, it) },
                            onMetalElementsChange = {
                                update(ProductEditorField.MetalElements, it)
                            },
                            onBraceletSizeCmChange = {
                                update(ProductEditorField.BraceletSizeCm, it)
                            },
                            onPearlSizeMmChange = { update(ProductEditorField.PearlSizeMm, it) },
                            onShortDescriptionChange = {
                                update(ProductEditorField.ShortDescription, it)
                            },
                        ),
                        onPickImages = {},
                    )
                }
            }
        }
    }

    private fun testDraft(draftId: String, name: String): ProductDraft {
        return ProductDraft(
            draftId = draftId,
            name = name,
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

    companion object {
        private const val FIRST_DRAFT_ID = "019fa2e6-cf3c-7073-9275-7d3b566f54ee"
        private const val SECOND_DRAFT_ID = "119fa2e6-cf3c-7073-9275-7d3b566f54ee"
    }
}
