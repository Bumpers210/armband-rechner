package de.carmajaperlen.armbandrechner

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

class ProductApiPayloadTest {
    @Test
    fun savePayloadContainsPearlsAndSpacersButNoCareInstructions() {
        val payload = productDraft().toSaveJson()

        assertEquals("Rosenquarz", payload.getJSONArray("materials").getString(0))
        assertEquals(
            "Spacer Blume Edelstahl",
            payload.getJSONArray("metalElements").getString(0),
        )
        assertFalse(payload.has("careInstructions"))
    }

    private fun productDraft(): ProductDraft {
        return ProductDraft(
            draftId = "019fa2e6-cf3c-7073-9275-7d3b566f54ee",
            materials = listOf("Rosenquarz"),
            metalElements = listOf("Spacer Blume Edelstahl"),
            careInstructions = listOf("Legacy-Pflegehinweis"),
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
