package de.carmajaperlen.armbandrechner

/** A read-only comparison, never permission to write to the server. */
data class ProductConflictReview(val local: ProductDraft, val server: ProductServerUpdate) {
    init {
        require(local.draftId == server.draftId) { "Serverantwort gehört zu einem anderen Artikel." }
    }
}

enum class ProductConflictChoice { LoadServer, KeepLocal }

internal class ProductConflictResolver(
    private val readServer: suspend (String) -> ProductServerUpdate,
    private val preserveLocal: suspend (ProductDraft) -> Unit,
    private val loadServerLocally: suspend (ProductDraft, ProductServerUpdate) -> ProductDraft,
    private val saveLocal: suspend (ProductDraft) -> ProductDraft,
) {
    suspend fun resolve(review: ProductConflictReview, choice: ProductConflictChoice): ProductDraft {
        val latest = readServer(review.local.draftId)
        // Check the entire reviewed response, including image/draft-only changes.
        if (latest != review.server) {
            throw ProductSynchronizationConflictException(
                review.local, latest,
                ProductConflictException("product_version_conflict", emptyMap(),
                    "Der Serverstand hat sich erneut geändert. Bitte die Fassungen erneut vergleichen."),
            )
        }
        // Failure to preserve the complete local draft (including images) aborts resolution.
        preserveLocal(review.local)
        return when (choice) {
            ProductConflictChoice.LoadServer -> loadServerLocally(review.local, latest)
            ProductConflictChoice.KeepLocal -> saveLocal(review.local.rebaseAfterExplicitChoice(latest))
        }
    }
}

internal fun ProductDraft.rebaseAfterExplicitChoice(server: ProductServerUpdate): ProductDraft {
    require(draftId == server.draftId)
    // Retain ALL editable fields and local calculation; only accept server-owned tokens/state.
    return copy(
        version = server.version,
        productVersion = server.productVersion,
        sourceHash = server.sourceHash,
        modelVersion = server.productModelVersion,
        status = server.status,
        sku = server.sku,
        slug = server.slug,
        notices = server.notices,
        serverUpdatedAt = server.updatedAt,
        images = applyServerUpdate(this, server).images,
        pendingV2SaveOperationId = null,
        pendingPublishOperationId = null,
        pendingArchiveOperationId = null,
        pendingRestoreOperationId = null,
    )
}

internal fun publicationFailureMessage(error: String): String =
    if (error.contains("product_version_conflict") || error.contains("source_hash_conflict") ||
        error.contains("version_conflict")) {
        "$error\n\nNicht erneut veröffentlichen: Bitte zuerst die lokale Fassung mit dem Serverstand vergleichen."
    } else {
        "$error\n\nDer Ausgang ist nicht bestätigt. Bitte prüfe die Verbindung oder den Serverstand, bevor du es erneut versuchst."
    }
