package de.steinhart.armbandrechner

import java.math.BigDecimal
import java.math.RoundingMode

data class PriceItem(
    val name: String,
    val unitPrice: BigDecimal,
)

data class CalculatorValues(
    val quantities: Map<String, Int> = emptyMap(),
    val workMinutes: String = "0",
    val hourlyRate: String = "0",
    val otherCosts: String = "0",
    val markupPercent: String = "0",
)

fun CalculatorValues.resetForNewCalculation(): CalculatorValues {
    return copy(
        quantities = emptyMap(),
        workMinutes = "0",
        otherCosts = "0",
    )
}

data class CalculatorTotals(
    val materialCosts: BigDecimal,
    val laborCosts: BigDecimal,
    val otherCosts: BigDecimal,
    val totalCosts: BigDecimal,
    val profit: BigDecimal,
    val exactSalePrice: BigDecimal,
    val recommendedSalePrice: BigDecimal,
)

object PriceCalculator {
    private val zero = BigDecimal.ZERO
    private val sixty = BigDecimal("60")
    private val hundred = BigDecimal("100")
    private val recommendationStep = BigDecimal("0.50")

    fun calculate(
        prices: List<PriceItem>,
        values: CalculatorValues,
    ): CalculatorTotals {
        val material = prices.fold(zero) { sum, item ->
            val quantity = values.quantities[item.name] ?: 0
            sum + item.unitPrice.multiply(BigDecimal(quantity))
        }
        val minutes = NumericInput.parse(values.workMinutes)
        val hourlyRate = NumericInput.parse(values.hourlyRate)
        val other = NumericInput.parse(values.otherCosts)
        val markup = NumericInput.parse(values.markupPercent)
        val labor = minutes
            .divide(sixty, 10, RoundingMode.HALF_UP)
            .multiply(hourlyRate)
        val total = material + labor + other
        val profit = total.multiply(markup).divide(hundred, 10, RoundingMode.HALF_UP)
        val exact = total.add(profit).setScale(2, RoundingMode.HALF_UP)
        val recommended = exact
            .divide(recommendationStep, 0, RoundingMode.CEILING)
            .multiply(recommendationStep)
            .setScale(2, RoundingMode.UNNECESSARY)

        return CalculatorTotals(
            materialCosts = material,
            laborCosts = labor,
            otherCosts = other,
            totalCosts = total,
            profit = profit,
            exactSalePrice = exact,
            recommendedSalePrice = recommended,
        )
    }
}

object NumericInput {
    private val validPattern = Regex("""\d*(?:[.,]\d*)?""")

    fun isAcceptable(value: String): Boolean {
        return value.length <= 12 && (value.isEmpty() || validPattern.matches(value))
    }

    fun parse(value: String): BigDecimal {
        val normalized = value.replace(',', '.')
        if (normalized.isBlank() || normalized == ".") return BigDecimal.ZERO
        return normalized.toBigDecimalOrNull()?.takeIf { it.signum() >= 0 } ?: BigDecimal.ZERO
    }
}
