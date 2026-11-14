package de.carmajaperlen.armbandrechner

import android.content.Context
import android.graphics.Bitmap
import android.net.Uri
import androidx.compose.ui.text.input.TextFieldValue
import androidx.test.core.app.ApplicationProvider
import java.io.ByteArrayOutputStream
import java.io.File
import java.util.UUID
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class ProductDraftRepositoryTest {
    private lateinit var context: Context
    private lateinit var repository: ProductDraftRepository
    private lateinit var draftId: String
    private lateinit var sourceImage: File

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        repository = ProductDraftRepository(context) { 2L }
        draftId = UUID.randomUUID().toString()
        sourceImage = File(context.cacheDir, "$draftId-source.png")
        val bitmap = Bitmap.createBitmap(24, 24, Bitmap.Config.ARGB_8888)
        try {
            sourceImage.outputStream().use { output ->
                bitmap.compress(Bitmap.CompressFormat.PNG, 100, output)
            }
        } finally {
            bitmap.recycle()
        }
    }

    @After
    fun tearDown() {
        sourceImage.delete()
        File(context.cacheDir, "product-draft-images/$draftId").deleteRecursively()
        File(context.filesDir, "product-drafts/$draftId.json").delete()
        File(context.filesDir, "product-images/$draftId").deleteRecursively()
        File(context.filesDir, "product-images").listFiles()
            ?.filter { it.name.startsWith("$draftId-server-") }?.forEach { it.deleteRecursively() }
        File(context.filesDir, "product-conflicts/$draftId").deleteRecursively()
    }

    @Test
    fun v2MeasurementsSurviveLocalLoadEditSaveAndReload() = runBlocking {
        repository.saveDraft(
            testDraft().copy(
                braceletSizeCm = "17.5",
                pearlSizeMm = "6",
            ),
        )
        val loaded = repository.loadDrafts().single()
        val edited = ProductDraftEditorState.fromDraft(loaded)
            .update(ProductEditorField.BraceletSizeCm, TextFieldValue("18,0"))
            .update(ProductEditorField.PearlSizeMm, TextFieldValue("8,0"))
            .applyTo(loaded)

        repository.saveDraft(edited)
        val reloaded = repository.loadDrafts().single()

        assertEquals("18", reloaded.braceletSizeCm)
        assertEquals("8", reloaded.pearlSizeMm)
    }

    @Test
    fun temporaryImagesAreDiscardedWithoutPersistingNewDraft() = runBlocking {
        val draft = testDraft()

        val withTemporaryImage = repository.storeTemporaryImages(
            draft,
            listOf(Uri.fromFile(sourceImage)),
        )
        val temporaryImage = File(withTemporaryImage.images.single().localPath)

        assertTrue(temporaryImage.isFile)
        assertTrue(temporaryImage.canonicalPath.startsWith(context.cacheDir.canonicalPath))
        assertFalse(File(context.filesDir, "product-drafts/$draftId.json").exists())

        repository.discardTemporaryImages(draftId)

        assertFalse(temporaryImage.exists())
        assertFalse(File(context.filesDir, "product-drafts/$draftId.json").exists())
    }

    @Test
    fun discardingReplacementImagesKeepsExplicitlySavedDraftAndImages() = runBlocking {
        val prepared = repository.storeTemporaryImages(
            testDraft(),
            listOf(Uri.fromFile(sourceImage)),
        )
        val saved = repository.saveDraft(prepared)
        val savedImage = File(saved.images.single().localPath)

        val replacement = repository.storeTemporaryImages(
            saved,
            listOf(Uri.fromFile(sourceImage)),
        )
        val temporaryReplacement = File(replacement.images.single().localPath)
        repository.discardTemporaryImages(draftId)

        assertTrue(savedImage.isFile)
        assertFalse(temporaryReplacement.exists())
        assertTrue(File(context.filesDir, "product-drafts/$draftId.json").isFile)
    }

    @Test
    fun legacyDraftIsMigratedToModelVersionTwoWithoutLegacyFields() = runBlocking {
        val draftFile = File(context.filesDir, "product-drafts/$draftId.json")
        draftFile.parentFile?.mkdirs()
        draftFile.writeText(
            """
            {
              "draftId": "$draftId",
              "version": 3,
              "status": "draft",
              "name": "Alter Entwurf",
              "materials": [],
              "metalElements": [],
              "braceletSize": "17,5 cm",
              "stock": 4,
              "vintedUrl": "https://example.invalid/legacy",
              "shortDescription": "",
              "careInstructions": [],
              "internalCalculation": { "quantities": {} },
              "images": [],
              "createdAtMillis": 1,
              "updatedAtMillis": 1
            }
            """.trimIndent(),
        )

        val migrated = repository.loadDrafts().single()
        val saved = repository.saveDraft(migrated)
        val persisted = JSONObject(draftFile.readText())

        assertEquals(2, saved.modelVersion)
        assertEquals("17.5", saved.braceletSizeCm)
        assertEquals("", saved.pearlSizeMm)
        assertFalse(persisted.has("braceletSize"))
        assertFalse(persisted.has("stock"))
        assertFalse(persisted.has("vintedUrl"))
        assertEquals(2, persisted.getInt("modelVersion"))
    }

    @Test
    fun serverCollectionAndImagesAreImportedWithoutOverwritingLocalDraft() = runBlocking {
        val imageId = UUID.randomUUID().toString()
        val bitmap = Bitmap.createBitmap(24, 16, Bitmap.Config.ARGB_8888)
        val jpeg = try {
            ByteArrayOutputStream().use { output ->
                bitmap.compress(Bitmap.CompressFormat.JPEG, 90, output)
                output.toByteArray()
            }
        } finally {
            bitmap.recycle()
        }
        val update = ProductServerUpdate(
            draftId = draftId,
            version = 7,
            productVersion = 4,
            sourceHash = "a".repeat(64),
            sku = "CP-2026-0001",
            slug = "cp-2026-0001-ares",
            status = ProductStatus.Published,
            updatedAt = "2026-08-23T10:00:00Z",
            name = "Ares",
            materials = listOf("Tigerauge"),
            metalElements = listOf("Edelstahl"),
            braceletSizeCm = "18",
            pearlSizeMm = "8",
            shortDescription = "Dauerhaft verfügbar.",
            descriptionDocument = DescriptionDocument.fromPlainText("Dauerhaft verfügbar."),
            careInstructions = emptyList(),
            priceMinor = 2000,
            currency = "eur",
            available = true,
            images = listOf(
                ProductServerImage(
                    imageId = imageId,
                    fileName = "01.jpg",
                    isMain = true,
                    alt = "Ares Armband",
                    width = 24,
                    height = 16,
                ),
            ),
        )

        val imported = repository.importServerDraft(update, mapOf(imageId to jpeg))
        val existing = repository.importServerDraft(
            update.copy(name = "Darf lokalen Stand nicht überschreiben"),
            mapOf(imageId to jpeg),
        )
        val reloaded = repository.loadDrafts().single()

        assertEquals("Ares", imported.name)
        assertEquals("Ares", existing.name)
        assertEquals("Ares", reloaded.name)
        assertEquals(ProductStatus.Published, reloaded.status)
        assertEquals("CP-2026-0001", reloaded.sku)
        assertEquals(imageId, reloaded.images.single().serverImageId)
        assertTrue(File(reloaded.images.single().localPath).isFile)
    }

    @Test
    fun explicitServerReplacementKeepsLocalDraftAndImagesRecoverableAcrossRestart() = runBlocking {
        val local = repository.saveDraft(repository.storeTemporaryImages(
            testDraft().copy(name = "Meine Amazonit-Fassung", productVersion = 7, version = 70,
                pendingInitialOnHand = 3, pendingInitialInventoryOperationId = "inventory-pending"),
            listOf(Uri.fromFile(sourceImage)),
        ))
        val oldBytes = File(local.images.single().localPath).readBytes()
        val server = ProductServerUpdate(
            draftId = draftId, productModelVersion = 4, version = 90, productVersion = 8,
            sourceHash = "b".repeat(64), sku = "CP-TEST", slug = "test-amazonit",
            status = ProductStatus.Published, updatedAt = "2026-11-14T10:00:00Z", name = "Server-Amazonit",
            materials = listOf("Amazonit"), metalElements = emptyList(), braceletSizeCm = "18", pearlSizeMm = "8",
            shortDescription = "Servertext", descriptionDocument = DescriptionDocument.fromPlainText("Servertext"),
            notices = listOf("Serverhinweis"), careInstructions = listOf("Trocken halten"),
            priceMinor = 3250, currency = "eur", available = false, salesEnabled = false,
            images = listOf(ProductServerImage("server-image", "01.jpg", true, "Serverfoto", 24, 24)),
        )
        repository.preserveConflictDraft(local)
        repository.replaceWithReviewedServerDraft(local, server, mapOf("server-image" to oldBytes))
        val restarted = ProductDraftRepository(context).loadDrafts().single()
        assertEquals("Server-Amazonit", restarted.name)
        assertEquals(8, restarted.productVersion)
        assertEquals(90, restarted.version)
        assertEquals(3250, restarted.priceMinor)
        assertFalse(restarted.salesEnabled)
        assertEquals(local.internalCalculation, restarted.internalCalculation)
        assertEquals(local.pendingInitialOnHand, restarted.pendingInitialOnHand)
        assertEquals(local.pendingInitialInventoryOperationId, restarted.pendingInitialInventoryOperationId)
        assertTrue(File(local.images.single().localPath).readBytes().contentEquals(oldBytes))
        val recovery = File(context.filesDir, "product-conflicts/$draftId").listFiles()!!.single()
        val saved = JSONObject(File(recovery, "draft.json").readText())
        assertEquals(local.name, saved.getString("name"))
        assertEquals(7, saved.getInt("productVersion"))
        assertEquals(local.priceMinor, saved.getInt("priceMinor"))
        val recoveryImage = File(saved.getJSONArray("images").getJSONObject(0).getString("localPath"))
        assertTrue(recoveryImage.readBytes().contentEquals(oldBytes))
        assertTrue(recoveryImage.canonicalPath.startsWith(recovery.canonicalPath))
    }

    @Test
    fun invalidDownloadedImageLeavesCurrentDraftAndOriginalImagesUntouched() = runBlocking {
        val local = repository.saveDraft(testDraft())
        val server = ProductServerUpdate(
            draftId = draftId, version = 2, productVersion = 2, sourceHash = "b".repeat(64), sku = null, slug = null,
            status = ProductStatus.Draft, updatedAt = null, name = "Remote", materials = emptyList(),
            metalElements = emptyList(), braceletSizeCm = "18", pearlSizeMm = "8", shortDescription = "Test",
            careInstructions = emptyList(), priceMinor = 2000, currency = "eur", available = false,
            images = listOf(ProductServerImage("remote", "01.jpg", true)),
        )
        repository.preserveConflictDraft(local)
        try {
            repository.replaceWithReviewedServerDraft(local, server, mapOf("remote" to byteArrayOf(1, 2, 3)))
            throw AssertionError("Invalid image must abort")
        } catch (_: IllegalArgumentException) { }
        assertEquals(local, ProductDraftRepository(context).loadDrafts().single())
        assertTrue(File(context.filesDir, "product-conflicts/$draftId").listFiles()!!.isNotEmpty())
    }

    @Test
    fun interruptedAtomicWriteRestoresBackupAndIgnoresIncompleteStaging() = runBlocking {
        val original = repository.saveDraft(testDraft())
        val file = File(context.filesDir, "product-drafts/$draftId.json")
        val backup = File(file.parentFile, "${file.name}.bak")
        val staging = File(file.parentFile, ".$draftId.json")
        val fresh = File(file.parentFile, "${file.name}.new")
        try {
            file.copyTo(backup)
            file.writeText("{truncated")
            staging.writeText("{unfinished")
            fresh.writeText("{unfinished")
            assertEquals(original, repository.loadDrafts().single())
            assertFalse(backup.exists())
            assertEquals(original, repository.loadDrafts().single())
        } finally {
            backup.delete(); staging.delete(); fresh.delete()
        }
    }

    private fun testDraft(): ProductDraft {
        return ProductDraft(
            draftId = draftId,
            name = "Testentwurf",
            priceMinor = 5000,
            internalCalculation = CalculationSnapshot(
                quantities = emptyMap(),
                workMinutes = "0",
                hourlyRate = "0",
                otherCosts = "0",
                markupPercent = "0",
                materialCosts = "0.00",
                laborCosts = "0.00",
                totalCosts = "0.00",
                recommendedSalePrice = "0.00",
                createdAtMillis = 1L,
            ),
            createdAtMillis = 1L,
            updatedAtMillis = 1L,
        )
    }
}
