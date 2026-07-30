package de.carmajaperlen.armbandrechner

import java.math.BigDecimal
import java.net.URI
import java.util.UUID

enum class ProductStatus(val wireName: String) {
    Draft("draft"),
    Ready("ready"),
    Published("published"),
    Sold("sold"),
    Disabled("disabled"),
    ;

    companion object {
        fun fromWireName(value: String): ProductStatus {
            return entries.firstOrNull { it.wireName == value } ?: Draft
        }
    }
}

data class ProductImage(
    val localPath: String,
    val width: Int,
    val height: Int,
    val alt: String,
    val isMain: Boolean,
    val imageId: String = UUID.randomUUID().toString(),
    val serverImageId: String? = null,
    val serverFileName: String? = null,
    val serverIsMain: Boolean? = null,
    val uploadedAtVersion: Int? = null,
) {
    val isUploaded: Boolean
        get() = serverImageId == imageId && serverFileName != null
}

data class CalculationSnapshot(
    val quantities: Map<String, Int>,
    val workMinutes: String,
    val hourlyRate: String,
    val otherCosts: String,
    val markupPercent: String,
    val materialCosts: String,
    val laborCosts: String,
    val totalCosts: String,
    val recommendedSalePrice: String,
    val createdAtMillis: Long,
)

data class ProductDraft(
    val draftId: String,
    val sku: String? = null,
    val slug: String? = null,
    val version: Int = 0,
    val status: ProductStatus = ProductStatus.Draft,
    val name: String = "",
    val materials: List<String> = emptyList(),
    val metalElements: List<String> = emptyList(),
    val braceletSize: String = "",
    val stock: Int = 1,
    val shortDescription: String = "",
    val careInstructions: List<String> = emptyList(),
    val vintedUrl: String = "",
    val internalCalculation: CalculationSnapshot,
    val images: List<ProductImage> = emptyList(),
    val createdAtMillis: Long,
    val updatedAtMillis: Long,
    val serverUpdatedAt: String? = null,
    val pendingPublishOperationId: String? = null,
    val pendingSoldOperationId: String? = null,
    val pendingDisableOperationId: String? = null,
) {
    val displayName: String
        get() = name.ifBlank { "Unbenannter Entwurf" }

    val canPublish: Boolean
        get() = validateForPublish().isEmpty()

    fun validateForPublish(): Map<String, String> {
        return buildMap {
            if (name.isBlank()) put("name", "Produktname ist erforderlich.")
            if (materials.isEmpty()) put("materials", "Mindestens ein Material ist erforderlich.")
            if (braceletSize.isBlank()) put("braceletSize", "Armbandgröße ist erforderlich.")
            if (shortDescription.isBlank()) put("shortDescription", "Kurzbeschreibung ist erforderlich.")
            if (vintedUrl.isNotBlank() && !isValidVintedUrl(vintedUrl)) {
                put("vintedUrl", "Vinted-Link ist ungültig.")
            }
            if (images.isEmpty()) put("images", "Mindestens ein Hauptfoto ist erforderlich.")
        }
    }

    companion object {
        fun fromCalculation(
            prices: List<PriceItem>,
            values: CalculatorValues,
            totals: CalculatorTotals,
            nowMillis: Long,
        ): ProductDraft {
            val selectedMaterials = prices.mapNotNull { item ->
                val quantity = values.quantities[item.name] ?: 0
                item.name.takeIf { quantity > 0 }
            }
            val snapshot = CalculationSnapshot(
                quantities = values.quantities.filterValues { it > 0 },
                workMinutes = values.workMinutes,
                hourlyRate = values.hourlyRate,
                otherCosts = values.otherCosts,
                markupPercent = values.markupPercent,
                materialCosts = totals.materialCosts.toPlain(),
                laborCosts = totals.laborCosts.toPlain(),
                totalCosts = totals.totalCosts.toPlain(),
                recommendedSalePrice = totals.recommendedSalePrice.toPlain(),
                createdAtMillis = nowMillis,
            )

            return ProductDraft(
                draftId = UUID.randomUUID().toString(),
                materials = selectedMaterials,
                stock = 1,
                internalCalculation = snapshot,
                createdAtMillis = nowMillis,
                updatedAtMillis = nowMillis,
            )
        }
    }
}

internal fun ProductDraft.prepareForPublish(
    operationIdFactory: () -> String,
): ProductDraft {
    return copy(
        status = ProductStatus.Ready,
        pendingPublishOperationId = pendingPublishOperationId ?: operationIdFactory(),
    )
}

fun isValidVintedUrl(value: String): Boolean {
    return runCatching {
        val uri = URI(value.trim())
        uri.scheme == "https" &&
            uri.host?.lowercase() in setOf("vinted.de", "www.vinted.de") &&
            uri.port == -1 &&
            uri.userInfo == null &&
            uri.query.orEmpty()
                .split("&")
                .mapNotNull { it.substringBefore("=", "").lowercase().takeIf(String::isNotBlank) }
                .none { it in setOf("url", "redirect", "redirect_url", "redirect_uri", "next", "target") }
    }.getOrDefault(false)
}

fun List<String>.toMultilineText(): String = joinToString("\n")

fun multilineTextToList(value: String): List<String> {
    return value.lines().map { it.trim() }.filter { it.isNotEmpty() }.distinct()
}

private fun BigDecimal.toPlain(): String = setScale(2, java.math.RoundingMode.HALF_UP).toPlainString()
