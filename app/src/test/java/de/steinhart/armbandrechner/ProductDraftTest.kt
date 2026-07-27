package de.steinhart.armbandrechner

import java.math.BigDecimal
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ProductDraftTest {
    @Test
    fun createsDraftFromCalculationWithStableDraftIdAndInternalCosts() {
        val prices = listOf(
            PriceItem("Rosenquarz", BigDecimal("0.15")),
            PriceItem("Edelstahlspacer", BigDecimal("0.13")),
        )
        val values = CalculatorValues(
            quantities = mapOf("Rosenquarz" to 10, "Edelstahlspacer" to 2),
            workMinutes = "30",
            hourlyRate = "20",
            otherCosts = "2",
            markupPercent = "25",
        )
        val totals = PriceCalculator.calculate(prices, values)

        val draft = ProductDraft.fromCalculation(prices, values, totals, 123L)

        assertTrue(draft.draftId.isNotBlank())
        assertEquals(null, draft.sku)
        assertEquals(0, draft.version)
        assertEquals(ProductStatus.Draft, draft.status)
        assertEquals(listOf("Rosenquarz", "Edelstahlspacer"), draft.materials)
        assertEquals("1.76", draft.internalCalculation.materialCosts)
        assertEquals("17.50", draft.internalCalculation.recommendedSalePrice)
    }

    @Test
    fun publishValidationRequiresPublicFieldsAndImagesButNoPublicPrice() {
        val snapshot = CalculationSnapshot(
            quantities = mapOf("Rosenquarz" to 1),
            workMinutes = "0",
            hourlyRate = "0",
            otherCosts = "0",
            markupPercent = "0",
            materialCosts = "0.15",
            laborCosts = "0.00",
            totalCosts = "0.15",
            recommendedSalePrice = "0.50",
            createdAtMillis = 1L,
        )
        val draft = ProductDraft(
            draftId = "019fa2e6-cf3c-7073-9275-7d3b566f54ee",
            internalCalculation = snapshot,
            createdAtMillis = 1L,
            updatedAtMillis = 1L,
        )

        assertFalse(draft.canPublish)

        val ready = draft.copy(
            name = "Rosenquarz Armband",
            materials = listOf("Rosenquarz"),
            braceletSize = "17 cm",
            shortDescription = "Zartes Armband aus Rosenquarz.",
            images = listOf(ProductImage("image.jpg", 1600, 1200, "Foto", true)),
        )

        assertTrue(ready.canPublish)
        assertNotEquals("", ready.internalCalculation.recommendedSalePrice)
    }

    @Test
    fun optionalVintedUrlUsesExactHttpsHostValidation() {
        assertTrue(isValidVintedUrl("https://vinted.de/items/123"))
        assertTrue(isValidVintedUrl("https://www.vinted.de/items/123"))
        assertFalse(isValidVintedUrl("http://vinted.de/items/123"))
        assertFalse(isValidVintedUrl("https://vinted.de.fremd.example/items/123"))
        assertFalse(isValidVintedUrl("https://user@vinted.de/items/123"))
        assertFalse(isValidVintedUrl("https://vinted.de:443/items/123"))
        assertFalse(isValidVintedUrl("https://vinted.de/redirect?url=https://example.org"))
    }
}
