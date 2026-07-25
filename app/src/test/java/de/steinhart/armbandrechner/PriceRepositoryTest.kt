package de.steinhart.armbandrechner

import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import java.io.File
import java.io.IOException
import java.math.BigDecimal
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder

class PriceRepositoryTest {
    @get:Rule
    val temporaryFolder = TemporaryFolder()

    @Test
    fun failedRefreshNeverOverwritesValidCache() = runTest {
        val prices = listOf(PriceItem("Rosenquarz", BigDecimal("0.15")))
        var failure: Throwable? = null
        val source = PriceListSource {
            failure?.let { throw it }
            prices
        }
        val store = PreferenceDataStoreFactory.create(
            scope = backgroundScope,
            produceFile = { File(temporaryFolder.root, "cache.preferences_pb") },
        )
        val repository = PriceRepository(store, source) { 1_234L }
        repository.refreshPrices()

        listOf(
            IOException("offline"),
            PriceListFormatException("ungültig"),
            IllegalStateException("unerwartet"),
        ).forEach { error ->
            failure = error
            assertThrows(error.javaClass) {
                kotlinx.coroutines.test.runTest {
                    repository.refreshPrices()
                }
            }
            val stored = repository.loadStoredState()
            assertEquals(prices, stored.prices)
            assertEquals(1_234L, stored.lastSyncMillis)
        }
    }

    @Test
    fun persistsCalculatorValuesAndQuantities() = runTest {
        val store = PreferenceDataStoreFactory.create(
            scope = backgroundScope,
            produceFile = { File(temporaryFolder.root, "settings.preferences_pb") },
        )
        val repository = PriceRepository(store, PriceListSource { emptyList() })
        val values = CalculatorValues(
            quantities = mapOf("Rosenquarz" to 7),
            workMinutes = "42",
            hourlyRate = "27,50",
            otherCosts = "3.25",
            markupPercent = "35",
        )

        repository.saveCalculator(values)

        assertEquals(values, repository.loadStoredState().calculatorValues)
    }
}
