package de.carmajaperlen.armbandrechner

import android.content.Context
import android.content.ContextWrapper
import android.graphics.Bitmap
import androidx.compose.ui.text.input.TextFieldValue
import androidx.lifecycle.ViewModelStore
import androidx.test.core.app.ApplicationProvider
import androidx.test.platform.app.InstrumentationRegistry
import java.io.File
import java.util.UUID
import kotlinx.coroutines.runBlocking
import org.junit.Assert.*
import org.junit.Test

/** Two independent device stores/ViewModels, one in-memory V5 server; zero network calls. */
class ProductTwoDeviceFlowTest {
    @Test fun realViewModelsPreserveBothDevicesAndOnlyPublishAfterExplicitResolution() = runBlocking {
        val base = ApplicationProvider.getApplicationContext<Context>()
        val root = File(base.cacheDir, "two-device-${UUID.randomUUID()}").apply { mkdirs() }
        val stores = mutableListOf<ViewModelStore>()
        val tokenStores = mutableListOf<SecureTokenStore>()
        val preferences = mutableListOf<String>()
        try {
            val image = File(root, "source.jpg")
            val bitmap = Bitmap.createBitmap(24, 24, Bitmap.Config.ARGB_8888)
            image.outputStream().use { bitmap.compress(Bitmap.CompressFormat.JPEG, 90, it) }
            bitmap.recycle()
            val draft = ProductDraft(
                draftId = "d01a5cde-920d-481c-b17b-98637a9af26c", name = "Amazonit", version = 70, productVersion = 7,
                sourceHash = "a".repeat(64), materials = listOf("Amazonit"), braceletSizeCm = "18", pearlSizeMm = "8",
                shortDescription = "Mein Text", descriptionDocument = DescriptionDocument.fromPlainText("Mein Text"),
                priceMinor = 2800, internalCalculation = CalculationSnapshot(emptyMap(), "0", "0", "0", "0", "0", "0", "0", "28", 1),
                images = listOf(ProductImage(image.absolutePath, 24, 24, "Amazonit", true, "image-id", "image-id", "01.jpg", true, 70)),
                createdAtMillis = 1, updatedAtMillis = 1,
            )
            val api = TwoDeviceApi(draft)
            suspend fun device(label: String): ProductViewModel {
                val privateRoot = File(root, label)
                val prefName = "conflict-$label-${UUID.randomUUID()}"
                preferences += prefName
                val context = object : ContextWrapper(base) {
                    override fun getFilesDir() = File(privateRoot, "files").apply { mkdirs() }
                    override fun getCacheDir() = File(privateRoot, "cache").apply { mkdirs() }
                    override fun getSharedPreferences(name: String, mode: Int) = base.getSharedPreferences(prefName, mode)
                }
                val repo = ProductDraftRepository(context)
                repo.saveDraft(draft.copy(images = draft.images.map {
                    val ownImage = File(context.filesDir, "own-image.jpg")
                    image.copyTo(ownImage)
                    it.copy(localPath = ownImage.absolutePath)
                }))
                val tokens = SecureTokenStore(context).also { tokenStores += it }
                tokens.saveRememberedSession("artificial-offline-token")
                lateinit var model: ProductViewModel
                main {
                    model = ProductViewModel(repo, tokens, api)
                    ViewModelStore().also { it.put("device", model); stores += it }
                }
                await { model.uiState.value.sessionChecked && model.uiState.value.authenticated && !model.uiState.value.busy }
                return model
            }
            val a = device("A")
            val b = device("B")
            main {
                a.updateSelectedEditor(ProductEditorField.Name, TextFieldValue("Amazonit von Gerät A"))
                a.syncSelected()
            }
            await { !a.uiState.value.busy }
            assertNull(a.uiState.value.error)
            assertEquals(8, api.server.productVersion)
            main { b.requestPublicationPreview(); b.publishSelected() }
            await { !b.uiState.value.busy }
            assertNotNull(b.uiState.value.conflictReview)
            assertTrue(b.uiState.value.error!!.contains("Produktversion auf dem Server: 8"))
            assertEquals("Amazonit", b.uiState.value.selectedDraft!!.name)
            assertEquals(0, api.publications)
            assertEquals(1, api.writes)
            main { b.resolveConflict(ProductConflictChoice.KeepLocal) }
            await { !b.uiState.value.busy }
            assertNull(b.uiState.value.error)
            assertNull(b.uiState.value.conflictReview)
            assertEquals(8, b.uiState.value.selectedDraft!!.productVersion)
            assertEquals("Amazonit", b.uiState.value.selectedDraft!!.name)
            assertEquals("Amazonit von Gerät A", api.server.name)
            assertEquals(1, api.writes)
            assertEquals(0, api.publications)
            val backup = File(root, "B/files/product-conflicts/${draft.draftId}").listFiles()!!.single()
            assertTrue(File(backup, "draft.json").isFile)
            assertTrue(File(backup, "01.jpg").readBytes().contentEquals(image.readBytes()))
            main { b.requestPublicationPreview(); b.publishSelected() }
            await { !b.uiState.value.busy }
            assertNull(b.uiState.value.error)
            assertNotNull(b.uiState.value.publicationConfirmation)
            assertEquals(1, api.publications)
            assertEquals(2, api.writes)
            assertEquals("Amazonit", api.server.name)
            assertEquals("Amazonit von Gerät A", a.uiState.value.selectedDraft!!.name)
        } finally {
            main { stores.forEach { it.clear() } }
            tokenStores.forEach { it.clearSession() }
            preferences.forEach { base.deleteSharedPreferences(it) }
            root.deleteRecursively()
        }
    }

    private fun main(action: () -> Unit) = InstrumentationRegistry.getInstrumentation().runOnMainSync(action)
    private fun await(condition: () -> Boolean) {
        val deadline = System.currentTimeMillis() + 15000
        while (!condition()) {
            check(System.currentTimeMillis() < deadline) { "ViewModel operation timed out" }
            Thread.sleep(20)
        }
        InstrumentationRegistry.getInstrumentation().waitForIdleSync()
    }

    private class TwoDeviceApi(initial: ProductDraft) : ProductApiClient() {
        var server = response(initial)
        var writes = 0
        var publications = 0
        override fun listProducts(baseUrl: String, token: String) = listOf(server)
        override fun getDraft(baseUrl: String, token: String, draftId: String) = server
        override fun saveDraft(baseUrl: String, token: String, draft: ProductDraft): ProductServerUpdate {
            if (draft.productVersion != server.productVersion) throw ProductConflictException(
                "product_version_conflict", emptyMap(), "Neuere Fassung vorhanden.")
            writes++
            server = response(draft).copy(version = server.version + 1, productVersion = server.productVersion + 1,
                sourceHash = writes.toString().padStart(64, '0'))
            return server
        }
        override fun publish(baseUrl: String, token: String, draft: ProductDraft, operationId: String): PublishResult {
            check(draft.productVersion == server.productVersion && draft.sourceHash == server.sourceHash)
            publications++
            server = server.copy(status = ProductStatus.Published, version = server.version + 1, productVersion = server.productVersion + 1)
            return PublishResult(draft.draftId, "CP-TEST", server.version, operationId, null, "not_started",
                server.status, server.productVersion, server.sourceHash, false)
        }
        private fun response(draft: ProductDraft) = ProductServerUpdate(
            draft.draftId, 4, draft.version, draft.productVersion, requireNotNull(draft.sourceHash), null, null,
            draft.status, null, draft.name, draft.materials, draft.metalElements, draft.braceletSizeCm, draft.pearlSizeMm,
            draft.shortDescription, draft.descriptionDocument, draft.notices, draft.careInstructions, draft.priceMinor,
            draft.currency, false, listOf(ProductServerImage("image-id", "01.jpg", true, "Amazonit", 24, 24)), false,
        )
    }
}
