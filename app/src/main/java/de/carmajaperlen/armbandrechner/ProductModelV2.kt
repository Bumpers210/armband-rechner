package de.carmajaperlen.armbandrechner

import org.json.JSONArray
import org.json.JSONObject

const val PRODUCT_MODEL_V2 = 2
const val MINIMUM_APP_VERSION_CODE = 2
const val APP_VERSION_CODE_HEADER = "X-Carmaja-App-Version-Code"

data class ProductImageV2(
    val imageId: String,
    val fileName: String,
    val alt: String,
    val width: Int,
    val height: Int,
    val isMain: Boolean,
)

data class ProductV2(
    val productModelVersion: Int,
    val productId: String,
    val productVersion: Int,
    val sourceHash: String,
    val name: String,
    val description: String,
    val materials: List<String>,
    val metalElements: List<String>,
    val braceletSize: String,
    val careInstructions: List<String>,
    val images: List<ProductImageV2>,
    val priceMinor: Int,
    val currency: String,
    val salesEnabled: Boolean,
)

/**
 * Vollständiger v2-PUT-Vertrag. productVersion und sourceHash sind absichtlich
 * nicht enthalten: Beide Werte werden ausschließlich vom Server verwaltet.
 */
data class ProductV2Update(
    val expectedProductVersion: Int,
    val name: String,
    val description: String,
    val materials: List<String>,
    val metalElements: List<String>,
    val braceletSize: String,
    val careInstructions: List<String>,
    val images: List<ProductImageV2>,
    val priceMinor: Int,
    val currency: String,
    val salesEnabled: Boolean,
) {
    fun toJson(): JSONObject = JSONObject()
        .put("expectedProductVersion", expectedProductVersion)
        .put("name", name)
        .put("description", description)
        .put("materials", JSONArray(materials))
        .put("metalElements", JSONArray(metalElements))
        .put("braceletSize", braceletSize)
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
