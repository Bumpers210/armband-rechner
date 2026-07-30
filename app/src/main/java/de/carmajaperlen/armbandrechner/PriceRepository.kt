package de.carmajaperlen.armbandrechner

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import java.io.IOException
import java.math.BigDecimal
import java.net.HttpURLConnection
import java.net.URL
import java.util.Locale
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject

private val Context.appDataStore by preferencesDataStore(name = "armband_rechner")

data class StoredAppState(
    val prices: List<PriceItem>,
    val lastSyncMillis: Long?,
    val calculatorValues: CalculatorValues,
)

data class PriceRefresh(
    val prices: List<PriceItem>,
    val syncMillis: Long,
)

fun interface PriceListSource {
    suspend fun load(): List<PriceItem>
}

class GvizPriceListSource : PriceListSource {
    override suspend fun load(): List<PriceItem> = withContext(Dispatchers.IO) {
        val connection = (URL(SPREADSHEET_URL).openConnection() as HttpURLConnection).apply {
            connectTimeout = 15_000
            readTimeout = 15_000
            requestMethod = "GET"
            setRequestProperty("Accept", "application/json, text/javascript")
            setRequestProperty("User-Agent", "Armband-Rechner/1.0.0")
        }

        try {
            val responseCode = connection.responseCode
            if (responseCode !in 200..299) {
                throw IOException("HTTP-Status $responseCode")
            }
            val body = connection.inputStream.bufferedReader(Charsets.UTF_8).use { reader ->
                val text = reader.readText()
                if (text.length > MAX_RESPONSE_CHARACTERS) {
                    throw IOException("Die Tabellenantwort ist zu groß.")
                }
                text
            }
            GvizPriceListParser.parse(body)
        } finally {
            connection.disconnect()
        }
    }

    private companion object {
        const val MAX_RESPONSE_CHARACTERS = 1_000_000
        const val SPREADSHEET_URL =
            "https://docs.google.com/spreadsheets/d/" +
                "1PsiIr5pjKYPQIP0WxP3JPMn5y_sdWaM_hRT6tzzOIrU/gviz/tq" +
                "?sheet=Preisliste&range=A1%3AC&headers=1" +
                "&tq=select%20A%2CB%2CC%20where%20C%20%3D%20true&tqx=out%3Ajson"
    }
}

object GvizPriceListParser {
    private const val RESPONSE_PREFIX = "google.visualization.Query.setResponse("
    private val expectedLabels = listOf("Name", "Preis pro Stück", "Aktiv")
    private val expectedTypes = listOf("string", "number", "boolean")

    fun parse(response: String): List<PriceItem> {
        try {
            val prefixStart = response.indexOf(RESPONSE_PREFIX)
            val jsonStart = prefixStart + RESPONSE_PREFIX.length
            val jsonEnd = response.lastIndexOf(");")
            if (prefixStart < 0 || jsonEnd <= jsonStart) {
                throw PriceListFormatException("Die Google-Antwort ist beschädigt.")
            }

            val root = JSONObject(response.substring(jsonStart, jsonEnd))
            if (root.optString("status", "ok") != "ok") {
                throw PriceListFormatException("Google hat die Tabellenabfrage abgelehnt.")
            }
            val table = root.getJSONObject("table")
            validateColumns(table.getJSONArray("cols"))

            val seenNames = mutableSetOf<String>()
            val result = mutableListOf<PriceItem>()
            val rows = table.getJSONArray("rows")
            for (index in 0 until rows.length()) {
                val cells = rows.getJSONObject(index).optJSONArray("c")
                    ?: throw PriceListFormatException("Zeile ${index + 2} ist beschädigt.")
                if (cells.length() != 3) {
                    throw PriceListFormatException("Zeile ${index + 2} hat nicht drei Spalten.")
                }

                val nameValue = cellValue(cells, 0)
                val priceValue = cellValue(cells, 1)
                val activeValue = cellValue(cells, 2)
                if (nameValue == null && priceValue == null && activeValue == null) continue
                if (activeValue !is Boolean) {
                    throw PriceListFormatException("Aktiv ist in Zeile ${index + 2} kein Wahrheitswert.")
                }
                if (!activeValue) continue
                if (nameValue !is String || nameValue.isBlank()) {
                    throw PriceListFormatException("Der Name in Zeile ${index + 2} fehlt.")
                }
                if (priceValue !is Number) {
                    throw PriceListFormatException("Der Preis in Zeile ${index + 2} ist ungültig.")
                }

                val name = nameValue.trim()
                val normalizedName = name.lowercase(Locale.ROOT)
                if (!seenNames.add(normalizedName)) {
                    throw PriceListFormatException("Der Name \"$name\" kommt mehrfach vor.")
                }
                val price = priceValue.toString().toBigDecimalOrNull()
                    ?: throw PriceListFormatException("Der Preis für \"$name\" ist ungültig.")
                if (price.signum() < 0) {
                    throw PriceListFormatException("Der Preis für \"$name\" ist negativ.")
                }
                result += PriceItem(name, price)
            }
            return result
        } catch (error: PriceListFormatException) {
            throw error
        } catch (error: JSONException) {
            throw PriceListFormatException("Die Google-Antwort ist beschädigt.", error)
        }
    }

    private fun validateColumns(columns: JSONArray) {
        if (columns.length() != 3) {
            throw PriceListFormatException("Die Preisliste muss genau drei Spalten enthalten.")
        }
        for (index in 0 until 3) {
            val column = columns.getJSONObject(index)
            if (column.optString("label") != expectedLabels[index] ||
                column.optString("type") != expectedTypes[index]
            ) {
                throw PriceListFormatException(
                    "Die Spalten müssen Name | Preis pro Stück | Aktiv heißen.",
                )
            }
        }
    }

    private fun cellValue(cells: JSONArray, index: Int): Any? {
        val cell = cells.opt(index)
        if (cell !is JSONObject || !cell.has("v") || cell.isNull("v")) return null
        return cell.get("v")
    }
}

class PriceListFormatException(
    message: String,
    cause: Throwable? = null,
) : Exception(message, cause)

class PriceRepository(
    private val dataStore: DataStore<Preferences>,
    private val source: PriceListSource,
    private val currentTimeMillis: () -> Long = System::currentTimeMillis,
) {
    suspend fun loadStoredState(): StoredAppState {
        val preferences = dataStore.data.first()
        return StoredAppState(
            prices = decodePrices(preferences[Keys.prices]),
            lastSyncMillis = preferences[Keys.lastSync],
            calculatorValues = CalculatorValues(
                quantities = decodeQuantities(preferences[Keys.quantities]),
                workMinutes = preferences[Keys.workMinutes] ?: "0",
                hourlyRate = preferences[Keys.hourlyRate] ?: "0",
                otherCosts = preferences[Keys.otherCosts] ?: "0",
                markupPercent = preferences[Keys.markup] ?: "0",
            ),
        )
    }

    suspend fun refreshPrices(): PriceRefresh {
        val validatedPrices = source.load()
        val syncMillis = currentTimeMillis()
        val encodedPrices = encodePrices(validatedPrices)

        dataStore.edit { preferences ->
            preferences[Keys.prices] = encodedPrices
            preferences[Keys.lastSync] = syncMillis
        }
        return PriceRefresh(validatedPrices, syncMillis)
    }

    suspend fun saveCalculator(values: CalculatorValues) {
        dataStore.edit { preferences ->
            preferences[Keys.quantities] = encodeQuantities(values.quantities)
            preferences[Keys.workMinutes] = values.workMinutes
            preferences[Keys.hourlyRate] = values.hourlyRate
            preferences[Keys.otherCosts] = values.otherCosts
            preferences[Keys.markup] = values.markupPercent
        }
    }

    private fun encodePrices(prices: List<PriceItem>): String {
        val array = JSONArray()
        prices.forEach { item ->
            array.put(
                JSONObject()
                    .put("name", item.name)
                    .put("price", item.unitPrice.toPlainString()),
            )
        }
        return array.toString()
    }

    private fun decodePrices(encoded: String?): List<PriceItem> {
        if (encoded == null) return emptyList()
        return runCatching {
            val array = JSONArray(encoded)
            buildList {
                for (index in 0 until array.length()) {
                    val entry = array.getJSONObject(index)
                    val name = entry.getString("name")
                    val price = BigDecimal(entry.getString("price"))
                    if (name.isBlank() || price.signum() < 0) {
                        throw JSONException("Ungültiger Cache")
                    }
                    add(PriceItem(name, price))
                }
            }
        }.getOrDefault(emptyList())
    }

    private fun encodeQuantities(quantities: Map<String, Int>): String {
        val objectValue = JSONObject()
        quantities.filterValues { it > 0 }.forEach { (name, quantity) ->
            objectValue.put(name, quantity)
        }
        return objectValue.toString()
    }

    private fun decodeQuantities(encoded: String?): Map<String, Int> {
        if (encoded == null) return emptyMap()
        return runCatching {
            val objectValue = JSONObject(encoded)
            buildMap {
                objectValue.keys().forEach { name ->
                    val quantity = objectValue.getInt(name)
                    if (quantity > 0) put(name, quantity)
                }
            }
        }.getOrDefault(emptyMap())
    }

    private object Keys {
        val prices = stringPreferencesKey("cached_prices")
        val lastSync = longPreferencesKey("last_successful_sync")
        val quantities = stringPreferencesKey("quantities")
        val workMinutes = stringPreferencesKey("work_minutes")
        val hourlyRate = stringPreferencesKey("hourly_rate")
        val otherCosts = stringPreferencesKey("other_costs")
        val markup = stringPreferencesKey("markup_percent")
    }

    companion object {
        fun create(context: Context): PriceRepository {
            return PriceRepository(
                dataStore = context.appDataStore,
                source = GvizPriceListSource(),
            )
        }
    }
}

