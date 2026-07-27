package de.steinhart.armbandrechner

import android.content.Context
import android.net.Uri
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import androidx.lifecycle.viewModelScope
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
    val apiBaseUrl: String = "",
    val username: String = "",
    val password: String = "",
    val deviceName: String = "Android",
    val authenticated: Boolean = false,
    val busy: Boolean = false,
    val message: String? = null,
    val error: String? = null,
    val fieldErrors: Map<String, String> = emptyMap(),
) {
    val selectedDraft: ProductDraft?
        get() = drafts.firstOrNull { it.draftId == selectedDraftId }
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
            val drafts = repository.loadDrafts()
            _uiState.value = _uiState.value.copy(
                drafts = drafts,
                selectedDraftId = drafts.firstOrNull()?.draftId,
                apiBaseUrl = tokenStore.loadPlainSetting(
                    SecureTokenStore.SETTING_API_BASE_URL,
                    BuildConfig.DEFAULT_PRODUCT_API_BASE_URL,
                ),
                deviceName = tokenStore.loadPlainSetting(
                    SecureTokenStore.SETTING_DEVICE_NAME,
                    "Android",
                ),
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
                message = "Produktentwurf aus Kalkulation erstellt.",
                error = null,
            )
        }
    }

    fun selectDraft(draftId: String) {
        _uiState.value = _uiState.value.copy(selectedDraftId = draftId, fieldErrors = emptyMap())
    }

    fun updateApiBaseUrl(value: String) {
        _uiState.value = _uiState.value.copy(apiBaseUrl = value)
        tokenStore.savePlainSetting(SecureTokenStore.SETTING_API_BASE_URL, value)
    }

    fun updateUsername(value: String) {
        _uiState.value = _uiState.value.copy(username = value)
    }

    fun updatePassword(value: String) {
        _uiState.value = _uiState.value.copy(password = value)
    }

    fun updateDeviceName(value: String) {
        _uiState.value = _uiState.value.copy(deviceName = value)
        tokenStore.savePlainSetting(SecureTokenStore.SETTING_DEVICE_NAME, value)
    }

    fun updateSelected(transform: ProductDraft.() -> ProductDraft) {
        val draft = _uiState.value.selectedDraft ?: return
        viewModelScope.launch {
            val updated = repository.saveDraft(draft.transform())
            replaceDraft(updated)
        }
    }

    fun addImages(uris: List<Uri>) {
        val draft = _uiState.value.selectedDraft ?: return
        if (uris.isEmpty()) return
        runBusy {
            val updated = repository.storeImages(draft, uris.take(5))
            replaceDraft(updated)
            _uiState.value = _uiState.value.copy(message = "Bilder wurden vorbereitet.")
        }
    }

    fun login() {
        val state = _uiState.value
        runBusy {
            val token = withContext(Dispatchers.IO) {
                apiClient.login(
                    baseUrl = state.apiBaseUrl,
                    username = state.username,
                    password = state.password,
                    deviceName = state.deviceName.ifBlank { "Android" },
                )
            }
            apiToken = token
            tokenStore.saveToken(token)
            _uiState.value = _uiState.value.copy(
                authenticated = true,
                password = "",
                message = "Anmeldung erfolgreich.",
            )
        }
    }

    fun syncSelected() {
        val draft = _uiState.value.selectedDraft ?: return
        runBusy {
            val updated = syncDraft(draft)
            replaceDraft(updated)
            _uiState.value = _uiState.value.copy(message = "Produktentwurf synchronisiert.")
        }
    }

    fun publishSelected() {
        val draft = _uiState.value.selectedDraft ?: return
        val validation = draft.validateForPublish()
        if (validation.isNotEmpty()) {
            _uiState.value = _uiState.value.copy(fieldErrors = validation)
            return
        }

        runBusy {
            var current = draft
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
                message = "Veröffentlichung gestartet: ${result.commitSha.take(7)} (${result.deploymentStatus}).",
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
                message = "$successMessage Commit ${result.commitSha.take(7)}.",
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

    private fun requireBaseUrl(): String {
        val baseUrl = _uiState.value.apiBaseUrl.trim().trimEnd('/')
        if (!baseUrl.startsWith("https://")) {
            throw ProductApiException(0, "API-URL muss mit https:// beginnen.")
        }
        return baseUrl
    }

    private fun requireToken(): String {
        return apiToken ?: throw ProductApiException(401, "Bitte zuerst anmelden.")
    }

    private fun runBusy(block: suspend () -> Unit) {
        if (_uiState.value.busy) return
        _uiState.value = _uiState.value.copy(busy = true, error = null, message = null)
        viewModelScope.launch {
            runCatching { block() }
                .onFailure { error ->
                    _uiState.value = _uiState.value.copy(
                        error = readableError(error),
                        busy = false,
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
