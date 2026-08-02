package de.carmajaperlen.armbandrechner

import android.content.Context
import android.graphics.Bitmap
import android.net.Uri
import androidx.test.core.app.ApplicationProvider
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

        assertEquals(PRODUCT_MODEL_VERSION, saved.modelVersion)
        assertEquals("17.5", saved.braceletSizeCm)
        assertEquals("", saved.pearlSizeMm)
        assertFalse(persisted.has("braceletSize"))
        assertFalse(persisted.has("stock"))
        assertFalse(persisted.has("vintedUrl"))
        assertEquals(PRODUCT_MODEL_VERSION, persisted.getInt("modelVersion"))
    }

    private fun testDraft(): ProductDraft {
        return ProductDraft(
            draftId = draftId,
            name = "Testentwurf",
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
