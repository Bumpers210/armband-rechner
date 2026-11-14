package de.carmajaperlen.armbandrechner

import java.io.IOException
import kotlinx.coroutines.runBlocking
import org.junit.Assert.*
import org.junit.Test

class ProductConflictResolutionTest {
    @Test
    fun twoDevicesCannotOverwriteEachOtherAndExplicitLocalChoiceRequiresAnotherSave() = runBlocking {
        val server = SharedServer(fixture().asServer())
        val a = Device(server, fixture().copy(name = "Amazonit Gerät A", priceMinor = 3250))
        val b = Device(server, fixture().copy(name = "Amazonit Gerät B", priceMinor = 2800))
        a.sync()
        val conflict = b.conflict()
        assertEquals(8, conflict.serverUpdate.productVersion)
        assertEquals(81, conflict.serverUpdate.version) // NOT the product version.
        assertEquals(7, b.local.productVersion)
        assertEquals("Amazonit Gerät A", server.value.name)
        val writesBefore = server.acceptedWrites
        val oldLocal = b.local
        b.resolve(conflict, ProductConflictChoice.KeepLocal)
        assertEquals(oldLocal, b.backups.single())
        assertEquals(8, b.local.productVersion)
        assertEquals("Amazonit Gerät B", b.local.name)
        assertEquals(2800, b.local.priceMinor)
        assertEquals(oldLocal.images, b.local.images) // No images in fixture; image preservation tested below.
        assertNull(b.local.pendingV2SaveOperationId)
        assertNull(b.local.pendingPublishOperationId)
        assertEquals(writesBefore, server.acceptedWrites)
        assertEquals("Amazonit Gerät A", server.value.name)
        b.sync() // Separate, explicit user action.
        assertEquals("Amazonit Gerät B", server.value.name)
        assertEquals(9, server.value.productVersion)
        assertEquals(1, b.backups.size)
        assertEquals(listOf(7, 7, 8), server.expectedVersions)
    }

    @Test
    fun serverChoiceKeepsLocalRecoveryCopyAndDoesNotWriteToServer() = runBlocking {
        val server = SharedServer(fixture().copy(name = "Server", productVersion = 8).asServer())
        val b = Device(server, fixture())
        val conflict = b.conflict()
        val before = b.local
        b.resolve(conflict, ProductConflictChoice.LoadServer)
        assertEquals(before, b.backups.single())
        assertEquals("Server", b.local.name)
        assertEquals(8, b.local.productVersion)
        assertEquals(before.internalCalculation, b.local.internalCalculation)
        assertEquals(0, server.acceptedWrites)
    }

    @Test
    fun anotherDeviceSavingWhileComparisonIsOpenRequiresNewDecision() = runBlocking {
        val server = SharedServer(fixture().copy(productVersion = 8).asServer())
        val b = Device(server, fixture())
        val conflict = b.conflict()
        val before = b.local
        server.value = server.value.copy(productVersion = 9, sourceHash = "c".repeat(64), name = "Dritte Fassung")
        try {
            b.resolve(conflict, ProductConflictChoice.KeepLocal)
            fail("Stale review must not be accepted")
        } catch (updated: ProductSynchronizationConflictException) {
            assertEquals(9, updated.serverUpdate.productVersion)
        }
        assertEquals(before, b.local)
        assertTrue(b.backups.isEmpty())
        assertEquals(0, server.acceptedWrites)
    }

    @Test
    fun sameProductVersionButChangedImageDraftOrHashAlsoInvalidatesReview() = runBlocking {
        for (change in listOf<(ProductServerUpdate) -> ProductServerUpdate>(
            { it.copy(version = it.version + 1) },
            { it.copy(sourceHash = "f".repeat(64)) },
            { it.copy(images = listOf(ProductServerImage("new-image", "01.jpg", true))) },
        )) {
            val server = SharedServer(fixture().copy(productVersion = 8).asServer())
            val b = Device(server, fixture())
            val conflict = b.conflict()
            val before = b.local
            server.value = change(server.value)
            try { b.resolve(conflict, ProductConflictChoice.LoadServer); fail("Must reject") }
            catch (_: ProductSynchronizationConflictException) { }
            assertEquals(before, b.local)
            assertTrue(b.backups.isEmpty())
        }
    }

    @Test
    fun failedBackupOrServerReadLeavesLocalAndServerUnchanged() = runBlocking {
        for (failRead in listOf(false, true)) {
            val local = fixture()
            val server = local.copy(productVersion = 8).asServer()
            var mutations = 0
            val resolver = ProductConflictResolver(
                readServer = { if (failRead) throw IOException("offline") else server },
                preserveLocal = { throw IOException("disk full") },
                loadServerLocally = { _, _ -> mutations++; local },
                saveLocal = { mutations++; it },
            )
            try { resolver.resolve(ProductConflictReview(local, server), ProductConflictChoice.LoadServer); fail("Must stop") }
            catch (_: IOException) { }
            assertEquals(0, mutations)
        }
    }

    @Test
    fun explicitRebasePreservesAllLocalEditableFieldsAndInventoryIntentButNoStaleOperations() {
        val local = fixture().copy(
            images = listOf(ProductImage("private-local.jpg", 3200, 2000, "Mein Bild", true, imageId = "local-image")),
            pendingV2SaveOperationId = "old-save", pendingPublishOperationId = "old-publish",
            pendingArchiveOperationId = "old-archive", pendingRestoreOperationId = "old-restore",
            pendingInitialOnHand = 3, pendingInitialInventoryOperationId = "inventory-intent",
        )
        val server = local.copy(productVersion = 8, version = 99, name = "Andere Fassung", priceMinor = 9900,
            salesEnabled = true, images = emptyList(), notices = listOf("Serverhinweis")).asServer()
        val rebased = local.rebaseAfterExplicitChoice(server)
        assertEquals(local.toSaveJson().apply { put("expectedProductVersion", 8) }.toString(), rebased.toSaveJson().toString())
        assertEquals(local.internalCalculation, rebased.internalCalculation)
        assertEquals(local.images.single().localPath, rebased.images.single().localPath)
        assertFalse(rebased.images.single().isUploaded)
        assertEquals(server.notices, rebased.notices)
        assertEquals(3, rebased.pendingInitialOnHand)
        assertEquals("inventory-intent", rebased.pendingInitialInventoryOperationId)
        assertNull(rebased.pendingV2SaveOperationId)
        assertNull(rebased.pendingPublishOperationId)
        assertNull(rebased.pendingArchiveOperationId)
        assertNull(rebased.pendingRestoreOperationId)
    }

    @Test
    fun concurrentChangeAfterSaveDoesNotSilentlyReplaceLocalFields() = runBlocking {
        val server = SharedServer(fixture().asServer())
        server.changeBeforeRead = true
        val b = Device(server, fixture())
        val conflict = b.conflict()
        assertEquals("Amazonit", b.local.name)
        assertEquals("Concurrent edit", conflict.serverUpdate.name)
        assertEquals(8, b.local.productVersion)
        assertEquals(9, server.value.productVersion)
    }

    @Test
    fun conflictsAreNotMisreportedAsNetworkFailures() {
        val message = publicationFailureMessage("HTTP 409 product_version_conflict")
        assertFalse(message.contains("prüfe die Verbindung"))
        assertTrue(message.contains("vergleichen"))
        assertTrue(publicationFailureMessage("Keine Verbindung").contains("Ausgang ist nicht bestätigt"))
    }

    @Test
    fun confirmedImageDoesNotSilentlyAcceptAnotherDevicesPriceChange() = runBlocking {
        val local = fixture().copy(images = listOf(ProductImage("local.jpg", 24, 24, "Foto", true, imageId = "image")))
        var stored = local
        var server = local.asServer().copy(productVersion = 8, version = 81, images = emptyList())
        val api = object : ProductSynchronizationApi {
            override suspend fun saveDraft(draft: ProductDraft) = server
            override suspend fun getDraft(draftId: String) = server
            override suspend fun uploadImage(draft: ProductDraft, image: ProductImage, desiredImageIds: List<String>): ProductServerUpdate {
                server = server.copy(productVersion = 9, version = 82, priceMinor = 9900,
                    images = listOf(ProductServerImage(image.imageId, "01.jpg", true)))
                throw ProductConflictException("version_conflict", mapOf("currentVersion" to "82"), "Conflict")
            }
        }
        try {
            ProductSynchronizer(api, { stored = it; it }).synchronize(local)
            fail("Foreign metadata must remain an explicit conflict even if the image exists")
        } catch (conflict: ProductSynchronizationConflictException) {
            assertEquals(9900, conflict.serverUpdate.priceMinor)
            assertEquals(2800, conflict.localDraft.priceMinor)
        }
        assertEquals(2800, stored.priceMinor)
        assertEquals(8, stored.productVersion)
        assertFalse(stored.images.single().isUploaded)
    }

    private class Device(val server: SharedServer, var local: ProductDraft) {
        val backups = mutableListOf<ProductDraft>()
        suspend fun sync() { local = ProductSynchronizer(server, { local = it; it }).synchronize(local) }
        suspend fun conflict(): ProductSynchronizationConflictException {
            try { sync(); error("Expected conflict") }
            catch (failure: ProductSynchronizationConflictException) { return failure }
        }
        suspend fun resolve(conflict: ProductSynchronizationConflictException, choice: ProductConflictChoice) {
            local = ProductConflictResolver(
                readServer = { server.getDraft(it) },
                preserveLocal = { backups += it },
                loadServerLocally = { old, remote -> old.rebaseAfterExplicitChoice(remote).copy(name = remote.name, priceMinor = remote.priceMinor) },
                saveLocal = { local = it; it },
            ).resolve(ProductConflictReview(conflict.localDraft, conflict.serverUpdate), choice)
        }
    }

    private class SharedServer(var value: ProductServerUpdate) : ProductSynchronizationApi {
        var acceptedWrites = 0
        var changeBeforeRead = false
        val expectedVersions = mutableListOf<Int>()
        override suspend fun saveDraft(draft: ProductDraft): ProductServerUpdate {
            expectedVersions += draft.productVersion
            if (draft.productVersion != value.productVersion) throw ProductConflictException(
                "product_version_conflict", mapOf("currentProductVersion" to value.productVersion.toString()), "Neuere Fassung vorhanden.")
            acceptedWrites++
            value = draft.asServer().copy(productVersion = value.productVersion + 1,
                version = value.version + 1, sourceHash = acceptedWrites.toString().padStart(64, '0'))
            return value
        }
        override suspend fun getDraft(draftId: String): ProductServerUpdate {
            if (changeBeforeRead) {
                changeBeforeRead = false
                value = value.copy(productVersion = value.productVersion + 1, name = "Concurrent edit")
            }
            return value
        }
        override suspend fun uploadImage(draft: ProductDraft, image: ProductImage, desiredImageIds: List<String>): ProductServerUpdate =
            error("No upload expected")
    }
}

private fun fixture() = ProductDraft(
    draftId = "a0b82735-7db3-4e92-a1f9-658b77586300", version = 80, productVersion = 7,
    sourceHash = "a".repeat(64), name = "Amazonit", materials = listOf("Amazonit"), metalElements = listOf("Edelstahl"),
    braceletSizeCm = "18", pearlSizeMm = "8", shortDescription = "Lokaler Text",
    descriptionDocument = DescriptionDocument.fromPlainText("Lokaler Text"), priceMinor = 2800, salesEnabled = false,
    careInstructions = listOf("Trocken halten"),
    internalCalculation = CalculationSnapshot(emptyMap(), "10", "12", "1", "30", "5", "2", "8", "28", 1),
    createdAtMillis = 1, updatedAtMillis = 2,
)

private fun ProductDraft.asServer() = ProductServerUpdate(
    draftId, modelVersion, version, productVersion, requireNotNull(sourceHash), sku, slug, status, serverUpdatedAt,
    name, materials, metalElements, braceletSizeCm, pearlSizeMm, shortDescription, descriptionDocument, notices,
    careInstructions, priceMinor, currency, false,
    images.mapIndexed { index, image -> ProductServerImage(image.imageId, "%02d.jpg".format(index + 1), index == 0, image.alt, image.width, image.height) },
    salesEnabled,
)
