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
    val version: Int,
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
    val careInstructions: List<String>,
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
    val currentVersion: Int? = fields["currentVersion"]?.toIntOrNull(),
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
            path = "login",
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
        val response = requestJson(
            baseUrl = baseUrl,
            path = "products/${draft.draftId}",
            method = "PUT",
            token = token,
            body = draft.toSaveJson(),
        )
        return response.getJSONObject("product").toServerUpdate()
    }

    open fun getDraft(baseUrl: String, token: String, draftId: String): ProductServerUpdate {
        val response = requestJson(
            baseUrl = baseUrl,
            path = "products/$draftId",
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
            path = "products/${draft.draftId}/images",
            method = "POST",
            token = token,
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

    fun publish(baseUrl: String, token: String, draft: ProductDraft, operationId: String): PublishResult {
        return postStatus(baseUrl, token, draft, operationId, "publish")
    }

    fun markSold(baseUrl: String, token: String, draft: ProductDraft, operationId: String): PublishResult {
        return postStatus(baseUrl, token, draft, operationId, "sold")
    }

    fun disable(baseUrl: String, token: String, draft: ProductDraft, operationId: String): PublishResult {
        return postStatus(baseUrl, token, draft, operationId, "disable")
    }

    private fun postStatus(
        baseUrl: String,
        token: String,
        draft: ProductDraft,
        operationId: String,
        action: String,
    ): PublishResult {
        val response = requestJson(
            baseUrl = baseUrl,
            path = "products/${draft.draftId}/$action",
            method = "POST",
            token = token,
            body = JSONObject()
                .put("expectedVersion", draft.version)
                .put("operationId", operationId),
        )
        return PublishResult(
            draftId = response.getString("draftId"),
            sku = response.optStringOrNull("sku"),
            version = response.getInt("version"),
            operationId = response.getString("operationId"),
            commitSha = response.optStringOrNull("commitSha"),
            deploymentStatus = response.getString("deploymentStatus"),
            status = ProductStatus.fromWireName(response.optString("status")),
        )
    }

    private fun requestJson(
        baseUrl: String,
        path: String,
        method: String,
        token: String?,
        body: JSONObject?,
    ): JSONObject {
        val connection = openConnection(baseUrl, path, method, token).apply {
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
    ): HttpURLConnection {
        val normalizedBase = requireTestApiBaseUrl(baseUrl).trimEnd('/')
        val connection = (URL("$normalizedBase/$path").openConnection() as HttpURLConnection)
        connection.connectTimeout = 15_000
        connection.readTimeout = 30_000
        connection.requestMethod = method
        connection.doInput = true
        connection.doOutput = method != "GET"
        connection.setRequestProperty("Accept", "application/json")
        connection.setRequestProperty("User-Agent", "Armband-Rechner/1.1.0")
        token?.let { connection.setRequestProperty("Authorization", "Bearer $it") }
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

internal fun requireMatchingPublishTarget(expected: String, actual: String) {
    if (expected != "test" || actual != expected) {
        throw ProductTargetMismatchException(
            "Test-App und API verwenden unterschiedliche Veröffentlichungsziele.",
        )
    }
}

internal fun requireTestApiBaseUrl(value: String): String {
    val normalized = value.trim().trimEnd('/')
    val valid = runCatching {
        val uri = URI(normalized)
        uri.scheme == "https" &&
            uri.host == "test-api.carmaja-perlen.de" &&
            uri.port == -1 &&
            uri.userInfo == null &&
            uri.path.orEmpty().isEmpty() &&
            uri.query == null &&
            uri.fragment == null
    }.getOrDefault(false)

    if (!valid) {
        throw ProductApiException(
            0,
            "test_api_endpoint_required",
            message = "Die Test-App darf ausschließlich die konfigurierte Test-API verwenden.",
        )
    }

    return "$normalized/"
}

internal fun ProductDraft.toSaveJson(): JSONObject {
    val saveStatus = when (status) {
        ProductStatus.Draft -> ProductStatus.Draft
        else -> ProductStatus.Ready
    }
    val payload = JSONObject()
        .put("modelVersion", modelVersion)
        .put("draftId", draftId)
        .put("expectedVersion", version)
        .put("status", saveStatus.wireName)
        .put("name", name)
        .put("materials", JSONArray(materials))
        .put("metalElements", JSONArray(metalElements))
        .put("braceletSizeCm", braceletSizeCm.toBigDecimal())
        .put("pearlSizeMm", pearlSizeMm.toBigDecimal())
        .put("shortDescription", shortDescription)
        .put("internalCalculation", internalCalculation.toJson())

    return payload
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
        draftId = getString("draftId"),
        version = getInt("version"),
        sku = optStringOrNull("sku"),
        slug = optStringOrNull("slug"),
        status = ProductStatus.fromWireName(optString("status", "draft")),
        updatedAt = optStringOrNull("updatedAt"),
        name = optString("name"),
        materials = optStringList("materials"),
        metalElements = optStringList("metalElements"),
        braceletSizeCm = opt("braceletSizeCm")?.toString().orEmpty(),
        pearlSizeMm = opt("pearlSizeMm")?.toString().orEmpty(),
        shortDescription = optString("shortDescription"),
        careInstructions = optStringList("careInstructions"),
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
