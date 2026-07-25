package de.steinhart.armbandrechner

import java.math.BigDecimal
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class PriceCalculatorTest {
    @Test
    fun calculatesAllCostPartsAndProfit() {
        val totals = PriceCalculator.calculate(
            prices = listOf(
                PriceItem("A", BigDecimal("0.15")),
                PriceItem("B", BigDecimal("1.25")),
            ),
            values = CalculatorValues(
                quantities = mapOf("A" to 3, "B" to 2),
                workMinutes = "90",
                hourlyRate = "24",
                otherCosts = "3,20",
                markupPercent = "25",
            ),
        )

        assertDecimalEquals("2.95", totals.materialCosts)
        assertDecimalEquals("36.00", totals.laborCosts)
        assertDecimalEquals("3.20", totals.otherCosts)
        assertDecimalEquals("42.15", totals.totalCosts)
        assertDecimalEquals("10.5375", totals.profit)
        assertDecimalEquals("52.69", totals.exactSalePrice)
        assertDecimalEquals("53.00", totals.recommendedSalePrice)
    }

    @Test
    fun roundsExactPriceToCentsHalfUp() {
        val totals = PriceCalculator.calculate(
            prices = emptyList(),
            values = CalculatorValues(otherCosts = "1.005"),
        )

        assertDecimalEquals("1.01", totals.exactSalePrice)
    }

    @Test
    fun recommendationRoundsUpToNextFiftyCents() {
        val belowStep = PriceCalculator.calculate(
            prices = emptyList(),
            values = CalculatorValues(otherCosts = "10.01"),
        )
        val exactStep = PriceCalculator.calculate(
            prices = emptyList(),
            values = CalculatorValues(otherCosts = "10.50"),
        )

        assertDecimalEquals("10.50", belowStep.recommendedSalePrice)
        assertDecimalEquals("10.50", exactStep.recommendedSalePrice)
    }

    @Test
    fun resetKeepsRateMarkupAndPriceSettings() {
        val reset = CalculatorValues(
            quantities = mapOf("Rosenquarz" to 4),
            workMinutes = "30",
            hourlyRate = "28,50",
            otherCosts = "2",
            markupPercent = "40",
        ).resetForNewCalculation()

        assertEquals(emptyMap<String, Int>(), reset.quantities)
        assertEquals("0", reset.workMinutes)
        assertEquals("0", reset.otherCosts)
        assertEquals("28,50", reset.hourlyRate)
        assertEquals("40", reset.markupPercent)
    }

    @Test
    fun numericInputAcceptsCommaAndPointButRejectsNegativeOrInvalidValues() {
        assertTrue(NumericInput.isAcceptable("12,50"))
        assertTrue(NumericInput.isAcceptable("12.50"))
        assertTrue(NumericInput.isAcceptable(""))
        assertFalse(NumericInput.isAcceptable("-1"))
        assertFalse(NumericInput.isAcceptable("12x"))
        assertDecimalEquals("12.50", NumericInput.parse("12,50"))
    }

    private fun assertDecimalEquals(expected: String, actual: BigDecimal) {
        assertEquals(0, BigDecimal(expected).compareTo(actual))
    }
}

