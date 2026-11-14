package de.carmajaperlen.armbandrechner

import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createComposeRule
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

class ProductConflictDialogTest {
    @get:Rule val composeRule = createComposeRule()

    @Test fun cancelAndChoiceRequireExplicitActionsAndBusyPreventsDoubleClick() {
        var canceled = 0
        val resolutions = mutableListOf<ProductConflictChoice>()
        val busy = mutableStateOf(false)
        composeRule.setContent {
            MaterialTheme {
                ProductConflictDialog(review(), busy.value, ProductUiActions(
                    onDismissConflict = { canceled++ },
                    onResolveConflict = { resolutions += it; busy.value = true },
                ))
            }
        }
        composeRule.onNodeWithTag("conflict-confirm").assertIsNotEnabled()
        composeRule.onNodeWithTag("conflict-cancel").performClick()
        assertEquals(1, canceled)
        assertTrue(resolutions.isEmpty())
        composeRule.onNodeWithTag("conflict-choose-local").performScrollTo().performClick()
        assertTrue(resolutions.isEmpty())
        composeRule.onNodeWithTag("conflict-confirm").performClick()
        assertEquals(listOf(ProductConflictChoice.KeepLocal), resolutions)
        composeRule.onNodeWithTag("conflict-confirm").assertIsNotEnabled()
        composeRule.onNodeWithTag("conflict-cancel").assertIsNotEnabled()
    }

    @Test fun newServerRevisionClearsPreviousChoice() {
        val comparison = mutableStateOf(review())
        composeRule.setContent { MaterialTheme { ProductConflictDialog(comparison.value, false, ProductUiActions()) } }
        composeRule.onNodeWithText("Amazonit: lokal Produktversion 7, Server Produktversion 8.").assertExists()
        composeRule.onNodeWithTag("conflict-choose-server").performScrollTo().performClick()
        composeRule.onNodeWithTag("conflict-confirm").assertIsEnabled()
        composeRule.runOnIdle { comparison.value = comparison.value.copy(server = comparison.value.server.copy(productVersion = 9)) }
        composeRule.onNodeWithTag("conflict-confirm").assertIsNotEnabled()
    }

    private fun review(): ProductConflictReview {
        val local = ProductDraft(
            draftId = "test-product", name = "Amazonit", version = 70, productVersion = 7, priceMinor = 2800,
            internalCalculation = CalculationSnapshot(emptyMap(), "0", "0", "0", "0", "0", "0", "0", "28", 1),
            createdAtMillis = 1, updatedAtMillis = 1,
        )
        val server = ProductServerUpdate(
            draftId = local.draftId, version = 90, productVersion = 8, sourceHash = "a".repeat(64), sku = null, slug = null,
            status = ProductStatus.Ready, updatedAt = null, name = "Amazonit Server", materials = listOf("Amazonit"),
            metalElements = emptyList(), braceletSizeCm = "18", pearlSizeMm = "8", shortDescription = "Servertext",
            careInstructions = emptyList(), priceMinor = 3250, currency = "eur", available = false, images = emptyList(),
        )
        return ProductConflictReview(local, server)
    }
}
