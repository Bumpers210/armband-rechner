package de.carmajaperlen.armbandrechner

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

class ProductApiPayloadTest {
    @Test
    fun savePayloadContainsCompleteV4ContractWithoutAvailabilityOrServerFields() {
        val payload = productDraft().toSaveJson()

        assertEquals("Rosenquarz", payload.getJSONArray("materials").getString(0))
        assertEquals(
            "Spacer Blume Edelstahl",
            payload.getJSONArray("metalElements").getString(0),
        )
        assertEquals(17.5, payload.getDouble("braceletSizeCm"), 0.0)
        assertEquals(6.0, payload.getDouble("pearlSizeMm"), 0.0)
        assertEquals("Legacy-Pflegehinweis", payload.getJSONArray("careInstructions").getString(0))
        assertEquals(1, payload.getJSONObject("descriptionDocument").getInt("version"))
        assertFalse(payload.has("description"))
        assertEquals(2490, payload.getInt("priceMinor"))
        assertEquals("eur", payload.getString("currency"))
        for (field in listOf("stock", "salesEnabled", "vintedUrl", "productVersion", "sourceHash")) {
            assertFalse(payload.has(field))
        }
    }

    private fun productDraft(): ProductDraft {
        return ProductDraft(
            draftId = "019fa2e6-cf3c-7073-9275-7d3b566f54ee",
            materials = listOf("Rosenquarz"),
            metalElements = listOf("Spacer Blume Edelstahl"),
            braceletSizeCm = "17.5",
            pearlSizeMm = "6",
            shortDescription = "Handgefertigtes Armband.",
            descriptionDocument = DescriptionDocument.fromPlainText("Handgefertigtes Armband."),
            careInstructions = listOf("Legacy-Pflegehinweis"),
            priceMinor = 2490,
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
