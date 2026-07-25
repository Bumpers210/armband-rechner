package de.steinhart.armbandrechner

import android.content.Context
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import androidx.lifecycle.viewModelScope
import java.io.IOException
import java.util.Locale
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class AppUiState(
    val prices: List<PriceItem> = emptyList(),
    val calculator: CalculatorValues = CalculatorValues(),
    val lastSyncMillis: Long? = null,
    val loadingStoredData: Boolean = true,
    val refreshing: Boolean = false,
    val refreshError: String? = null,
    val notice: String? = null,
) {
    val totals: CalculatorTotals
        get() = PriceCalculator.calculate(prices, calculator)
}

class CalculatorViewModel(
    private val repository: PriceRepository,
) : ViewModel() {
    private val _uiState = MutableStateFlow(AppUiState())
    val uiState: StateFlow<AppUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch {
            val stored = repository.loadStoredState()
            val restoredQuantities = remapQuantities(
                oldQuantities = stored.calculatorValues.quantities,
                newPrices = stored.prices,
            )
            _uiState.value = AppUiState(
                prices = stored.prices,
                calculator = stored.calculatorValues.copy(quantities = restoredQuantities),
                lastSyncMillis = stored.lastSyncMillis,
                loadingStoredData = false,
            )
            refreshPriceList()
        }
    }

    fun refreshPriceList() {
        if (_uiState.value.refreshing) return
        _uiState.value = _uiState.value.copy(refreshing = true, refreshError = null)
        viewModelScope.launch {
            runCatching { repository.refreshPrices() }
                .onSuccess { refreshed ->
                    val oldQuantities = _uiState.value.calculator.quantities
                    val remappedQuantities = remapQuantities(oldQuantities, refreshed.prices)
                    val activeNames = refreshed.prices
                        .map { it.name.lowercase(Locale.ROOT) }
                        .toSet()
                    val removedCount = oldQuantities.count { (name, quantity) ->
                        quantity > 0 && name.lowercase(Locale.ROOT) !in activeNames
                    }
                    val calculator = _uiState.value.calculator.copy(
                        quantities = remappedQuantities,
                    )
                    _uiState.value = _uiState.value.copy(
                        prices = refreshed.prices,
                        calculator = calculator,
                        lastSyncMillis = refreshed.syncMillis,
                        refreshing = false,
                        refreshError = null,
                        notice = if (removedCount > 0) {
                            if (removedCount == 1) {
                                "Eine nicht mehr aktive Perle wurde entfernt."
                            } else {
                                "$removedCount nicht mehr aktive Perlen wurden entfernt."
                            }
                        } else {
                            null
                        },
                    )
                    if (calculator.quantities != oldQuantities) {
                        persistCalculator(calculator)
                    }
                }
                .onFailure { error ->
                    _uiState.value = _uiState.value.copy(
                        refreshing = false,
                        refreshError = readableError(error),
                    )
                }
        }
    }

    fun changeQuantity(name: String, delta: Int) {
        val current = _uiState.value
        if (current.prices.none { it.name == name }) return
        val oldQuantity = current.calculator.quantities[name] ?: 0
        val newQuantity = (oldQuantity + delta).coerceIn(0, MAX_QUANTITY)
        if (newQuantity == oldQuantity) return
        val quantities = current.calculator.quantities.toMutableMap().apply {
            if (newQuantity == 0) remove(name) else put(name, newQuantity)
        }
        updateCalculator(current.calculator.copy(quantities = quantities))
    }

    fun updateWorkMinutes(value: String) = updateNumericValue(value) {
        copy(workMinutes = it)
    }

    fun updateHourlyRate(value: String) = updateNumericValue(value) {
        copy(hourlyRate = it)
    }

    fun updateOtherCosts(value: String) = updateNumericValue(value) {
        copy(otherCosts = it)
    }

    fun updateMarkup(value: String) = updateNumericValue(value) {
        copy(markupPercent = it)
    }

    fun newCalculation() {
        val calculator = _uiState.value.calculator.resetForNewCalculation()
        _uiState.value = _uiState.value.copy(
            calculator = calculator,
            notice = "Neue Kalkulation gestartet.",
        )
        persistCalculator(calculator)
    }

    fun consumeNotice() {
        if (_uiState.value.notice != null) {
            _uiState.value = _uiState.value.copy(notice = null)
        }
    }

    private fun updateNumericValue(
        value: String,
        transform: CalculatorValues.(String) -> CalculatorValues,
    ) {
        if (!NumericInput.isAcceptable(value)) return
        updateCalculator(_uiState.value.calculator.transform(value))
    }

    private fun updateCalculator(calculator: CalculatorValues) {
        _uiState.value = _uiState.value.copy(calculator = calculator)
        persistCalculator(calculator)
    }

    private fun persistCalculator(calculator: CalculatorValues) {
        viewModelScope.launch {
            repository.saveCalculator(calculator)
        }
    }

    private fun remapQuantities(
        oldQuantities: Map<String, Int>,
        newPrices: List<PriceItem>,
    ): Map<String, Int> {
        val normalizedOld = oldQuantities.entries.associateBy {
            it.key.lowercase(Locale.ROOT)
        }
        return buildMap {
            newPrices.forEach { item ->
                val quantity = normalizedOld[item.name.lowercase(Locale.ROOT)]?.value ?: 0
                if (quantity > 0) put(item.name, quantity)
            }
        }
    }

    private fun readableError(error: Throwable): String {
        return when (error) {
            is PriceListFormatException -> error.message ?: "Ungültige Tabellenantwort"
            is IOException -> "Keine Verbindung zur Preisliste"
            else -> "Preisliste konnte nicht aktualisiert werden"
        }
    }

    companion object {
        private const val MAX_QUANTITY = 9_999

        fun factory(context: Context): ViewModelProvider.Factory = viewModelFactory {
            initializer {
                CalculatorViewModel(PriceRepository.create(context.applicationContext))
            }
        }
    }
}
