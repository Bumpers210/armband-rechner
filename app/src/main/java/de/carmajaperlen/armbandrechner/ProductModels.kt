package de.carmajaperlen.armbandrechner

import java.math.BigDecimal
import java.math.RoundingMode
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
    val productVersion: Int = 0,
    val sourceHash: String? = null,
    val status: ProductStatus = ProductStatus.Draft,
    val name: String = "",
    val materials: List<String> = emptyList(),
    val metalElements: List<String> = emptyList(),
    val modelVersion: Int = PRODUCT_MODEL_VERSION,
    val braceletSizeCm: String = "",
    val pearlSizeMm: String = "",
    val shortDescription: String = "",
    val descriptionDocument: DescriptionDocument? = null,
    val careInstructions: List<String> = emptyList(),
    val priceMinor: Int = 0,
    val currency: String = "eur",
    val salesEnabled: Boolean = false,
    val internalCalculation: CalculationSnapshot,
    val images: List<ProductImage> = emptyList(),
    val createdAtMillis: Long,
    val updatedAtMillis: Long,
    val serverUpdatedAt: String? = null,
    val pendingV2SaveOperationId: String? = null,
    val pendingPublishOperationId: String? = null,
    val pendingArchiveOperationId: String? = null,
    val pendingRestoreOperationId: String? = null,
) {
    val displayName: String
        get() = name.ifBlank { "Unbenannter Entwurf" }

    val canPublish: Boolean
        get() = validateForPublish().isEmpty()

    fun validateForPublish(): Map<String, String> {
        return buildMap {
            if (name.isBlank()) put("name", "Produktname ist erforderlich.")
            if (materials.isEmpty()) put("materials", "Mindestens ein Material ist erforderlich.")
            if (braceletSizeCm.isBlank()) put("braceletSizeCm", "Armbandgröße ist erforderlich.")
            if (pearlSizeMm.isBlank()) put("pearlSizeMm", "Perlengröße ist erforderlich.")
            if (shortDescription.isBlank()) put("shortDescription", "Kurzbeschreibung ist erforderlich.")
            if (shortDescription.length > DESCRIPTION_MAX_CHARACTERS) {
                put("shortDescription", "Kurzbeschreibung darf höchstens 500 Zeichen enthalten.")
            }
            runCatching {
                if (modelVersion >= PRODUCT_MODEL_VERSION) {
                    requireNotNull(descriptionDocument) {
                        "Formatierte Beschreibung ist erforderlich."
                    }
                }
                descriptionDocument?.let { document ->
                    document.requireValid()
                    require(document.plainText() == shortDescription) {
                        "Formatierte Beschreibung und Klartext stimmen nicht überein."
                    }
                }
            }
                .exceptionOrNull()
                ?.let { put("shortDescription", it.message ?: "Formatierte Beschreibung ist ungültig.") }
            if (priceMinor < 50) put("priceMinor", "Verkaufspreis muss mindestens 0,50 € betragen.")
            if (currency != "eur") put("currency", "Für V1 ist ausschließlich EUR zulässig.")
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
            val selectedItems = prices.filter { item ->
                val quantity = values.quantities[item.name] ?: 0
                quantity > 0
            }
            val selectedMaterials = selectedItems
                .filterNot(PriceItem::isSpacer)
                .map(PriceItem::name)
            val selectedSpacers = selectedItems
                .filter(PriceItem::isSpacer)
                .map { normalizeSpacerLabel(it.name) }
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
                metalElements = selectedSpacers,
                priceMinor = totals.recommendedSalePrice.toMinorUnits(),
                internalCalculation = snapshot,
                createdAtMillis = nowMillis,
                updatedAtMillis = nowMillis,
            )
        }
    }
}

internal fun PriceItem.isSpacer(): Boolean = name.contains("spacer", ignoreCase = true)

internal fun normalizeSpacerLabel(value: String): String {
    val normalized = value.trim().replace(Regex("\\s+"), " ")
    return if (normalized.contains("Edelstahl", ignoreCase = true)) {
        normalized
    } else {
        "$normalized Edelstahl".trim()
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

const val PRODUCT_MODEL_VERSION = 3

internal fun normalizeMeasurement(value: String): String? {
    val normalized = value.trim().replace(',', '.')
    val number = normalized.toBigDecimalOrNull()?.takeIf { it.signum() > 0 } ?: return null
    return number.stripTrailingZeros().toPlainString()
}

internal fun displayMeasurement(value: String, unit: String): String {
    return "${value.replace('.', ',')} $unit"
}

internal fun parsePriceMinor(value: String): Int? {
    val normalized = value.trim().replace(',', '.')
    val amount = normalized.toBigDecimalOrNull()?.takeIf { it.signum() >= 0 } ?: return null
    val cents = runCatching {
        amount.setScale(2, RoundingMode.UNNECESSARY).movePointRight(2).intValueExact()
    }.getOrNull() ?: return null
    return cents.takeIf { it >= 50 }
}

internal fun displayPriceMinor(value: Int): String =
    BigDecimal(value).movePointLeft(2).setScale(2).toPlainString().replace('.', ',')

fun List<String>.toMultilineText(): String = joinToString("\n")

fun multilineTextToList(value: String): List<String> {
    return value.lines().map { it.trim() }.filter { it.isNotEmpty() }.distinct()
}

private fun BigDecimal.toPlain(): String = setScale(2, java.math.RoundingMode.HALF_UP).toPlainString()

private fun BigDecimal.toMinorUnits(): Int =
    setScale(2, RoundingMode.HALF_UP).movePointRight(2).intValueExact()
