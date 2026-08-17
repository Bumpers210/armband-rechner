package de.carmajaperlen.armbandrechner

internal interface ProductSynchronizationApi {
    suspend fun saveDraft(draft: ProductDraft): ProductServerUpdate

    suspend fun getDraft(draftId: String): ProductServerUpdate

    suspend fun uploadImage(
        draft: ProductDraft,
        image: ProductImage,
        desiredImageIds: List<String>,
    ): ProductServerUpdate
}

data class ProductSyncConflictState(
    val draftId: String,
    val localVersion: Int,
    val serverVersion: Int?,
    val serverUpdatedAt: String?,
    val errorCode: String,
    val message: String,
)

class ProductSynchronizationConflictException(
    val localDraft: ProductDraft,
    val serverUpdate: ProductServerUpdate,
    conflict: ProductConflictException,
) : ProductApiException(
    statusCode = 409,
    errorCode = conflict.errorCode,
    fields = conflict.fields,
    message = conflict.message ?: "Der Serverstand wurde zwischenzeitlich geändert.",
) {
    fun toState(): ProductSyncConflictState {
        return ProductSyncConflictState(
            draftId = localDraft.draftId,
            localVersion = localDraft.productVersion,
            serverVersion = serverUpdate.productVersion,
            serverUpdatedAt = serverUpdate.updatedAt,
            errorCode = errorCode,
            message = message ?: "Versionskonflikt",
        )
    }
}

internal class ProductSynchronizer(
    private val api: ProductSynchronizationApi,
    private val persist: suspend (ProductDraft) -> ProductDraft,
    private val operationIdFactory: () -> String = { java.util.UUID.randomUUID().toString() },
) {
    suspend fun synchronize(draft: ProductDraft): ProductDraft {
        var current = saveMetadata(draft)
        val desiredImageIds = current.images.map(ProductImage::imageId)

        for (imageId in desiredImageIds) {
            val image = current.images.first { it.imageId == imageId }
            if (image.isUploaded) continue
            current = uploadPendingImage(current, image, desiredImageIds)
        }

        val verified = api.getDraft(current.draftId)
        current = persist(applyServerUpdate(current, verified))
        verifyComplete(current, verified, desiredImageIds)
        return current
    }

    private suspend fun saveMetadata(draft: ProductDraft): ProductDraft {
        val prepared = if (draft.pendingV2SaveOperationId == null) {
            persist(draft.copy(pendingV2SaveOperationId = operationIdFactory()))
        } else {
            draft
        }
        return try {
            persist(applyServerUpdate(prepared, api.saveDraft(prepared)))
        } catch (conflict: ProductConflictException) {
            throw conflictWithServer(prepared, conflict)
        }
    }

    private suspend fun uploadPendingImage(
        draft: ProductDraft,
        image: ProductImage,
        desiredImageIds: List<String>,
    ): ProductDraft {
        return try {
            persist(
                applyServerUpdate(
                    draft,
                    api.uploadImage(draft, image, desiredImageIds),
                ),
            )
        } catch (conflict: ProductConflictException) {
            val server = api.getDraft(draft.draftId)

            if (server.images.any { it.imageId == image.imageId }) {
                return persist(applyServerUpdate(draft, server))
            }

            if (!isImmediatelySelfCaused(draft, server, conflict)) {
                throw ProductSynchronizationConflictException(draft, server, conflict)
            }

            val refreshed = persist(applyServerUpdate(draft, server))
            val refreshedImage = refreshed.images.first { it.imageId == image.imageId }

            try {
                persist(
                    applyServerUpdate(
                        refreshed,
                        api.uploadImage(refreshed, refreshedImage, desiredImageIds),
                    ),
                )
            } catch (retryConflict: ProductConflictException) {
                throw conflictWithServer(refreshed, retryConflict)
            }
        }
    }

    private suspend fun conflictWithServer(
        draft: ProductDraft,
        conflict: ProductConflictException,
    ): ProductSynchronizationConflictException {
        return ProductSynchronizationConflictException(
            localDraft = draft,
            serverUpdate = api.getDraft(draft.draftId),
            conflict = conflict,
        )
    }

    private fun isImmediatelySelfCaused(
        local: ProductDraft,
        server: ProductServerUpdate,
        conflict: ProductConflictException,
    ): Boolean {
        val confirmedLocalIds = local.images
            .filter(ProductImage::isUploaded)
            .map(ProductImage::imageId)
            .toSet()
        val serverIds = server.images.map(ProductServerImage::imageId).toSet()

        return conflict.currentVersion == local.version + 1 &&
            server.version == conflict.currentVersion &&
            server.matchesMetadata(local) &&
            serverIds == confirmedLocalIds
    }

    private fun verifyComplete(
        local: ProductDraft,
        server: ProductServerUpdate,
        desiredImageIds: List<String>,
    ) {
        val serverIds = server.images.map(ProductServerImage::imageId)
        val complete = serverIds == desiredImageIds &&
            local.images.size == server.images.size &&
            local.images.all(ProductImage::isUploaded)

        if (!complete) {
            throw ProductApiException(
                statusCode = 409,
                errorCode = "image_sync_incomplete",
                fields = mapOf(
                    "localImageCount" to local.images.size.toString(),
                    "serverImageCount" to server.images.size.toString(),
                ),
                message = "Die Bildsynchronisierung ist unvollständig.",
            )
        }
    }
}

internal fun applyServerUpdate(
    draft: ProductDraft,
    update: ProductServerUpdate,
): ProductDraft {
    require(update.draftId == draft.draftId) {
        "Serverantwort gehört zu einem anderen Produktentwurf."
    }
    val confirmations = update.images.associateBy(ProductServerImage::imageId)
    val images = draft.images.map { image ->
        val confirmation = confirmations[image.imageId]
        if (confirmation == null) {
            image.copy(
                serverImageId = null,
                serverFileName = null,
                serverIsMain = null,
                uploadedAtVersion = null,
            )
        } else {
            image.copy(
                serverImageId = confirmation.imageId,
                serverFileName = confirmation.fileName,
                serverIsMain = confirmation.isMain,
                uploadedAtVersion = update.version,
            )
        }
    }

    return draft.copy(
        modelVersion = update.productModelVersion,
        version = update.version,
        productVersion = update.productVersion,
        sourceHash = update.sourceHash,
        sku = update.sku ?: draft.sku,
        slug = update.slug ?: draft.slug,
        status = update.status,
        serverUpdatedAt = update.updatedAt ?: draft.serverUpdatedAt,
        priceMinor = update.priceMinor,
        currency = update.currency,
        salesEnabled = update.salesEnabled,
        shortDescription = update.shortDescription,
        descriptionDocument = update.descriptionDocument ?: draft.descriptionDocument,
        pendingV2SaveOperationId = null,
        images = images,
    )
}

private fun ProductServerUpdate.matchesMetadata(draft: ProductDraft): Boolean {
    return name == draft.name &&
        materials == draft.materials &&
        metalElements == draft.metalElements &&
        braceletSizeCm == draft.braceletSizeCm &&
        pearlSizeMm == draft.pearlSizeMm &&
        shortDescription == draft.shortDescription &&
        descriptionDocument == draft.descriptionDocument &&
        careInstructions == draft.careInstructions
        && priceMinor == draft.priceMinor
        && currency == draft.currency
        && salesEnabled == draft.salesEnabled
}
