package de.steinhart.armbandrechner

import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID
import org.json.JSONArray
import org.json.JSONObject

data class ProductServerUpdate(
    val version: Int,
    val sku: String?,
    val slug: String?,
    val status: ProductStatus,
)

data class PublishResult(
    val draftId: String,
    val sku: String?,
    val version: Int,
    val operationId: String,
    val commitSha: String,
    val deploymentStatus: String,
    val status: ProductStatus,
)

open class ProductApiException(
    val statusCode: Int,
    message: String,
) : Exception(message)

class ProductConflictException(message: String) : ProductApiException(409, message)

class ProductApiClient {
    fun login(
        baseUrl: String,
        username: String,
        password: String,
        deviceName: String,
    ): String {
        val response = requestJson(
            baseUrl = baseUrl,
            path = "login",
            method = "POST",
            token = null,
            body = JSONObject()
                .put("username", username)
                .put("password", password)
                .put("deviceName", deviceName),
        )
        return response.getString("token")
    }

    fun saveDraft(baseUrl: String, token: String, draft: ProductDraft): ProductServerUpdate {
        val response = requestJson(
            baseUrl = baseUrl,
            path = "products/${draft.draftId}",
            method = "PUT",
            token = token,
            body = draft.toSaveJson(),
        )
        return response.getJSONObject("product").toServerUpdate()
    }

    fun uploadImages(baseUrl: String, token: String, draft: ProductDraft): ProductServerUpdate {
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
            draft.images.forEach { image ->
                writeTextPart("alt[]", image.alt)
            }
            draft.images.forEachIndexed { index, image ->
                val file = File(image.localPath)
                output.write("--$boundary\r\n".toByteArray())
                output.write(
                    "Content-Disposition: form-data; name=\"images[]\"; filename=\"${index + 1}.jpg\"\r\n"
                        .toByteArray(),
                )
                output.write("Content-Type: image/jpeg\r\n\r\n".toByteArray())
                file.inputStream().use { it.copyTo(output) }
                output.write("\r\n".toByteArray())
            }
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
            commitSha = response.getString("commitSha"),
            deploymentStatus = response.getString("deploymentStatus"),
            status = ProductStatus.fromWireName(response.optString("status")),
        )
    }

    private fun requestJson(
        baseUrl: String,
        path: String,
        method: String,
        token: String?,
        body: JSONObject,
    ): JSONObject {
        val connection = openConnection(baseUrl, path, method, token).apply {
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
        }
        connection.outputStream.use { output ->
            output.write(body.toString().toByteArray(Charsets.UTF_8))
        }
        return readResponse(connection)
    }

    private fun openConnection(
        baseUrl: String,
        path: String,
        method: String,
        token: String?,
    ): HttpURLConnection {
        val normalizedBase = baseUrl.trim().trimEnd('/')
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
            val message = json.optJSONObject("error")?.optString("message")
                ?: "Produktserver antwortet mit HTTP $statusCode"
            if (statusCode == 409) {
                throw ProductConflictException(message)
            }
            throw ProductApiException(statusCode, message)
        }

        return json
    }
}

private fun ProductDraft.toSaveJson(): JSONObject {
    val saveStatus = when (status) {
        ProductStatus.Draft -> ProductStatus.Draft
        else -> ProductStatus.Ready
    }
    return JSONObject()
        .put("draftId", draftId)
        .put("expectedVersion", version)
        .put("status", saveStatus.wireName)
        .put("name", name)
        .put("materials", JSONArray(materials))
        .put("metalElements", JSONArray(metalElements))
        .put("braceletSize", braceletSize)
        .put("stock", stock)
        .put("shortDescription", shortDescription)
        .put("careInstructions", JSONArray(careInstructions))
        .put("vintedUrl", vintedUrl)
        .put("internalCalculation", internalCalculation.toJson())
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
    return ProductServerUpdate(
        version = getInt("version"),
        sku = optStringOrNull("sku"),
        slug = optStringOrNull("slug"),
        status = ProductStatus.fromWireName(optString("status", "draft")),
    )
}

private fun JSONObject.optStringOrNull(name: String): String? {
    if (!has(name) || isNull(name)) return null
    return optString(name).takeIf { it.isNotBlank() }
}
