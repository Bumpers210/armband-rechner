package de.carmajaperlen.armbandrechner

import android.content.ContentResolver
import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.media.ExifInterface
import android.net.Uri
import java.io.ByteArrayOutputStream
import java.io.File
import java.util.UUID
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject

class ProductDraftRepository(
    private val context: Context,
    private val currentTimeMillis: () -> Long = System::currentTimeMillis,
) {
    private val draftsDirectory = File(context.filesDir, "product-drafts")
    private val imagesDirectory = File(context.filesDir, "product-images")
    private val temporaryImagesDirectory = File(context.cacheDir, "product-draft-images")

    suspend fun loadDrafts(): List<ProductDraft> = withContext(Dispatchers.IO) {
        clearTemporaryImages()
        draftsDirectory.mkdirs()
        draftsDirectory
            .listFiles { file -> file.extension == "json" }
            .orEmpty()
            .mapNotNull { file ->
                runCatching { decodeDraft(JSONObject(file.readText())) }.getOrNull()
            }
            .sortedByDescending { it.updatedAtMillis }
    }

    suspend fun saveDraft(draft: ProductDraft): ProductDraft = withContext(Dispatchers.IO) {
        draftsDirectory.mkdirs()
        val updated = promoteTemporaryImages(draft).copy(updatedAtMillis = currentTimeMillis())
        draftFile(updated.draftId).writeText(encodeDraft(updated).toString(2))
        updated
    }

    suspend fun storeTemporaryImages(draft: ProductDraft, uris: List<Uri>): ProductDraft =
        withContext(Dispatchers.IO) {
            require(uris.isNotEmpty()) { "Mindestens ein Bild ist erforderlich." }
            require(uris.size <= MAX_IMAGES) { "Es sind höchstens fünf Bilder erlaubt." }

            val targetDirectory = temporaryImageDirectory(draft.draftId)
            targetDirectory.deleteRecursively()
            targetDirectory.mkdirs()

            val images = try {
                uris.mapIndexed { index, uri ->
                    val compressed = compressImage(
                        resolver = context.contentResolver,
                        uri = uri,
                        target = File(targetDirectory, "%02d.jpg".format(index + 1)),
                    )
                    ProductImage(
                        localPath = compressed.file.absolutePath,
                        width = compressed.width,
                        height = compressed.height,
                        alt = draft.name.ifBlank { "Carmaja-Perlen Armband" },
                        isMain = index == 0,
                    )
                }
            } catch (error: Throwable) {
                targetDirectory.deleteRecursively()
                throw error
            }
            draft.copy(images = images)
        }

    suspend fun discardTemporaryImages(draftId: String) = withContext(Dispatchers.IO) {
        temporaryImageDirectory(draftId).deleteRecursively()
    }

    fun clearTemporaryImages() {
        temporaryImagesDirectory.deleteRecursively()
    }

    fun createDraftFromCalculation(
        prices: List<PriceItem>,
        values: CalculatorValues,
        totals: CalculatorTotals,
    ): ProductDraft {
        val now = currentTimeMillis()
        return ProductDraft.fromCalculation(
            prices = prices,
            values = values,
            totals = totals,
            nowMillis = now,
        )
    }

    private fun draftFile(draftId: String): File = File(draftsDirectory, "$draftId.json")

    private fun temporaryImageDirectory(draftId: String): File {
        return File(temporaryImagesDirectory, draftId)
    }

    private fun promoteTemporaryImages(draft: ProductDraft): ProductDraft {
        val temporaryDirectory = temporaryImageDirectory(draft.draftId)
        if (!temporaryDirectory.isDirectory ||
            draft.images.none { image -> File(image.localPath).isInside(temporaryDirectory) }
        ) {
            return draft
        }

        require(draft.images.all { image -> File(image.localPath).isInside(temporaryDirectory) }) {
            "Temporäre und gespeicherte Produktbilder dürfen nicht gemischt werden."
        }

        imagesDirectory.mkdirs()
        val stagingDirectory = File(imagesDirectory, ".${draft.draftId}-${UUID.randomUUID()}")
        val targetDirectory = File(imagesDirectory, draft.draftId)
        stagingDirectory.mkdirs()

        val promotedImages = try {
            draft.images.mapIndexed { index, image ->
                val source = File(image.localPath)
                require(source.isFile) { "Temporäres Produktbild fehlt." }
                val staged = File(stagingDirectory, "%02d.jpg".format(index + 1))
                source.copyTo(staged, overwrite = false)
                image.copy(localPath = File(targetDirectory, staged.name).absolutePath)
            }
        } catch (error: Throwable) {
            stagingDirectory.deleteRecursively()
            throw error
        }

        val backupDirectory = File(imagesDirectory, ".${draft.draftId}-backup-${UUID.randomUUID()}")
        if (targetDirectory.exists() && !targetDirectory.renameTo(backupDirectory)) {
            stagingDirectory.deleteRecursively()
            error("Gespeicherte Produktbilder konnten nicht gesichert werden.")
        }
        if (!stagingDirectory.renameTo(targetDirectory)) {
            backupDirectory.renameTo(targetDirectory)
            stagingDirectory.deleteRecursively()
            error("Temporäre Produktbilder konnten nicht übernommen werden.")
        }

        backupDirectory.deleteRecursively()
        temporaryDirectory.deleteRecursively()
        return draft.copy(images = promotedImages)
    }

    private fun encodeDraft(draft: ProductDraft): JSONObject {
        return JSONObject()
            .put("draftId", draft.draftId)
            .put("sku", draft.sku)
            .put("slug", draft.slug)
            .put("version", draft.version)
            .put("modelVersion", draft.modelVersion)
            .put("status", draft.status.wireName)
            .put("name", draft.name)
            .put("materials", JSONArray(draft.materials))
            .put("metalElements", JSONArray(draft.metalElements))
            .put("braceletSizeCm", draft.braceletSizeCm)
            .put("pearlSizeMm", draft.pearlSizeMm)
            .put("shortDescription", draft.shortDescription)
            .put("careInstructions", JSONArray(draft.careInstructions))
            .put("internalCalculation", encodeCalculation(draft.internalCalculation))
            .put("images", JSONArray(draft.images.map(::encodeImage)))
            .put("createdAtMillis", draft.createdAtMillis)
            .put("updatedAtMillis", draft.updatedAtMillis)
            .put("serverUpdatedAt", draft.serverUpdatedAt)
            .put("pendingPublishOperationId", draft.pendingPublishOperationId)
            .put("pendingSoldOperationId", draft.pendingSoldOperationId)
            .put("pendingDisableOperationId", draft.pendingDisableOperationId)
    }

    private fun decodeDraft(json: JSONObject): ProductDraft {
        return ProductDraft(
            draftId = json.getString("draftId"),
            sku = json.optStringOrNull("sku"),
            slug = json.optStringOrNull("slug"),
            version = json.optInt("version", 0),
            status = ProductStatus.fromWireName(json.optString("status", "draft")),
            name = json.optString("name"),
            materials = json.optStringList("materials"),
            metalElements = json.optStringList("metalElements"),
            modelVersion = PRODUCT_MODEL_VERSION,
            braceletSizeCm = legacyBraceletSizeCm(json),
            pearlSizeMm = json.opt("pearlSizeMm")?.toString().orEmpty(),
            shortDescription = json.optString("shortDescription"),
            careInstructions = json.optStringList("careInstructions"),
            internalCalculation = decodeCalculation(json.getJSONObject("internalCalculation")),
            images = json.optJSONArray("images")?.let { array ->
                buildList {
                    for (index in 0 until array.length()) {
                        add(decodeImage(array.getJSONObject(index)))
                    }
                }
            }.orEmpty(),
            createdAtMillis = json.optLong("createdAtMillis"),
            updatedAtMillis = json.optLong("updatedAtMillis"),
            serverUpdatedAt = json.optStringOrNull("serverUpdatedAt"),
            pendingPublishOperationId = json.optStringOrNull("pendingPublishOperationId"),
            pendingSoldOperationId = json.optStringOrNull("pendingSoldOperationId"),
            pendingDisableOperationId = json.optStringOrNull("pendingDisableOperationId"),
        )
    }

    private fun legacyBraceletSizeCm(json: JSONObject): String {
        val modern = json.opt("braceletSizeCm")?.toString()
        if (!modern.isNullOrBlank()) return modern
        return json.optString("braceletSize")
            .removeSuffix("cm")
            .trim()
            .let { normalizeMeasurement(it).orEmpty() }
    }

    private fun encodeCalculation(snapshot: CalculationSnapshot): JSONObject {
        return JSONObject()
            .put("quantities", JSONObject(snapshot.quantities))
            .put("workMinutes", snapshot.workMinutes)
            .put("hourlyRate", snapshot.hourlyRate)
            .put("otherCosts", snapshot.otherCosts)
            .put("markupPercent", snapshot.markupPercent)
            .put("materialCosts", snapshot.materialCosts)
            .put("laborCosts", snapshot.laborCosts)
            .put("totalCosts", snapshot.totalCosts)
            .put("recommendedSalePrice", snapshot.recommendedSalePrice)
            .put("createdAtMillis", snapshot.createdAtMillis)
    }

    private fun decodeCalculation(json: JSONObject): CalculationSnapshot {
        val quantitiesJson = json.optJSONObject("quantities") ?: JSONObject()
        val quantities = buildMap {
            quantitiesJson.keys().forEach { key ->
                val quantity = quantitiesJson.optInt(key, 0)
                if (quantity > 0) put(key, quantity)
            }
        }

        return CalculationSnapshot(
            quantities = quantities,
            workMinutes = json.optString("workMinutes", "0"),
            hourlyRate = json.optString("hourlyRate", "0"),
            otherCosts = json.optString("otherCosts", "0"),
            markupPercent = json.optString("markupPercent", "0"),
            materialCosts = json.optString("materialCosts", "0.00"),
            laborCosts = json.optString("laborCosts", "0.00"),
            totalCosts = json.optString("totalCosts", "0.00"),
            recommendedSalePrice = json.optString("recommendedSalePrice", "0.00"),
            createdAtMillis = json.optLong("createdAtMillis"),
        )
    }

    private fun encodeImage(image: ProductImage): JSONObject {
        return JSONObject()
            .put("localPath", image.localPath)
            .put("width", image.width)
            .put("height", image.height)
            .put("alt", image.alt)
            .put("isMain", image.isMain)
            .put("imageId", image.imageId)
            .put("serverImageId", image.serverImageId)
            .put("serverFileName", image.serverFileName)
            .put("serverIsMain", image.serverIsMain)
            .put("uploadedAtVersion", image.uploadedAtVersion)
    }

    private fun decodeImage(json: JSONObject): ProductImage {
        return ProductImage(
            localPath = json.getString("localPath"),
            width = json.optInt("width", 0),
            height = json.optInt("height", 0),
            alt = json.optString("alt"),
            isMain = json.optBoolean("isMain"),
            imageId = json.optStringOrNull("imageId") ?: java.util.UUID.randomUUID().toString(),
            serverImageId = json.optStringOrNull("serverImageId"),
            serverFileName = json.optStringOrNull("serverFileName"),
            serverIsMain = json.optBooleanOrNull("serverIsMain"),
            uploadedAtVersion = json.optIntOrNull("uploadedAtVersion"),
        )
    }

    private fun compressImage(
        resolver: ContentResolver,
        uri: Uri,
        target: File,
    ): CompressedImage {
        val orientation = resolver.openInputStream(uri)?.use { input ->
            runCatching { ExifInterface(input).getAttributeInt(
                ExifInterface.TAG_ORIENTATION,
                ExifInterface.ORIENTATION_NORMAL,
            ) }.getOrDefault(ExifInterface.ORIENTATION_NORMAL)
        } ?: ExifInterface.ORIENTATION_NORMAL
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
        val sampleSize = calculateSampleSize(bounds.outWidth, bounds.outHeight)
        val options = BitmapFactory.Options().apply { inSampleSize = sampleSize }
        val decoded = resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, options) }
            ?: error("Bild konnte nicht gelesen werden.")
        val oriented = decoded.applyOrientation(orientation)
        val scaled = oriented.scaleToMax(MAX_IMAGE_EDGE)
        val bytes = compressToLimit(scaled)

        target.writeBytes(bytes)

        if (oriented !== decoded) decoded.recycle()
        if (scaled !== oriented) oriented.recycle()

        return CompressedImage(target, scaled.width, scaled.height)
    }

    private fun calculateSampleSize(width: Int, height: Int): Int {
        var sample = 1
        var currentWidth = width
        var currentHeight = height

        while (currentWidth / 2 >= MAX_IMAGE_EDGE && currentHeight / 2 >= MAX_IMAGE_EDGE) {
            sample *= 2
            currentWidth /= 2
            currentHeight /= 2
        }

        return sample.coerceAtLeast(1)
    }

    private fun Bitmap.applyOrientation(orientation: Int): Bitmap {
        val matrix = Matrix()
        when (orientation) {
            ExifInterface.ORIENTATION_ROTATE_90 -> matrix.postRotate(90f)
            ExifInterface.ORIENTATION_ROTATE_180 -> matrix.postRotate(180f)
            ExifInterface.ORIENTATION_ROTATE_270 -> matrix.postRotate(270f)
            ExifInterface.ORIENTATION_FLIP_HORIZONTAL -> matrix.preScale(-1f, 1f)
            ExifInterface.ORIENTATION_FLIP_VERTICAL -> matrix.preScale(1f, -1f)
            else -> return this
        }

        return Bitmap.createBitmap(this, 0, 0, width, height, matrix, true)
    }

    private fun Bitmap.scaleToMax(maxEdge: Int): Bitmap {
        val largest = maxOf(width, height)
        if (largest <= maxEdge) return this
        val ratio = maxEdge.toFloat() / largest.toFloat()
        return Bitmap.createScaledBitmap(this, (width * ratio).toInt(), (height * ratio).toInt(), true)
    }

    private fun compressToLimit(bitmap: Bitmap): ByteArray {
        var quality = 88
        var bytes: ByteArray

        do {
            val output = ByteArrayOutputStream()
            bitmap.compress(Bitmap.CompressFormat.JPEG, quality, output)
            bytes = output.toByteArray()
            quality -= 8
        } while (bytes.size > MAX_IMAGE_BYTES && quality >= 60)

        require(bytes.size <= MAX_IMAGE_BYTES) {
            "Bild ist nach der Komprimierung noch zu groß."
        }

        return bytes
    }

    private data class CompressedImage(
        val file: File,
        val width: Int,
        val height: Int,
    )

    companion object {
        private const val MAX_IMAGES = 5
        private const val MAX_IMAGE_EDGE = 1600
        private const val MAX_IMAGE_BYTES = 1_048_576
    }
}

private fun File.isInside(directory: File): Boolean {
    val directoryPath = directory.canonicalFile.toPath()
    return canonicalFile.toPath().startsWith(directoryPath)
}

private fun JSONObject.optStringList(name: String): List<String> {
    val array = optJSONArray(name) ?: return emptyList()
    return buildList {
        for (index in 0 until array.length()) {
            val value = array.optString(index).trim()
            if (value.isNotEmpty()) add(value)
        }
    }
}

private fun JSONObject.optStringOrNull(name: String): String? {
    if (!has(name) || isNull(name)) return null
    return optString(name).takeIf { it.isNotBlank() }
}

private fun JSONObject.optBooleanOrNull(name: String): Boolean? {
    if (!has(name) || isNull(name)) return null
    return getBoolean(name)
}

private fun JSONObject.optIntOrNull(name: String): Int? {
    if (!has(name) || isNull(name)) return null
    return getInt(name)
}
