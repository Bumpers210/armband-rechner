package de.carmajaperlen.armbandrechner

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ProductModelV2Test {
    @Test
    fun v2ContractRequiresMinimumAppVersion() {
        assertTrue(MINIMUM_APP_VERSION_CODE >= 2)
        assertTrue(BuildConfig.VERSION_CODE >= MINIMUM_APP_VERSION_CODE)
        assertEquals("X-Carmaja-App-Version-Code", APP_VERSION_CODE_HEADER)
    }

    @Test
    fun v2UpdateContainsOnlyClientOwnedFields() {
        val payload = update().toJson()

        assertTrue(payload.has("expectedProductVersion"))
        assertTrue(payload.has("priceMinor"))
        assertTrue(payload.has("currency"))
        assertTrue(payload.has("salesEnabled"))
        assertFalse(payload.has("productVersion"))
        assertFalse(payload.has("sourceHash"))
        assertFalse(payload.has("stock"))
        assertFalse(payload.has("vintedUrl"))
    }

    @Test
    fun v2UpdateUsesFullReplacementFields() {
        val payload = update().toJson()
        val expected = setOf(
            "expectedProductVersion",
            "name",
            "description",
            "materials",
            "metalElements",
            "braceletSize",
            "careInstructions",
            "images",
            "priceMinor",
            "currency",
            "salesEnabled",
        )

        assertEquals(expected, payload.keys().asSequence().toSet())
    }

    private fun update() = ProductV2Update(
        expectedProductVersion = 0,
        name = "Testarmband",
        description = "Beschreibung",
        materials = listOf("Rosenquarz"),
        metalElements = emptyList(),
        braceletSize = "18 cm",
        careInstructions = emptyList(),
        images = emptyList(),
        priceMinor = 2490,
        currency = "eur",
        salesEnabled = true,
    )
}
