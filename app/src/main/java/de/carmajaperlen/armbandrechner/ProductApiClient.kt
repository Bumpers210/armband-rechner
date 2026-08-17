package de.carmajaperlen.armbandrechner

import java.io.File
import java.net.HttpURLConnection
import java.net.URI
import java.net.URL
import java.util.UUID
import org.json.JSONArray
import org.json.JSONObject

data class ProductServerImage(
    val imageId: String,
    val fileName: String,
    val isMain: Boolean,
)

data class ProductServerUpdate(
    val draftId: String,
    val productModelVersion: Int = PRODUCT_MODEL_V3,
    val version: Int,
    val productVersion: Int,
    val sourceHash: String,
    val sku: String?,
    val slug: String?,
    val status: ProductStatus,
    val updatedAt: String?,
    val name: String,
    val materials: List<String>,
    val metalElements: List<String>,
    val braceletSizeCm: String,
    val pearlSizeMm: String,
    val shortDescription: String,
    val descriptionDocument: DescriptionDocument? = null,
    val careInstructions: List<String>,
    val priceMinor: Int,
    val currency: String,
    val available: Boolean,
    val images: List<ProductServerImage>,
)

data class PublishResult(
    val draftId: String,
    val sku: String?,
    val version: Int,
    val operationId: String,
    val commitSha: String?,
    val deploymentStatus: String,
    val status: ProductStatus,
    val productVersion: Int,
    val sourceHash: String,
    val available: Boolean,
)

data class ProductLoginResult(
    val token: String,
    val publishTarget: String,
)

open class ProductApiException(
    val statusCode: Int,
    val errorCode: String,
    val fields: Map<String, String> = emptyMap(),
    message: String,
) : Exception(message)

class ProductConflictException(
    errorCode: String,
    fields: Map<String, String>,
    message: String,
    val currentVersion: Int? = (
        fields["currentVersion"] ?: fields["currentProductVersion"]
    )?.toIntOrNull(),
    val serverUpdatedAt: String? = fields["updatedAt"],
) : ProductApiException(409, errorCode, fields, message)

class ProductTargetMismatchException(message: String) :
    ProductApiException(409, "publish_target_mismatch", message = message)

open class ProductApiClient {
    fun login(
        baseUrl: String,
        username: String,
        password: String,
        deviceName: String,
        expectedPublishTarget: String,
    ): ProductLoginResult {
        val response = requestJson(
            baseUrl = baseUrl,
            path = "v4/login",
            method = "POST",
            token = null,
            body = JSONObject()
                .put("username", username)
                .put("password", password)
                .put("deviceName", deviceName)
                .put("publishTarget", expectedPublishTarget),
        )
        val actualTarget = response.getString("publishTarget")
        requireMatchingPublishTarget(expectedPublishTarget, actualTarget)
        return ProductLoginResult(
            token = response.getString("token"),
            publishTarget = actualTarget,
        )
    }

    open fun saveDraft(baseUrl: String, token: String, draft: ProductDraft): ProductServerUpdate {
        val idempotencyKey = requireNotNull(draft.pendingV2SaveOperationId) {
            "V4-Speichern benötigt eine persistierte Idempotenz-ID."
        }
        val response = requestJson(
            baseUrl = baseUrl,
            path = "v4/products/${draft.draftId}",
            method = "PUT",
            token = token,
            body = draft.toSaveJson(),
            headers = mapOf(
                APP_VERSION_CODE_HEADER to BuildConfig.VERSION_CODE.toString(),
                "Idempotency-Key" to idempotencyKey,
            ),
        )
        return response.getJSONObject("product").toServerUpdate()
    }

    open fun getDraft(baseUrl: String, token: String, draftId: String): ProductServerUpdate {
        val response = requestJson(
            baseUrl = baseUrl,
            path = "v4/products/$draftId",
            method = "GET",
            token = token,
            body = null,
        )
        return response.getJSONObject("product").toServerUpdate()
    }

    open fun uploadImage(
        baseUrl: String,
        token: String,
        draft: ProductDraft,
        image: ProductImage,
        desiredImageIds: List<String>,
    ): ProductServerUpdate {
        val boundary = "CarmajaBoundary${UUID.randomUUID()}"
        val connection = openConnection(
            baseUrl = baseUrl,
            path = "v4/products/${draft.draftId}/images",
            method = "POST",
            token = token,
            headers = mapOf(APP_VERSION_CODE_HEADER to BuildConfig.VERSION_CODE.toString()),
        ).apply {
            setRequestProperty("Content-Type", "multipart/form-data; boundary=$boundary")
        }

        connection.outputStream.use { output ->
            fun writeTextPart(name: String, value: String) {
                output.write("--$boundary\r\n".toByteArray())
                output.write("Content-Disposition: form-data; name=\"$name\"\r\n\r\n".toByteArray())
                output.write(value.toByteArray(Charsets.UTF_8))
                output.write("\r\n".toByteArray())
            }

            writeTextPart("expectedVersion", draft.version.toString())
            writeTextPart("imageId", image.imageId)
            writeTextPart("desiredImageIds", JSONArray(desiredImageIds).toString())
            writeTextPart("alt", image.alt)

            val file = File(image.localPath)
            output.write("--$boundary\r\n".toByteArray())
            output.write(
                "Content-Disposition: form-data; name=\"image\"; filename=\"image.jpg\"\r\n"
                    .toByteArray(),
            )
            output.write("Content-Type: image/jpeg\r\n\r\n".toByteArray())
            file.inputStream().use { it.copyTo(output) }
            output.write("\r\n".toByteArray())
            output.write("--$boundary--\r\n".toByteArray())
        }

        val response = readResponse(connection)
        return response.getJSONObject("product").toServerUpdate()
    }

    open fun publish(baseUrl: String, token: String, draft: ProductDraft, operationId: String): PublishResult {
        return lifecycle(baseUrl, token, draft, operationId, "publish")
    }

    open fun archive(baseUrl: String, token: String, draft: ProductDraft, operationId: String): PublishResult {
        return lifecycle(baseUrl, token, draft, operationId, "archive")
    }

    open fun restore(baseUrl: String, token: String, draft: ProductDraft, operationId: String): PublishResult {
        return lifecycle(baseUrl, token, draft, operationId, "restore")
    }

    private fun lifecycle(
        baseUrl: String,
        token: String,
        draft: ProductDraft,
        operationId: String,
        action: String,
    ): PublishResult {
        val sourceHash = draft.sourceHash
            ?: throw ProductApiException(
                409,
                "source_hash_missing",
                message = "Kollektion muss vor dieser Aktion vollständig synchronisiert sein.",
            )
        val response = requestJson(
            baseUrl = baseUrl,
            path = "v4/products/${draft.draftId}/$action",
            method = "POST",
            token = token,
            body = JSONObject()
                .put("expectedProductVersion", draft.productVersion)
                .put("expectedSourceHash", sourceHash)
                .put("operationId", operationId),
            headers = mapOf(APP_VERSION_CODE_HEADER to BuildConfig.VERSION_CODE.toString()),
        )
        val publication = response.getJSONObject("publication")
        val product = response.getJSONObject("product").toServerUpdate()
        return PublishResult(
            draftId = product.draftId,
            sku = product.sku,
            version = product.version,
            operationId = publication.optString("operationId", operationId),
            commitSha = publication.optStringOrNull("commitSha"),
            deploymentStatus = publication.optString("deploymentStatus", "not_started"),
            status = product.status,
            productVersion = product.productVersion,
            sourceHash = product.sourceHash,
            available = product.available,
        )
    }

    private fun requestJson(
        baseUrl: String,
        path: String,
        method: String,
        token: String?,
        body: JSONObject?,
        headers: Map<String, String> = emptyMap(),
    ): JSONObject {
        val connection = openConnection(baseUrl, path, method, token, headers).apply {
            if (body != null) {
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
            }
        }
        if (body != null) {
            connection.outputStream.use { output ->
                output.write(body.toString().toByteArray(Charsets.UTF_8))
            }
        }
        return readResponse(connection)
    }

    private fun openConnection(
        baseUrl: String,
        path: String,
        method: String,
        token: String?,
        headers: Map<String, String> = emptyMap(),
    ): HttpURLConnection {
        val normalizedBase = requireKnownProductApiBaseUrl(baseUrl).trimEnd('/')
        val connection = (URL("$normalizedBase/$path").openConnection() as HttpURLConnection)
        connection.connectTimeout = 15_000
        connection.readTimeout = 30_000
        connection.requestMethod = method
        connection.doInput = true
        connection.doOutput = method != "GET"
        connection.setRequestProperty("Accept", "application/json")
        connection.setRequestProperty(
            "User-Agent",
            "Carmaja-Perlen-Produktverwaltung/${BuildConfig.VERSION_NAME}",
        )
        token?.let { connection.setRequestProperty("Authorization", "Bearer $it") }
        headers.forEach(connection::setRequestProperty)
        return connection
    }

    private fun readResponse(connection: HttpURLConnection): JSONObject {
        val statusCode = connection.responseCode
        val stream = if (statusCode in 200..299) connection.inputStream else connection.errorStream
        val text = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
        connection.disconnect()

        val json = text.takeIf { it.isNotBlank() }?.let(::JSONObject) ?: JSONObject()

        if (statusCode !in 200..299) {
            val error = json.optJSONObject("error")
            val message = error?.optString("message")
                ?: "Produktserver antwortet mit HTTP $statusCode"
            val errorCode = error?.optString("code").orEmpty().ifBlank { "http_error" }
            val fieldsJson = error?.optJSONObject("fields")
            val fields = fieldsJson?.keys()?.asSequence()?.associateWith {
                fieldsJson.optString(it)
            }.orEmpty()
            if (statusCode == 409) {
                throw ProductConflictException(errorCode, fields, message)
            }
            throw ProductApiException(statusCode, errorCode, fields, message)
        }

        if (!json.optBoolean("ok", false) || !json.has("data")) {
            throw ProductApiException(
                0,
                "invalid_api_response",
                message = "Produktserver liefert eine ungültige Antwort.",
            )
        }

        return json.optJSONObject("data")
            ?: throw ProductApiException(
                0,
                "invalid_api_response",
                message = "Produktserver liefert keine Daten.",
            )
    }
}

data class ProductApiEndpoint(
    val publishTarget: String,
    val baseUrl: String,
) {
    val isTest: Boolean
        get() = publishTarget == "test"

    val environmentLabel: String
        get() = if (isTest) "TESTUMGEBUNG" else "PRODUKTIVUMGEBUNG"

    val host: String
        get() = URI(baseUrl).host.orEmpty()

}

internal fun requireMatchingPublishTarget(expected: String, actual: String) {
    if (expected !in PRODUCT_PUBLISH_TARGETS || actual != expected) {
        throw ProductTargetMismatchException(
            "App und API verwenden unterschiedliche Veröffentlichungsziele.",
        )
    }
}

internal fun requireProductApiEndpoint(baseUrl: String, publishTarget: String): ProductApiEndpoint {
    val expectedHost = when (publishTarget) {
        "test" -> TEST_PRODUCT_API_HOST
        "production" -> PRODUCTION_PRODUCT_API_HOST
        else -> throw ProductApiException(
            0,
            "invalid_publish_target",
            message = "Die Produktverwaltung ist nicht für ein bekanntes Veröffentlichungsziel konfiguriert.",
        )
    }

    val normalized = valueForApiEndpoint(baseUrl)
    val valid = runCatching {
        val uri = URI(normalized)
        uri.scheme == "https" &&
            uri.host == expectedHost &&
            uri.port == -1 &&
            uri.userInfo == null &&
            uri.path.orEmpty().isEmpty() &&
            uri.query == null &&
            uri.fragment == null
    }.getOrDefault(false)

    if (!valid) {
        throw ProductApiException(
            0,
            "product_api_endpoint_required",
            message = "Die Produktverwaltung darf ausschließlich die konfigurierte API verwenden.",
        )
    }

    return ProductApiEndpoint(publishTarget = publishTarget, baseUrl = "$normalized/")
}

internal fun requireKnownProductApiBaseUrl(value: String): String {
    val normalized = valueForApiEndpoint(value)
    val valid = runCatching {
        val uri = URI(normalized)
        uri.scheme == "https" &&
            uri.host in PRODUCT_API_HOSTS &&
            uri.port == -1 &&
            uri.userInfo == null &&
            uri.path.orEmpty().isEmpty() &&
            uri.query == null &&
            uri.fragment == null
    }.getOrDefault(false)

    if (!valid) {
        throw ProductApiException(
            0,
            "product_api_endpoint_required",
            message = "Die Produktverwaltung darf ausschließlich bekannte API-Endpunkte verwenden.",
        )
    }

    return "$normalized/"
}

private fun valueForApiEndpoint(value: String): String = value.trim().trimEnd('/')

private const val TEST_PRODUCT_API_HOST = "test-api.carmaja-perlen.de"
private const val PRODUCTION_PRODUCT_API_HOST = "api.carmaja-perlen.de"
private val PRODUCT_API_HOSTS = setOf(TEST_PRODUCT_API_HOST, PRODUCTION_PRODUCT_API_HOST)
private val PRODUCT_PUBLISH_TARGETS = setOf("test", "production")

internal fun ProductDraft.toSaveJson(): JSONObject {
    val imagesV2 = images.mapIndexed { index, image ->
        ProductImageV2(
            imageId = image.imageId,
            fileName = "%02d.jpg".format(index + 1),
            alt = image.alt.ifBlank { name },
            width = image.width,
            height = image.height,
            isMain = index == 0,
        )
    }
    val document = requireNotNull(descriptionDocument) {
        "Formatierte Beschreibung fehlt."
    }
    return ProductV3Update(
        expectedProductVersion = productVersion,
        name = name,
        descriptionDocument = document,
        materials = materials,
        metalElements = metalElements,
        braceletSizeCm = braceletSizeCm,
        pearlSizeMm = pearlSizeMm,
        careInstructions = careInstructions,
        images = imagesV2,
        priceMinor = priceMinor,
        currency = currency,
        salesEnabled = false,
    ).toJson().apply {
        remove("salesEnabled")
    }
}

private fun CalculationSnapshot.toJson(): JSONObject {
    return JSONObject()
        .put("quantities", JSONObject(quantities))
        .put("workMinutes", workMinutes)
        .put("hourlyRate", hourlyRate)
        .put("otherCosts", otherCosts)
        .put("markupPercent", markupPercent)
        .put("materialCosts", materialCosts)
        .put("laborCosts", laborCosts)
        .put("totalCosts", totalCosts)
        .put("recommendedSalePrice", recommendedSalePrice)
        .put("createdAtMillis", createdAtMillis)
}

private fun JSONObject.toServerUpdate(): ProductServerUpdate {
    val serverImages = optJSONArray("images")?.let { array ->
        buildList {
            for (index in 0 until array.length()) {
                val image = array.optJSONObject(index) ?: continue
                val imageId = image.optStringOrNull("imageId") ?: continue
                val fileName = image.optStringOrNull("fileName") ?: continue
                add(
                    ProductServerImage(
                        imageId = imageId,
                        fileName = fileName,
                        isMain = image.optBoolean("isMain", index == 0),
                    ),
                )
            }
        }
    }.orEmpty()

    return ProductServerUpdate(
        draftId = optString("draftId").ifBlank { getString("productId") },
        productModelVersion = optInt("productModelVersion", 2),
        version = optInt("version", 0),
        productVersion = getInt("productVersion"),
        sourceHash = getString("sourceHash"),
        sku = optStringOrNull("sku"),
        slug = optStringOrNull("slug"),
        status = ProductStatus.fromWireName(optString("status", "draft")),
        updatedAt = optStringOrNull("updatedAt"),
        name = optString("name"),
        materials = optStringList("materials"),
        metalElements = optStringList("metalElements"),
        braceletSizeCm = opt("braceletSizeCm")?.toString().orEmpty(),
        pearlSizeMm = opt("pearlSizeMm")?.toString().orEmpty(),
        shortDescription = optString("description").ifBlank { optString("shortDescription") },
        descriptionDocument = optJSONObject("descriptionDocument")
            ?.let(DescriptionDocument::fromJson),
        careInstructions = optStringList("careInstructions"),
        priceMinor = getInt("priceMinor"),
        currency = getString("currency"),
        available = optBoolean("available", optString("status", "draft") == "published"),
        images = serverImages,
    )
}

private fun JSONObject.optStringList(name: String): List<String> {
    val values = optJSONArray(name) ?: return emptyList()
    return buildList {
        for (index in 0 until values.length()) {
            values.optString(index).takeIf { it.isNotBlank() }?.let(::add)
        }
    }
}

private fun JSONObject.optStringOrNull(name: String): String? {
    if (!has(name) || isNull(name)) return null
    return optString(name).takeIf { it.isNotBlank() }
}
