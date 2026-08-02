package de.carmajaperlen.armbandrechner

import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ProductSynchronizerTest {
    @Test
    fun saveVersionZeroIsPersistedBeforeFirstImageUpload() = runTest {
        val draft = productDraft(imageCount = 1)
        val api = FakeSynchronizationApi(draft)
        val persisted = mutableListOf<ProductDraft>()

        val result = synchronizer(api, persisted).synchronize(draft)

        assertEquals(listOf(0), api.saveExpectedVersions)
        assertEquals(listOf(1), api.uploadExpectedVersions)
        assertEquals(2, result.version)
        assertEquals(1, persisted.first().version)
        assertEquals("CP-TEST", persisted.first().sku)
        assertEquals("test-produkt", persisted.first().slug)
        assertEquals(ProductStatus.Ready, persisted.first().status)
        assertEquals("2026-07-27T20:00:01Z", persisted.first().serverUpdatedAt)
    }

    @Test
    fun multipleImagesUploadSequentiallyWithLatestServerVersion() = runTest {
        val draft = productDraft(imageCount = 3)
        val api = FakeSynchronizationApi(draft)
        val persisted = mutableListOf<ProductDraft>()

        val result = synchronizer(api, persisted).synchronize(draft)

        assertEquals(listOf(1, 2, 3), api.uploadExpectedVersions)
        assertEquals(draft.images.map(ProductImage::imageId), api.uploadedImageIds)
        assertEquals(4, result.version)
        assertTrue(result.images.all(ProductImage::isUploaded))
        assertEquals(listOf("01.jpg", "02.jpg", "03.jpg"), result.images.map { it.serverFileName })
        assertEquals(true, result.images.first().serverIsMain)
        assertEquals(4, result.images.last().uploadedAtVersion)
        assertEquals(1, api.getCalls)
    }

    @Test
    fun successfulSaveDoesNotContinueWithOldSnapshot() = runTest {
        val draft = productDraft(imageCount = 2)
        val api = FakeSynchronizationApi(draft)

        synchronizer(api).synchronize(draft.copy(version = 0))

        assertEquals(1, api.uploadExpectedVersions.first())
        assertFalse(api.uploadExpectedVersions.contains(0))
    }

    @Test
    fun realSecondDeviceConflictIsNotAutomaticallyOverwritten() = runTest {
        val draft = productDraft(imageCount = 1)
        val api = FakeSynchronizationApi(draft).apply {
            externalConflictOnImageId = draft.images.single().imageId
        }

        val error = expectThrows<ProductSynchronizationConflictException> {
            synchronizer(api).synchronize(draft)
        }

        assertEquals(1, api.uploadExpectedVersions.size)
        assertEquals("Extern geändert", error.serverUpdate.name)
        assertEquals("Testprodukt", error.localDraft.name)
        assertEquals(2, error.serverUpdate.version)
    }

    @Test
    fun immediatelySelfCausedConflictRetriesOnlyOnce() = runTest {
        val draft = productDraft(imageCount = 1)
        val api = FakeSynchronizationApi(draft).apply {
            selfConflictOnImageId = draft.images.single().imageId
        }

        val result = synchronizer(api).synchronize(draft)

        assertEquals(listOf(1, 2), api.uploadExpectedVersions)
        assertEquals(3, result.version)
        assertTrue(result.images.single().isUploaded)
    }

    @Test
    fun repeatedConflictStopsAfterControlledRetry() = runTest {
        val draft = productDraft(imageCount = 1)
        val api = FakeSynchronizationApi(draft).apply {
            selfConflictOnImageId = draft.images.single().imageId
            conflictAgainOnRetry = true
        }

        expectThrows<ProductSynchronizationConflictException> {
            synchronizer(api).synchronize(draft)
        }
        assertEquals(2, api.uploadExpectedVersions.size)
    }

    @Test
    fun failedImagePreventsSuccessAndRetrySkipsConfirmedImage() = runTest {
        val draft = productDraft(imageCount = 2)
        val api = FakeSynchronizationApi(draft).apply {
            failOnceOnImageId = draft.images[1].imageId
        }
        val persisted = mutableListOf<ProductDraft>()
        val synchronizer = synchronizer(api, persisted)

        expectThrows<ProductApiException> {
            synchronizer.synchronize(draft)
        }

        val partial = persisted.last()
        assertTrue(partial.images[0].isUploaded)
        assertFalse(partial.images[1].isUploaded)

        val result = synchronizer.synchronize(partial)

        assertEquals(1, api.uploadedImageIds.count { it == draft.images[0].imageId })
        assertEquals(2, api.uploadAttempts.count { it == draft.images[1].imageId })
        assertTrue(result.images.all(ProductImage::isUploaded))
    }

    private fun synchronizer(
        api: FakeSynchronizationApi,
        persisted: MutableList<ProductDraft> = mutableListOf(),
    ): ProductSynchronizer {
        return ProductSynchronizer(
            api = api,
            persist = { draft ->
                draft.also(persisted::add)
            },
        )
    }
}

private suspend inline fun <reified T : Throwable> expectThrows(
    crossinline block: suspend () -> Unit,
): T {
    try {
        block()
    } catch (error: Throwable) {
        if (error is T) return error
        throw AssertionError("Unerwarteter Fehlertyp: ${error::class.java.name}", error)
    }
    throw AssertionError("Erwarteter Fehlertyp wurde nicht ausgelöst: ${T::class.java.name}")
}

private class FakeSynchronizationApi(
    initialDraft: ProductDraft,
) : ProductSynchronizationApi {
    val saveExpectedVersions = mutableListOf<Int>()
    val uploadExpectedVersions = mutableListOf<Int>()
    val uploadAttempts = mutableListOf<String>()
    val uploadedImageIds = mutableListOf<String>()
    var getCalls = 0
    var externalConflictOnImageId: String? = null
    var selfConflictOnImageId: String? = null
    var conflictAgainOnRetry = false
    var failOnceOnImageId: String? = null

    private var selfConflictTriggered = false
    private var failureTriggered = false
    private var server = initialDraft.toServerUpdate(version = 0)

    override suspend fun saveDraft(draft: ProductDraft): ProductServerUpdate {
        saveExpectedVersions += draft.version
        requireVersion(draft.version)
        server = draft.toServerUpdate(
            version = server.version + 1,
            images = server.images,
        )
        return server
    }

    override suspend fun getDraft(draftId: String): ProductServerUpdate {
        getCalls += 1
        assertEquals(server.draftId, draftId)
        return server
    }

    override suspend fun uploadImage(
        draft: ProductDraft,
        image: ProductImage,
        desiredImageIds: List<String>,
    ): ProductServerUpdate {
        uploadExpectedVersions += draft.version
        uploadAttempts += image.imageId

        if (image.imageId == externalConflictOnImageId) {
            externalConflictOnImageId = null
            server = server.copy(
                version = server.version + 1,
                name = "Extern geändert",
                updatedAt = "2026-07-27T20:00:02Z",
            )
            throw conflict(server.version)
        }

        if (image.imageId == selfConflictOnImageId &&
            (!selfConflictTriggered || conflictAgainOnRetry)
        ) {
            selfConflictTriggered = true
            server = server.copy(
                version = server.version + 1,
                updatedAt = "2026-07-27T20:00:02Z",
            )
            throw conflict(server.version)
        }

        if (image.imageId == failOnceOnImageId && !failureTriggered) {
            failureTriggered = true
            throw ProductApiException(
                503,
                "image_upload_failed",
                message = "Testfehler beim Bild-Upload.",
            )
        }

        requireVersion(draft.version)
        val confirmations = (server.images + ProductServerImage(
            imageId = image.imageId,
            fileName = "%02d.jpg".format(desiredImageIds.indexOf(image.imageId) + 1),
            isMain = desiredImageIds.first() == image.imageId,
        )).associateBy(ProductServerImage::imageId)
        server = server.copy(
            version = server.version + 1,
            updatedAt = "2026-07-27T20:00:0${server.version + 1}Z",
            images = desiredImageIds.mapNotNull(confirmations::get),
        )
        uploadedImageIds += image.imageId
        return server
    }

    private fun requireVersion(expectedVersion: Int) {
        if (expectedVersion != server.version) {
            throw conflict(server.version)
        }
    }

    private fun conflict(currentVersion: Int): ProductConflictException {
        return ProductConflictException(
            errorCode = "version_conflict",
            fields = mapOf(
                "currentVersion" to currentVersion.toString(),
                "updatedAt" to server.updatedAt.orEmpty(),
            ),
            message = "Der Entwurf wurde bereits geändert.",
        )
    }
}

private fun productDraft(imageCount: Int): ProductDraft {
    return ProductDraft(
        draftId = "019fa2e6-cf3c-7073-9275-7d3b566f54ee",
        version = 0,
        name = "Testprodukt",
        materials = listOf("Rosenquarz"),
        braceletSizeCm = "17",
        pearlSizeMm = "6",
        shortDescription = "Testbeschreibung",
        internalCalculation = CalculationSnapshot(
            quantities = mapOf("Rosenquarz" to 1),
            workMinutes = "10",
            hourlyRate = "20",
            otherCosts = "0",
            markupPercent = "20",
            materialCosts = "0.15",
            laborCosts = "3.33",
            totalCosts = "3.48",
            recommendedSalePrice = "4.18",
            createdAtMillis = 1L,
        ),
        images = (0 until imageCount).map { index ->
            ProductImage(
                localPath = "image-$index.jpg",
                width = 1200,
                height = 900,
                alt = "Bild ${index + 1}",
                isMain = index == 0,
                imageId = "00000000-0000-4000-8000-%012d".format(index + 1),
            )
        },
        createdAtMillis = 1L,
        updatedAtMillis = 1L,
    )
}

private fun ProductDraft.toServerUpdate(
    version: Int,
    images: List<ProductServerImage> = emptyList(),
): ProductServerUpdate {
    return ProductServerUpdate(
        draftId = draftId,
        version = version,
        sku = "CP-TEST",
        slug = "test-produkt",
        status = ProductStatus.Ready,
        updatedAt = "2026-07-27T20:00:01Z",
        name = name,
        materials = materials,
        metalElements = metalElements,
        braceletSizeCm = braceletSizeCm,
        pearlSizeMm = pearlSizeMm,
        shortDescription = shortDescription,
        careInstructions = careInstructions,
        images = images,
    )
}
