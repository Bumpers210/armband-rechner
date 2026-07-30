package de.carmajaperlen.armbandrechner

import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ProductEditorStateTest {
    @Test
    fun editorUpdatePreservesTextAndSelectionWithoutNormalizing() {
        val editor = ProductDraftEditorState.fromDraft(testDraft())
        val input = TextFieldValue(
            text = "  Roter Dragon-Veins-Achat und Erdbeerquarz  ",
            selection = TextRange(14),
        )

        val updated = editor.update(ProductEditorField.Materials, input)

        assertEquals(input, updated.materials)
        assertEquals("  Roter Dragon-Veins-Achat und Erdbeerquarz  ", updated.materials.text)
        assertEquals(TextRange(14), updated.materials.selection)
    }

    @Test
    fun normalizationHappensOnlyWhenEditorIsAppliedForSaving() {
        val draft = testDraft()
        val editor = ProductDraftEditorState.fromDraft(draft).copy(
            name = TextFieldValue("  Handgefertigtes Edelsteinarmband  "),
            materials = TextFieldValue(
                "  Dragon-Veins-Achat  \n\nErdbeerquarz\nDragon-Veins-Achat",
            ),
            braceletSize = TextFieldValue("  Groesse 17 cm  "),
            stock = TextFieldValue(" 2 "),
            shortDescription = TextFieldValue(
                "  Persoenliche Anfertigung  auf Anfrage  ",
            ),
        )

        assertEquals("  Handgefertigtes Edelsteinarmband  ", editor.name.text)
        assertEquals(
            "  Dragon-Veins-Achat  \n\nErdbeerquarz\nDragon-Veins-Achat",
            editor.materials.text,
        )

        val saved = editor.applyTo(draft)

        assertEquals("Handgefertigtes Edelsteinarmband", saved.name)
        assertEquals(listOf("Dragon-Veins-Achat", "Erdbeerquarz"), saved.materials)
        assertEquals("Groesse 17 cm", saved.braceletSize)
        assertEquals(2, saved.stock)
        assertEquals("Persoenliche Anfertigung  auf Anfrage", saved.shortDescription)
    }

    @Test
    fun stockMayBeTemporarilyEmptyButIsRejectedWhenSaving() {
        val editor = ProductDraftEditorState.fromDraft(testDraft()).copy(
            stock = TextFieldValue(""),
        )

        assertEquals("", editor.stock.text)
        assertTrue(editor.validateForSave().containsKey("stock"))
    }

    @Test
    fun existingDraftInitializesSelectionsAtTextEnd() {
        val editor = ProductDraftEditorState.fromDraft(
            testDraft().copy(name = "Achat-Armband"),
        )

        assertEquals("Achat-Armband", editor.name.text)
        assertEquals(TextRange("Achat-Armband".length), editor.name.selection)
    }

    private fun testDraft(): ProductDraft {
        return ProductDraft(
            draftId = "019fa2e6-cf3c-7073-9275-7d3b566f54ee",
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
