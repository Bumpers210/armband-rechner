package de.carmajaperlen.armbandrechner

import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.assertTextEquals
import androidx.compose.ui.test.assertWidthIsAtLeast
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.unit.dp
import java.math.BigDecimal
import java.text.NumberFormat
import java.util.Locale
import org.junit.Rule
import org.junit.Test

class ArmbandCalculatorScreenTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun plusMinusUpdateQuantityAndMeetTouchTarget() {
        setCalculatorContent()

        composeRule.onNodeWithTag("plus-Testperle")
            .assertWidthIsAtLeast(48.dp)
            .assertHeightIsAtLeast(48.dp)
            .performClick()
        composeRule.onNodeWithTag("quantity-Testperle").assertTextEquals("1")

        composeRule.onNodeWithTag("minus-Testperle").performClick()
        composeRule.onNodeWithTag("quantity-Testperle").assertTextEquals("0")
    }

    @Test
    fun inputFieldsImmediatelyUpdateRecommendedPrice() {
        setCalculatorContent()

        enterValue("work-minutes", "60")
        enterValue("hourly-rate", "20")
        enterValue("other-costs", "1")
        enterValue("markup-percent", "50")

        val expected = NumberFormat.getCurrencyInstance(Locale.GERMANY)
            .format(BigDecimal("31.50"))
        composeRule.onNodeWithTag("recommended-price")
            .performScrollTo()
            .assertTextEquals(expected)
    }

    private fun enterValue(tag: String, value: String) {
        val field = composeRule.onNodeWithTag(tag)
        field.performScrollTo()
        field.performTextClearance()
        field.performTextInput(value)
    }

    private fun setCalculatorContent() {
        composeRule.setContent {
            var state by remember {
                mutableStateOf(
                    AppUiState(
                        prices = listOf(PriceItem("Testperle", BigDecimal("2.00"))),
                        loadingStoredData = false,
                    ),
                )
            }
            MaterialTheme {
                ArmbandCalculatorScreen(
                    state = state,
                    onRefresh = {},
                    onQuantityChange = { name, delta ->
                        val old = state.calculator.quantities[name] ?: 0
                        val updated = (old + delta).coerceAtLeast(0)
                        val quantities = state.calculator.quantities.toMutableMap().apply {
                            if (updated == 0) remove(name) else put(name, updated)
                        }
                        state = state.copy(
                            calculator = state.calculator.copy(quantities = quantities),
                        )
                    },
                    onWorkMinutesChange = { value ->
                        state = state.copy(
                            calculator = state.calculator.copy(workMinutes = value),
                        )
                    },
                    onHourlyRateChange = { value ->
                        state = state.copy(
                            calculator = state.calculator.copy(hourlyRate = value),
                        )
                    },
                    onOtherCostsChange = { value ->
                        state = state.copy(
                            calculator = state.calculator.copy(otherCosts = value),
                        )
                    },
                    onMarkupChange = { value ->
                        state = state.copy(
                            calculator = state.calculator.copy(markupPercent = value),
                        )
                    },
                    onNewCalculation = {},
                    onNoticeShown = {},
                )
            }
        }
    }
}
