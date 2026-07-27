package de.steinhart.armbandrechner

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
    val authenticated: Boolean = false,
    val busy: Boolean = false,
    val message: String? = null,
    val error: String? = null,
    val fieldErrors: Map<String, String> = emptyMap(),
) {
    val selectedDraft: ProductDraft?
        get() = drafts.firstOrNull { it.draftId == selectedDraftId }

    val selectedEditor: ProductDraftEditorState?
        get() = selectedDraftId?.let(editors::get)
}

class ProductViewModel(
    private val repository: ProductDraftRepository,
    private val tokenStore: SecureTokenStore,
    private val apiClient: ProductApiClient = ProductApiClient(),
) : ViewModel() {
    private val _uiState = MutableStateFlow(ProductUiState())
    val uiState: StateFlow<ProductUiState> = _uiState.asStateFlow()

    private var apiToken: String? = null

    init {
        viewModelScope.launch {
            apiToken = tokenStore.loadToken()
                .takeIf { BuildConfig.PRODUCT_PUBLISH_TARGET == "test" }
            val drafts = repository.loadDrafts()
            val apiBaseUrl = BuildConfig.DEFAULT_PRODUCT_API_BASE_URL
            val deviceName = tokenStore.loadPlainSetting(
                SecureTokenStore.SETTING_DEVICE_NAME,
                "Android",
            )
            _uiState.value = _uiState.value.copy(
                drafts = drafts,
                selectedDraftId = drafts.firstOrNull()?.draftId,
                editors = drafts.associate { it.draftId to ProductDraftEditorState.fromDraft(it) },
                loginEditor = ProductLoginEditorState.fromStored(apiBaseUrl, deviceName),
                authenticated = apiToken != null,
            )
        }
    }

    fun createFromCalculation(prices: List<PriceItem>, values: CalculatorValues, totals: CalculatorTotals) {
        viewModelScope.launch {
            val draft = repository.saveDraft(
                repository.createDraftFromCalculation(prices, values, totals),
            )
            _uiState.value = _uiState.value.copy(
                drafts = listOf(draft) + _uiState.value.drafts,
                selectedDraftId = draft.draftId,
                editors = _uiState.value.editors + (
                    draft.draftId to ProductDraftEditorState.fromDraft(draft)
                ),
                message = "Produktentwurf aus Kalkulation erstellt.",
                error = null,
            )
        }
    }

    fun selectDraft(draftId: String) {
        _uiState.value = _uiState.value.copy(selectedDraftId = draftId, fieldErrors = emptyMap())
    }

    fun updateApiBaseUrl(value: TextFieldValue) {
        // The beta app has no environment switch; the endpoint comes from BuildConfig.
    }

    fun updateUsername(value: TextFieldValue) {
        val editor = _uiState.value.loginEditor.copy(username = value)
        _uiState.value = _uiState.value.copy(loginEditor = editor)
    }

    fun updatePassword(value: TextFieldValue) {
        val editor = _uiState.value.loginEditor.copy(password = value)
        _uiState.value = _uiState.value.copy(loginEditor = editor)
    }

    fun updateDeviceName(value: TextFieldValue) {
        val editor = _uiState.value.loginEditor.copy(deviceName = value)
        _uiState.value = _uiState.value.copy(loginEditor = editor)
    }

    fun updateSelectedEditor(field: ProductEditorField, value: TextFieldValue) {
        val editor = _uiState.value.selectedEditor ?: return
        _uiState.value = _uiState.value.copy(
            editors = _uiState.value.editors + (
                editor.draftId to editor.update(field, value)
            ),
            fieldErrors = _uiState.value.fieldErrors - field.errorKey,
        )
    }

    fun saveSelected() {
        val snapshot = selectedDraftSnapshot() ?: return
        runBusy {
            val saved = repository.saveDraft(snapshot)
            replaceDraft(saved)
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
            val updated = repository.storeImages(
                draft.copy(name = editorName.ifBlank { draft.name }),
                uris.take(5),
            )
            replaceDraft(updated)
            _uiState.value = _uiState.value.copy(message = "Bilder wurden vorbereitet.")
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
                        expectedPublishTarget = BuildConfig.PRODUCT_PUBLISH_TARGET,
                    )
                }
            } catch (error: ProductTargetMismatchException) {
                apiToken = null
                tokenStore.clearToken()
                _uiState.value = _uiState.value.copy(authenticated = false)
                throw error
            }
            apiToken = login.token
            tokenStore.saveToken(login.token)
            tokenStore.savePlainSetting(SecureTokenStore.SETTING_DEVICE_NAME, deviceName)
            _uiState.value = _uiState.value.copy(
                authenticated = true,
                loginEditor = editor.copy(password = TextFieldValue()),
                message = "Anmeldung erfolgreich.",
            )
        }
    }

    fun syncSelected() {
        val draft = selectedDraftSnapshot() ?: return
        runBusy {
            val local = repository.saveDraft(draft)
            replaceDraft(local)
            val updated = syncDraft(local)
            replaceDraft(updated)
            _uiState.value = _uiState.value.copy(
                message = "Produktentwurf synchronisiert.",
                fieldErrors = emptyMap(),
            )
        }
    }

    fun publishSelected() {
        val draft = selectedDraftSnapshot() ?: return
        val validation = draft.validateForPublish()
        if (validation.isNotEmpty()) {
            _uiState.value = _uiState.value.copy(fieldErrors = validation)
            return
        }

        runBusy {
            var current = repository.saveDraft(draft)
            replaceDraft(current)
            val operationId = current.pendingPublishOperationId ?: UUID.randomUUID().toString()
            current = repository.saveDraft(current.copy(pendingPublishOperationId = operationId))
            replaceDraft(current)
            current = syncDraft(current)
            val result = withContext(Dispatchers.IO) {
                apiClient.publish(requireBaseUrl(), requireToken(), current, operationId)
            }
            val published = repository.saveDraft(
                current.copy(
                    sku = result.sku,
                    version = result.version,
                    status = result.status,
                    pendingPublishOperationId = null,
                ),
            )
            replaceDraft(published)
            _uiState.value = _uiState.value.copy(
                message = if (result.commitSha == null) {
                    "Für Testwebsite bereitgestellt (${result.deploymentStatus})."
                } else {
                    "Veröffentlichung gestartet: ${result.commitSha.take(7)} (${result.deploymentStatus})."
                },
                fieldErrors = emptyMap(),
            )
        }
    }

    fun markSelectedSold() {
        changeLiveStatus(
            operationSelector = { pendingSoldOperationId },
            operationWriter = { copy(pendingSoldOperationId = it) },
            apiCall = { draft, operationId ->
                apiClient.markSold(requireBaseUrl(), requireToken(), draft, operationId)
            },
            successMessage = "Produkt wurde als verkauft markiert.",
        )
    }

    fun disableSelected() {
        changeLiveStatus(
            operationSelector = { pendingDisableOperationId },
            operationWriter = { copy(pendingDisableOperationId = it) },
            apiCall = { draft, operationId ->
                apiClient.disable(requireBaseUrl(), requireToken(), draft, operationId)
            },
            successMessage = "Produkt wurde deaktiviert.",
        )
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
            val withOperation = repository.saveDraft(draft.operationWriter(operationId))
            replaceDraft(withOperation)
            val result = withContext(Dispatchers.IO) { apiCall(withOperation, operationId) }
            val updated = repository.saveDraft(
                withOperation.copy(
                    version = result.version,
                    status = result.status,
                    pendingSoldOperationId = null,
                    pendingDisableOperationId = null,
                ),
            )
            replaceDraft(updated)
            _uiState.value = _uiState.value.copy(
                message = result.commitSha?.let { "$successMessage Commit ${it.take(7)}." }
                    ?: "$successMessage Verarbeitung: ${result.deploymentStatus}.",
            )
        }
    }

    private suspend fun syncDraft(draft: ProductDraft): ProductDraft {
        val saved = withContext(Dispatchers.IO) {
            apiClient.saveDraft(requireBaseUrl(), requireToken(), draft)
        }
        var updated = applyServerUpdate(draft, saved)
        updated = repository.saveDraft(updated)

        if (updated.images.isNotEmpty()) {
            val uploaded = withContext(Dispatchers.IO) {
                apiClient.uploadImages(requireBaseUrl(), requireToken(), updated)
            }
            updated = repository.saveDraft(applyServerUpdate(updated, uploaded))
        }

        return updated
    }

    private fun applyServerUpdate(draft: ProductDraft, update: ProductServerUpdate): ProductDraft {
        return draft.copy(
            version = update.version,
            sku = update.sku ?: draft.sku,
            slug = update.slug ?: draft.slug,
            status = update.status,
        )
    }

    private fun replaceDraft(draft: ProductDraft) {
        _uiState.value = _uiState.value.copy(
            drafts = _uiState.value.drafts
                .filterNot { it.draftId == draft.draftId }
                .plus(draft)
                .sortedByDescending { it.updatedAtMillis },
            selectedDraftId = draft.draftId,
        )
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

        return editor.applyTo(draft)
    }

    private fun requireBaseUrl(): String {
        if (BuildConfig.PRODUCT_PUBLISH_TARGET != "test") {
            throw ProductApiException(
                0,
                "test_build_required",
                message = "Produktverwaltung ist nur im Test-Build verfügbar.",
            )
        }
        return requireTestApiBaseUrl(BuildConfig.DEFAULT_PRODUCT_API_BASE_URL)
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
                    val fieldErrors = (error as? ProductApiException)
                        ?.fields
                        .orEmpty()
                    _uiState.value = _uiState.value.copy(
                        error = readableError(error),
                        busy = false,
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
            is ProductConflictException ->
                "Der Serverstand ist neuer. Bitte Entwurf erneut laden und Änderungen prüfen."
            is ProductTargetMismatchException ->
                "Anmeldung abgelehnt: Die API ist nicht als Testumgebung konfiguriert."
            is ProductApiException -> error.message ?: "Produktserverfehler"
            is IOException -> "Keine Verbindung zur Produktverwaltung"
            is IllegalArgumentException -> error.message ?: "Ungültige Eingabe"
            else -> "Produktaktion konnte nicht abgeschlossen werden"
        }
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
