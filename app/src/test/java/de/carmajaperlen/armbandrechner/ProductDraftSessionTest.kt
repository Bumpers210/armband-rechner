package de.carmajaperlen.armbandrechner

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ProductDraftSessionTest {
    @Test
    fun newUnsavedDraftIsRemovedCompletelyWhenDiscarded() {
        val session = ProductDraftSession()
        val newDraft = productDraft("new-draft", "Neuer Entwurf")
        session.initialize(emptyList())
        session.registerNew(newDraft)

        val result = session.discard(newDraft.draftId)

        assertEquals(ProductDraftDiscardResult.Remove(newDraft.draftId), result)
        assertTrue(session.unsavedDraftIds.isEmpty())
    }

    @Test
    fun unsavedChangesRestoreLastSavedDraft() {
        val saved = productDraft("saved-draft", "Gespeicherter Stand")
        val session = ProductDraftSession()
        session.initialize(listOf(saved))
        session.markChanged(saved.draftId)

        val result = session.discard(saved.draftId)

        assertEquals(ProductDraftDiscardResult.Restore(saved), result)
        assertTrue(session.unsavedDraftIds.isEmpty())
    }

    @Test
    fun explicitlySavedDraftIsNeverRemovedByDiscard() {
        val draft = productDraft("saved-draft", "Gespeicherter Stand")
        val session = ProductDraftSession()
        session.initialize(emptyList())
        session.registerNew(draft)
        session.markSaved(draft)

        val result = session.discard(draft.draftId)

        assertEquals(ProductDraftDiscardResult.Unchanged, result)
        assertTrue(session.unsavedDraftIds.isEmpty())
    }

    private fun productDraft(draftId: String, name: String): ProductDraft {
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
}
