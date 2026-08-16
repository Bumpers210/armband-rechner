package de.carmajaperlen.armbandrechner

import org.json.JSONArray
import org.json.JSONObject

const val PRODUCT_MODEL_V3 = 3
const val MINIMUM_V3_TEST_APP_VERSION_CODE = 6
const val MINIMUM_V3_PRODUCTION_APP_VERSION_CODE = 5

data class ProductV3Update(
    val expectedProductVersion: Int,
    val name: String,
    val descriptionDocument: DescriptionDocument,
    val materials: List<String>,
    val metalElements: List<String>,
    val braceletSizeCm: String,
    val pearlSizeMm: String,
    val careInstructions: List<String>,
    val images: List<ProductImageV2>,
    val priceMinor: Int,
    val currency: String,
    val salesEnabled: Boolean,
) {
    fun toJson(): JSONObject = JSONObject()
        .put("expectedProductVersion", expectedProductVersion)
        .put("name", name)
        .put("descriptionDocument", descriptionDocument.toJson())
        .put("materials", JSONArray(materials))
        .put("metalElements", JSONArray(metalElements))
        .put("braceletSizeCm", braceletSizeCm.toBigDecimal())
        .put("pearlSizeMm", pearlSizeMm.toBigDecimal())
        .put("careInstructions", JSONArray(careInstructions))
        .put(
            "images",
            JSONArray(images.map { image ->
                JSONObject()
                    .put("imageId", image.imageId)
                    .put("fileName", image.fileName)
                    .put("alt", image.alt)
                    .put("width", image.width)
                    .put("height", image.height)
                    .put("isMain", image.isMain)
            }),
        )
        .put("priceMinor", priceMinor)
        .put("currency", currency)
        .put("salesEnabled", salesEnabled)
}
