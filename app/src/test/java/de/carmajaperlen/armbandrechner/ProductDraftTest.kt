package de.carmajaperlen.armbandrechner

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
        assertEquals(listOf("Rosenquarz"), draft.materials)
        assertEquals(listOf("Edelstahlspacer"), draft.metalElements)
        assertEquals("1.76", draft.internalCalculation.materialCosts)
        assertEquals("17.50", draft.internalCalculation.recommendedSalePrice)
    }

    @Test
    fun spacerWithoutStainlessSteelGetsSuffix() {
        assertEquals("Spacer Blume Edelstahl", normalizeSpacerLabel("Spacer Blume"))
    }

    @Test
    fun spacerWithStainlessSteelSuffixRemainsUnchanged() {
        assertEquals(
            "Spacer Blume Edelstahl",
            normalizeSpacerLabel("Spacer Blume Edelstahl"),
        )
    }

    @Test
    fun spacerWithLeadingStainlessSteelRemainsUnchanged() {
        assertEquals(
            "Edelstahl Spacer Blume",
            normalizeSpacerLabel("Edelstahl Spacer Blume"),
        )
    }

    @Test
    fun spacerWithDifferentlyCasedStainlessSteelRemainsUnchanged() {
        assertEquals(
            "Spacer Blume edelstahl",
            normalizeSpacerLabel("  Spacer   Blume edelstahl  "),
        )
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
            modelVersion = 2,
            internalCalculation = snapshot,
            createdAtMillis = 1L,
            updatedAtMillis = 1L,
        )

        assertFalse(draft.canPublish)

        val ready = draft.copy(
            name = "Rosenquarz Armband",
            materials = listOf("Rosenquarz"),
            braceletSizeCm = "17",
            pearlSizeMm = "6",
            shortDescription = "Zartes Armband aus Rosenquarz.",
            priceMinor = 2490,
            images = listOf(ProductImage("image.jpg", 1600, 1200, "Foto", true)),
        )

        assertTrue(ready.canPublish)
        assertNotEquals("", ready.internalCalculation.recommendedSalePrice)
    }

    @Test
    fun publishPreparationMarksDraftReadyAndKeepsStableOperationId() {
        val draft = ProductDraft(
            draftId = "019fa2e6-cf3c-7073-9275-7d3b566f54ee",
            status = ProductStatus.Draft,
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

        val prepared = draft.prepareForPublish { "operation-1" }
        val retried = prepared.prepareForPublish {
            throw AssertionError("Retry darf keine neue operationId erzeugen.")
        }

        assertEquals(ProductStatus.Ready, prepared.status)
        assertEquals("operation-1", prepared.pendingPublishOperationId)
        assertEquals("operation-1", retried.pendingPublishOperationId)
        assertEquals(draft.draftId, retried.draftId)
        assertEquals(draft.version, retried.version)
    }
}
