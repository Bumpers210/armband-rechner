package de.carmajaperlen.armbandrechner

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ProductPublicationFeedbackTest {
    @Test
    fun localPublicationDoesNotClaimThatTheWebsiteWasDeployed() {
        val message = publicationConfirmation(result(null))
        assertTrue(message.contains("Produktverwaltung veröffentlicht"))
        assertTrue(message.contains("nicht automatisch aktualisiert"))
        assertFalse(message.contains("not_started"))
    }

    @Test
    fun queuedPublicationDoesNotClaimCompletedWebsiteDeployment() {
        val message = publicationConfirmation(result("test-commit"))
        assertTrue(message.contains("angenommen"))
        assertTrue(message.contains("läuft separat"))
        assertFalse(message.contains("test-commit"))
    }

    @Test
    fun previewCannotPublishWhileBusyOrAfterConfirmation() {
        val preview = ProductUiState(publicationPreview = draft())
        assertTrue(preview.canPublishPreview)
        assertFalse(preview.copy(busy = true).canPublishPreview)
        assertFalse(preview.copy(publicationConfirmation = "Bestätigt").canPublishPreview)
        assertFalse(ProductUiState().canPublishPreview)
    }

    @Test
    fun anErrorIsNotAConfirmationAndDoesNotDiscardThePendingOperation() {
        val preview = ProductUiState(publicationPreview = draft().copy(pendingPublishOperationId = "test-operation"))
            .copy(error = "Keine Verbindung zur Produktverwaltung", busy = false)
        assertTrue(preview.canPublishPreview)
        assertTrue(preview.publicationConfirmation == null)
        assertTrue(preview.publicationPreview?.pendingPublishOperationId == "test-operation")
    }

    private fun draft(): ProductDraft = ProductDraft(
        draftId = "019fa2e6-cf3c-7073-9275-7d3b566f54ee",
        internalCalculation = CalculationSnapshot(
            quantities = emptyMap(), workMinutes = "0", hourlyRate = "0", otherCosts = "0",
            markupPercent = "0", materialCosts = "0.00", laborCosts = "0.00", totalCosts = "0.00",
            recommendedSalePrice = "28.00", createdAtMillis = 1L,
        ),
        createdAtMillis = 1L,
        updatedAtMillis = 1L,
    )

    private fun result(commit: String?): PublishResult = PublishResult(
        draftId = draft().draftId, sku = "CP-2026-0001", version = 6,
        operationId = "test-operation", commitSha = commit, deploymentStatus = "not_started",
        status = ProductStatus.Published, productVersion = 6, sourceHash = "a".repeat(64), available = false,
    )
}
