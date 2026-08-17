package de.carmajaperlen.armbandrechner

import android.content.Context
import android.net.Uri
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import androidx.lifecycle.viewModelScope
import androidx.compose.ui.text.input.TextFieldValue
import java.io.IOException
import java.util.UUID
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

data class ProductUiState(
    val drafts: List<ProductDraft> = emptyList(),
    val selectedDraftId: String? = null,
    val editors: Map<String, ProductDraftEditorState> = emptyMap(),
    val loginEditor: ProductLoginEditorState = ProductLoginEditorState(),
    val apiEndpoint: ProductApiEndpoint? = null,
    val sessionChecked: Boolean = false,
    val authenticated: Boolean = false,
    val editingDraftId: String? = null,
    val unsavedDraftIds: Set<String> = emptySet(),
    val busy: Boolean = false,
    val message: String? = null,
    val error: String? = null,
    val fieldErrors: Map<String, String> = emptyMap(),
    val conflict: ProductSyncConflictState? = null,
    val publicationPreview: ProductDraft? = null,
) {
    val selectedDraft: ProductDraft?
        get() = drafts.firstOrNull { it.draftId == selectedDraftId }

    val selectedEditor: ProductDraftEditorState?
        get() = selectedDraftId?.let(editors::get)

    val selectedHasUnsavedChanges: Boolean
        get() = selectedDraftId in unsavedDraftIds
}

class ProductViewModel(
    private val repository: ProductDraftRepository,
    private val tokenStore: SecureTokenStore,
    private val apiClient: ProductApiClient = ProductApiClient(),
) : ViewModel() {
    private val _uiState = MutableStateFlow(ProductUiState())
    val uiState: StateFlow<ProductUiState> = _uiState.asStateFlow()

    private var apiToken: String? = null
    private val draftSession = ProductDraftSession()
    private val apiEndpoint = requireProductApiEndpoint(
        baseUrl = BuildConfig.DEFAULT_PRODUCT_API_BASE_URL,
        publishTarget = BuildConfig.PRODUCT_PUBLISH_TARGET,
    )

    init {
        viewModelScope.launch {
            val canRestoreSession = tokenStore.isRememberedSessionEnabled()
            apiToken = if (canRestoreSession) tokenStore.loadRememberedToken() else null
            if (apiToken == null) {
                tokenStore.clearSession()
            }
            val drafts = repository.loadDrafts()
            draftSession.initialize(drafts)
            val deviceName = tokenStore.loadPlainSetting(
                SecureTokenStore.SETTING_DEVICE_NAME,
                "Android",
            )
            _uiState.value = _uiState.value.copy(
                drafts = drafts,
                selectedDraftId = drafts.firstOrNull()?.draftId,
                editors = drafts.associate { it.draftId to ProductDraftEditorState.fromDraft(it) },
                loginEditor = ProductLoginEditorState.fromStored(
                    deviceName = deviceName,
                    rememberSession = apiToken != null,
                ),
                apiEndpoint = apiEndpoint,
                sessionChecked = true,
                authenticated = apiToken != null,
            )
        }
    }

    fun createFromCalculation(prices: List<PriceItem>, values: CalculatorValues, totals: CalculatorTotals) {
        runBusy {
            discardSelectedUnsaved()
            val draft = repository.createDraftFromCalculation(prices, values, totals)
            draftSession.registerNew(draft)
            _uiState.value = _uiState.value.copy(
                drafts = listOf(draft) + _uiState.value.drafts,
                selectedDraftId = draft.draftId,
                editors = _uiState.value.editors + (
                    draft.draftId to ProductDraftEditorState.fromDraft(draft)
                ),
                unsavedDraftIds = draftSession.unsavedDraftIds,
                message = "Neuer Produktentwurf erstellt. Noch nicht gespeichert.",
                error = null,
            )
        }
    }

    fun selectDraft(draftId: String) {
        if (_uiState.value.selectedDraftId == draftId) return
        runBusy {
            discardSelectedUnsaved()
            if (_uiState.value.drafts.any { it.draftId == draftId }) {
                _uiState.value = _uiState.value.copy(
                    selectedDraftId = draftId,
                    editingDraftId = null,
                    fieldErrors = emptyMap(),
                )
            }
        }
    }

    fun updateUsername(value: TextFieldValue) {
        val editor = _uiState.value.loginEditor.copy(username = value)
        _uiState.value = _uiState.value.copy(loginEditor = editor, error = null)
    }

    fun updatePassword(value: TextFieldValue) {
        val editor = _uiState.value.loginEditor.copy(password = value)
        _uiState.value = _uiState.value.copy(loginEditor = editor, error = null)
    }

    fun updateDeviceName(value: TextFieldValue) {
        val editor = _uiState.value.loginEditor.copy(deviceName = value)
        _uiState.value = _uiState.value.copy(loginEditor = editor, error = null)
    }

    fun updateRememberSession(value: Boolean) {
        val editor = _uiState.value.loginEditor.copy(rememberSession = value)
        _uiState.value = _uiState.value.copy(loginEditor = editor, error = null)
    }

    fun beginEditingSelected() {
        val draft = _uiState.value.selectedDraft ?: return
        if (draft.status != ProductStatus.Published) return
        _uiState.value = _uiState.value.copy(
            editingDraftId = draft.draftId,
            fieldErrors = emptyMap(),
        )
    }

    fun updateSelectedEditor(field: ProductEditorField, value: TextFieldValue) {
        val editor = _uiState.value.selectedEditor ?: return
        draftSession.markChanged(editor.draftId)
        _uiState.value = _uiState.value.copy(
            editors = _uiState.value.editors + (
                editor.draftId to editor.update(field, value)
            ),
            unsavedDraftIds = draftSession.unsavedDraftIds,
            fieldErrors = _uiState.value.fieldErrors - field.errorKey,
            publicationPreview = null,
        )
    }

    fun toggleDescriptionBold() = updateDescriptionEditor(RichDescriptionEditorState::toggleBold)

    fun toggleDescriptionItalic() = updateDescriptionEditor(RichDescriptionEditorState::toggleItalic)

    fun setDescriptionFont(font: DescriptionFont) = updateDescriptionEditor { it.setFont(font) }

    fun setDescriptionSize(size: DescriptionSize) = updateDescriptionEditor { it.setSize(size) }

    private fun updateDescriptionEditor(
        transform: (RichDescriptionEditorState) -> RichDescriptionEditorState,
    ) {
        val editor = _uiState.value.selectedEditor ?: return
        draftSession.markChanged(editor.draftId)
        _uiState.value = _uiState.value.copy(
            editors = _uiState.value.editors + (
                editor.draftId to editor.copy(shortDescription = transform(editor.shortDescription))
            ),
            unsavedDraftIds = draftSession.unsavedDraftIds,
            fieldErrors = _uiState.value.fieldErrors - ProductEditorField.ShortDescription.errorKey,
            publicationPreview = null,
        )
    }

    fun saveSelected() {
        val snapshot = selectedDraftSnapshot() ?: return
        runBusy {
            saveAndReplace(snapshot)
            _uiState.value = _uiState.value.copy(
                message = "Produktentwurf lokal gespeichert.",
                fieldErrors = emptyMap(),
            )
        }
    }

    fun addImages(uris: List<Uri>) {
        val draft = _uiState.value.selectedDraft ?: return
        val editorName = _uiState.value.selectedEditor?.name?.text?.trim().orEmpty()
        if (uris.isEmpty()) return
        runBusy {
            val updated = repository.storeTemporaryImages(
                draft.copy(
                    name = editorName.ifBlank { draft.name },
                    pendingV2SaveOperationId = null,
                ),
                uris.take(5),
            )
            draftSession.markChanged(draft.draftId)
            replaceDraft(updated)
            _uiState.value = _uiState.value.copy(
                unsavedDraftIds = draftSession.unsavedDraftIds,
                message = "Bilder wurden vorbereitet. Noch nicht gespeichert.",
            )
        }
    }

    fun login() {
        val state = _uiState.value
        val editor = state.loginEditor
        val deviceName = editor.deviceName.text.trim().ifBlank { "Android" }
        runBusy {
            val baseUrl = requireBaseUrl()
            val login = try {
                withContext(Dispatchers.IO) {
                    apiClient.login(
                        baseUrl = baseUrl,
                        username = editor.username.text,
                        password = editor.password.text,
                        deviceName = deviceName,
                        expectedPublishTarget = apiEndpoint.publishTarget,
                    )
                }
            } catch (error: ProductTargetMismatchException) {
                apiToken = null
                tokenStore.clearSession()
                _uiState.value = _uiState.value.copy(authenticated = false)
                throw error
            }
            apiToken = login.token
            if (editor.rememberSession) {
                tokenStore.saveRememberedSession(login.token)
            } else {
                tokenStore.clearSession()
            }
            tokenStore.savePlainSetting(SecureTokenStore.SETTING_DEVICE_NAME, deviceName)
            _uiState.value = _uiState.value.copy(
                authenticated = true,
                loginEditor = editor.copy(password = TextFieldValue()),
                message = "Anmeldung erfolgreich.",
            )
        }
    }

    fun logout() {
        apiToken = null
        tokenStore.clearSession()
        discardAllUnsavedFromState().forEach { draftId ->
            viewModelScope.launch {
                repository.discardTemporaryImages(draftId)
            }
        }
        _uiState.value = _uiState.value.copy(
            authenticated = false,
            editingDraftId = null,
            loginEditor = _uiState.value.loginEditor.copy(
                password = TextFieldValue(),
                rememberSession = false,
            ),
            message = null,
            error = null,
            fieldErrors = emptyMap(),
            conflict = null,
        )
    }

    fun syncSelected() {
        val draft = selectedDraftSnapshot() ?: return
        runBusy {
            val local = saveAndReplace(draft)
            val updated = syncDraft(local)
            markSavedAndReplace(updated)
            _uiState.value = _uiState.value.copy(
                message = "Produktentwurf synchronisiert.",
                fieldErrors = emptyMap(),
                conflict = null,
            )
        }
    }

    fun requestPublicationPreview() {
        val draft = selectedDraftSnapshot() ?: return
        val validation = draft.validateForPublish()
        if (validation.isNotEmpty()) {
            _uiState.value = _uiState.value.copy(fieldErrors = validation)
            return
        }

        _uiState.value = _uiState.value.copy(
            publicationPreview = draft,
            fieldErrors = emptyMap(),
            error = null,
        )
    }

    fun cancelPublicationPreview() {
        if (_uiState.value.busy) return
        _uiState.value = _uiState.value.copy(publicationPreview = null)
    }

    fun publishSelected() {
        val draft = _uiState.value.publicationPreview
        if (draft == null) {
            _uiState.value = _uiState.value.copy(
                error = "Vor der Veröffentlichung muss die Vorschau geöffnet werden.",
            )
            return
        }

        runBusy {
            var current = saveAndReplace(draft)
            current = saveAndReplace(
                current.prepareForPublish { UUID.randomUUID().toString() },
            )
            val operationId = requireNotNull(current.pendingPublishOperationId)
            current = syncDraft(current)
            val result = withContext(Dispatchers.IO) {
                apiClient.publish(requireBaseUrl(), requireToken(), current, operationId)
            }
            saveAndReplace(
                current.copy(
                    sku = result.sku,
                    version = result.version,
                    status = result.status,
                    productVersion = result.productVersion,
                    sourceHash = result.sourceHash,
                    salesEnabled = result.available,
                    pendingPublishOperationId = null,
                ),
            )
            _uiState.value = _uiState.value.copy(
                message = if (result.commitSha == null) {
                    "Für Testwebsite bereitgestellt (${result.deploymentStatus})."
                } else {
                    "Veröffentlichung gestartet: ${result.commitSha.take(7)} (${result.deploymentStatus})."
                },
                fieldErrors = emptyMap(),
                editingDraftId = null,
                publicationPreview = null,
            )
        }
    }

    fun archiveSelected() {
        changeLiveStatus(
            operationSelector = { pendingArchiveOperationId },
            operationWriter = { copy(pendingArchiveOperationId = it) },
            apiCall = { draft, operationId ->
                apiClient.archive(requireBaseUrl(), requireToken(), draft, operationId)
            },
            successMessage = "Kollektion wurde gelöscht.",
        )
    }

    fun restoreSelected() {
        changeLiveStatus(
            operationSelector = { pendingRestoreOperationId },
            operationWriter = { copy(pendingRestoreOperationId = it) },
            apiCall = { draft, operationId ->
                apiClient.restore(requireBaseUrl(), requireToken(), draft, operationId)
            },
            successMessage = "Kollektion wurde wiederhergestellt.",
        )
    }

    fun discardSelected() {
        if (!_uiState.value.selectedHasUnsavedChanges) return
        runBusy {
            val result = discardSelectedUnsaved()
            _uiState.value = _uiState.value.copy(
                message = when (result) {
                    is ProductDraftDiscardResult.Remove ->
                        "Nicht gespeicherter Entwurf verworfen."
                    is ProductDraftDiscardResult.Restore ->
                        "Ungespeicherte Änderungen verworfen."
                    ProductDraftDiscardResult.Unchanged -> null
                },
            )
        }
    }

    fun consumeMessage() {
        if (_uiState.value.message != null || _uiState.value.error != null) {
            _uiState.value = _uiState.value.copy(message = null, error = null)
        }
    }

    private fun changeLiveStatus(
        operationSelector: ProductDraft.() -> String?,
        operationWriter: ProductDraft.(String) -> ProductDraft,
        apiCall: (ProductDraft, String) -> PublishResult,
        successMessage: String,
    ) {
        val draft = _uiState.value.selectedDraft ?: return
        runBusy {
            val operationId = draft.operationSelector() ?: UUID.randomUUID().toString()
            val withOperation = saveAndReplace(draft.operationWriter(operationId))
            val result = withContext(Dispatchers.IO) { apiCall(withOperation, operationId) }
            val updated = saveAndReplace(
                withOperation.copy(
                    version = result.version,
                    status = result.status,
                    productVersion = result.productVersion,
                    sourceHash = result.sourceHash,
                    salesEnabled = result.available,
                    pendingArchiveOperationId = null,
                    pendingRestoreOperationId = null,
                ),
            )
            _uiState.value = _uiState.value.copy(
                message = result.commitSha?.let { "$successMessage Commit ${it.take(7)}." }
                    ?: "$successMessage Verarbeitung: ${result.deploymentStatus}.",
                editingDraftId = null,
                publicationPreview = null,
            )
        }
    }

    private suspend fun syncDraft(draft: ProductDraft): ProductDraft {
        val baseUrl = requireBaseUrl()
        val token = requireToken()
        val synchronizationApi = object : ProductSynchronizationApi {
            override suspend fun saveDraft(draft: ProductDraft): ProductServerUpdate {
                return withContext(Dispatchers.IO) {
                    apiClient.saveDraft(baseUrl, token, draft)
                }
            }

            override suspend fun getDraft(draftId: String): ProductServerUpdate {
                return withContext(Dispatchers.IO) {
                    apiClient.getDraft(baseUrl, token, draftId)
                }
            }

            override suspend fun uploadImage(
                draft: ProductDraft,
                image: ProductImage,
                desiredImageIds: List<String>,
            ): ProductServerUpdate {
                return withContext(Dispatchers.IO) {
                    apiClient.uploadImage(baseUrl, token, draft, image, desiredImageIds)
                }
            }
        }
        return ProductSynchronizer(
            api = synchronizationApi,
            persist = { candidate ->
                saveAndReplace(candidate)
            },
        ).synchronize(draft)
    }

    private suspend fun saveAndReplace(draft: ProductDraft): ProductDraft {
        val saved = repository.saveDraft(draft)
        markSavedAndReplace(saved)
        return saved
    }

    private fun markSavedAndReplace(draft: ProductDraft) {
        draftSession.markSaved(draft)
        replaceDraft(draft, refreshEditor = true)
    }

    private fun replaceDraft(draft: ProductDraft, refreshEditor: Boolean = false) {
        val editors = if (refreshEditor) {
            _uiState.value.editors + (
                draft.draftId to ProductDraftEditorState.fromDraft(draft)
            )
        } else {
            _uiState.value.editors
        }
        _uiState.value = _uiState.value.copy(
            drafts = _uiState.value.drafts
                .filterNot { it.draftId == draft.draftId }
                .plus(draft)
                .sortedByDescending { it.updatedAtMillis },
            selectedDraftId = draft.draftId,
            editors = editors,
            unsavedDraftIds = draftSession.unsavedDraftIds,
        )
    }

    private suspend fun discardSelectedUnsaved(): ProductDraftDiscardResult {
        val draftId = _uiState.value.selectedDraftId
            ?: return ProductDraftDiscardResult.Unchanged
        if (draftId !in draftSession.unsavedDraftIds) {
            return ProductDraftDiscardResult.Unchanged
        }

        repository.discardTemporaryImages(draftId)
        val result = draftSession.discard(draftId)
        applyDiscardResult(result)
        return result
    }

    private fun discardAllUnsavedFromState(): List<String> {
        val draftIds = draftSession.unsavedDraftIds.toList()
        draftIds.forEach { draftId ->
            applyDiscardResult(draftSession.discard(draftId))
        }
        return draftIds
    }

    private fun applyDiscardResult(result: ProductDraftDiscardResult) {
        when (result) {
            ProductDraftDiscardResult.Unchanged -> Unit
            is ProductDraftDiscardResult.Remove -> {
                val remainingDrafts = _uiState.value.drafts
                    .filterNot { it.draftId == result.draftId }
                _uiState.value = _uiState.value.copy(
                    drafts = remainingDrafts,
                    selectedDraftId = remainingDrafts.firstOrNull()?.draftId,
                    editors = _uiState.value.editors - result.draftId,
                    editingDraftId = null,
                    publicationPreview = null,
                    unsavedDraftIds = draftSession.unsavedDraftIds,
                    fieldErrors = emptyMap(),
                )
            }
            is ProductDraftDiscardResult.Restore -> {
                val restored = result.draft
                _uiState.value = _uiState.value.copy(
                    drafts = _uiState.value.drafts
                        .filterNot { it.draftId == restored.draftId }
                        .plus(restored)
                        .sortedByDescending { it.updatedAtMillis },
                    selectedDraftId = restored.draftId,
                    editors = _uiState.value.editors + (
                        restored.draftId to ProductDraftEditorState.fromDraft(restored)
                    ),
                    editingDraftId = null,
                    publicationPreview = null,
                    unsavedDraftIds = draftSession.unsavedDraftIds,
                    fieldErrors = emptyMap(),
                )
            }
        }
    }

    private fun selectedDraftSnapshot(): ProductDraft? {
        val state = _uiState.value
        val draft = state.selectedDraft ?: return null
        val editor = state.selectedEditor ?: return null
        val validation = editor.validateForSave()

        if (validation.isNotEmpty()) {
            _uiState.value = state.copy(fieldErrors = validation)
            return null
        }

        val updated = editor.applyTo(draft)
        return if (state.selectedHasUnsavedChanges) {
            updated.copy(pendingV2SaveOperationId = null)
        } else {
            updated
        }
    }

    private fun requireBaseUrl(): String {
        return apiEndpoint.baseUrl
    }

    private fun requireToken(): String {
        return apiToken ?: throw ProductApiException(
            401,
            "authentication_required",
            message = "Bitte zuerst anmelden.",
        )
    }

    private fun runBusy(block: suspend () -> Unit) {
        if (_uiState.value.busy) return
        _uiState.value = _uiState.value.copy(busy = true, error = null, message = null)
        viewModelScope.launch {
            runCatching { block() }
                .onFailure { error ->
                    val authenticationFailed = (error as? ProductApiException)?.statusCode == 401
                    if (authenticationFailed) {
                        apiToken = null
                        tokenStore.clearSession()
                    }
                    val fieldErrors = (error as? ProductApiException)
                        ?.fields
                        .orEmpty()
                    val conflict = (error as? ProductSynchronizationConflictException)
                        ?.toState()
                    _uiState.value = _uiState.value.copy(
                        error = readableError(error),
                        busy = false,
                        authenticated = if (authenticationFailed) {
                            false
                        } else {
                            _uiState.value.authenticated
                        },
                        conflict = conflict ?: _uiState.value.conflict,
                        fieldErrors = if (fieldErrors.isEmpty()) {
                            _uiState.value.fieldErrors
                        } else {
                            fieldErrors
                        },
                    )
                }
                .onSuccess {
                    _uiState.value = _uiState.value.copy(busy = false)
                }
        }
    }

    private fun readableError(error: Throwable): String {
        return when (error) {
            is ProductSynchronizationConflictException ->
                "HTTP 409 · ${error.errorCode}: ${error.message} " +
                    "Serverversion ${error.serverUpdate.version}; lokaler Entwurf bleibt erhalten."
            is ProductConflictException ->
                "HTTP 409 · ${error.errorCode}: ${error.message}"
            is ProductTargetMismatchException ->
                "Anmeldung abgelehnt: Die API stimmt nicht mit der App-Umgebung überein."
            is ProductApiException -> {
                val status = error.statusCode.takeIf { it > 0 }?.let { "HTTP $it · " }.orEmpty()
                "$status${error.errorCode}: ${error.message ?: "Produktserverfehler"}"
            }
            is IOException -> "Keine Verbindung zur Produktverwaltung"
            is IllegalArgumentException -> error.message ?: "Ungültige Eingabe"
            else -> "Produktaktion konnte nicht abgeschlossen werden"
        }
    }

    override fun onCleared() {
        repository.clearTemporaryImages()
        super.onCleared()
    }

    companion object {
        fun factory(context: Context): ViewModelProvider.Factory = viewModelFactory {
            initializer {
                val appContext = context.applicationContext
                ProductViewModel(
                    repository = ProductDraftRepository(appContext),
                    tokenStore = SecureTokenStore(appContext),
                )
            }
        }
    }
}
