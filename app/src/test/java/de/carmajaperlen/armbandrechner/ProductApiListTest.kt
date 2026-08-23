package de.carmajaperlen.armbandrechner

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ProductApiListTest {
    @Test
    fun productListParsesEditableCollectionAndImageMetadata() {
        val products = JSONObject(
            """
            {
              "productModelVersion": 3,
              "products": [{
                "productId": "019fa2e6-cf3c-7073-9275-7d3b566f54ee",
                "productModelVersion": 3,
                "productVersion": 4,
                "version": 7,
                "sourceHash": "${"a".repeat(64)}",
                "sku": "CP-2026-0001",
                "slug": "cp-2026-0001-ares",
                "status": "published",
                "name": "Ares",
                "description": "Dauerhaft verfügbar.",
                "descriptionDocument": {
                  "version": 1,
                  "blocks": [{"type":"paragraph","spans":[{
                    "text":"Dauerhaft verfügbar.",
                    "bold":false,
                    "italic":false,
                    "font":"standard",
                    "size":"normal"
                  }]}]
                },
                "materials": ["Tigerauge"],
                "metalElements": ["Edelstahl"],
                "braceletSizeCm": 18,
                "pearlSizeMm": 8,
                "careInstructions": [],
                "priceMinor": 2000,
                "currency": "eur",
                "available": true,
                "images": [{
                  "imageId": "9b48a2c0-c1d8-4cb3-88cb-fad23069b8de",
                  "fileName": "01.jpg",
                  "isMain": true,
                  "alt": "Ares Armband",
                  "width": 1200,
                  "height": 900
                }]
              }]
            }
            """.trimIndent(),
        ).toServerUpdates()

        val ares = products.single()
        assertEquals("Ares", ares.name)
        assertEquals(ProductStatus.Published, ares.status)
        assertTrue(ares.available)
        assertEquals("Dauerhaft verfügbar.", ares.descriptionDocument?.plainText())
        assertEquals("Ares Armband", ares.images.single().alt)
        assertEquals(1200, ares.images.single().width)
        assertEquals(900, ares.images.single().height)
    }
}
