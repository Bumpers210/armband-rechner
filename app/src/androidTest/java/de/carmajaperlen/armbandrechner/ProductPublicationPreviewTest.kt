package de.carmajaperlen.armbandrechner

import androidx.compose.material3.MaterialTheme
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import java.util.concurrent.atomic.AtomicInteger
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test

class ProductPublicationPreviewTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun previewShowsCompleteFormattedDraftBeforePublishing() {
        val publishCalls = AtomicInteger(0)
        val backCalls = AtomicInteger(0)
        composeRule.setContent {
            MaterialTheme {
                ProductPublicationPreviewScreen(
                    draft = draftWithFormattedDescription(),
                    environmentLabel = "TESTUMGEBUNG",
                    busy = false,
                    onBack = { backCalls.incrementAndGet() },
                    onPublish = { publishCalls.incrementAndGet() },
                )
            }
        }

        composeRule.onNodeWithTag("publication-preview-label").assertIsDisplayed()
        composeRule.onNodeWithText("TESTUMGEBUNG").assertIsDisplayed()
        composeRule.onNodeWithText("Rosenquarz Armband").assertIsDisplayed()
        composeRule.onNodeWithText("Nicht verfügbar").assertIsDisplayed()
        composeRule.onNodeWithTag("publication-preview-description").assertExists()
        composeRule.onNodeWithText("MATERIALIEN").assertExists()
        composeRule.onNodeWithText("Rosenquarz").assertExists()
        composeRule.onNodeWithText("METALLELEMENTE").assertExists()
        composeRule.onNodeWithText("Keine").assertExists()
        composeRule.onNodeWithText("17,5 cm").assertExists()
        composeRule.onNodeWithText("8 mm").assertExists()
        composeRule.onNodeWithTag("publication-preview-care-link").assertExists()
        assertEquals(0, publishCalls.get())

        composeRule.onNodeWithTag("publication-preview-back").performScrollTo().performClick()
        assertEquals(1, backCalls.get())
        assertEquals(0, publishCalls.get())

        composeRule.onNodeWithTag("publication-preview-publish").performScrollTo().performClick()
        assertEquals(1, publishCalls.get())
    }

    @Test
    fun previewAlsoSupportsLegacyPlainDescription() {
        composeRule.setContent {
            MaterialTheme {
                ProductPublicationPreviewScreen(
                    draft = draftWithFormattedDescription().copy(
                        shortDescription = "Alter erster Absatz\n\nAlter zweiter Absatz",
                        descriptionDocument = null,
                        modelVersion = 2,
                    ),
                    environmentLabel = "TESTUMGEBUNG",
                    busy = false,
                    onBack = {},
                    onPublish = {},
                )
            }
        }

        composeRule.onNodeWithText("Alter erster Absatz\n\nAlter zweiter Absatz")
            .assertExists()
    }

    private fun draftWithFormattedDescription(): ProductDraft = ProductDraft(
        draftId = "019fa2e6-cf3c-7073-9275-7d3b566f54ee",
        name = "Rosenquarz Armband",
        materials = listOf("Rosenquarz"),
        braceletSizeCm = "17.5",
        pearlSizeMm = "8",
        shortDescription = "Elegant und sicher.",
        descriptionDocument = DescriptionDocument(
            blocks = listOf(
                DescriptionParagraph(
                    spans = listOf(
                        DescriptionSpan(
                            text = "Elegant",
                            style = DescriptionTextStyle(
                                bold = true,
                                italic = true,
                                font = DescriptionFont.Elegant,
                                size = DescriptionSize.Large,
                            ),
                        ),
                        DescriptionSpan(" und sicher."),
                    ),
                ),
            ),
        ),
        priceMinor = 2490,
        internalCalculation = CalculationSnapshot(
            quantities = emptyMap(),
            workMinutes = "0",
            hourlyRate = "0",
            otherCosts = "0",
            markupPercent = "0",
            materialCosts = "0.00",
            laborCosts = "0.00",
            totalCosts = "0.00",
            recommendedSalePrice = "24.90",
            createdAtMillis = 1L,
        ),
        createdAtMillis = 1L,
        updatedAtMillis = 1L,
    )
}
