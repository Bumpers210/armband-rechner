package de.carmajaperlen.armbandrechner

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp

data class ProductUiActions(
    val onCreateFromCalculation: () -> Unit = {},
    val onSelectDraft: (String) -> Unit = {},
    val onUsernameChange: (TextFieldValue) -> Unit = {},
    val onPasswordChange: (TextFieldValue) -> Unit = {},
    val onDeviceNameChange: (TextFieldValue) -> Unit = {},
    val onRememberSessionChange: (Boolean) -> Unit = {},
    val onLogin: () -> Unit = {},
    val onLogout: () -> Unit = {},
    val onEdit: () -> Unit = {},
    val onNameChange: (TextFieldValue) -> Unit = {},
    val onMaterialsChange: (TextFieldValue) -> Unit = {},
    val onMetalElementsChange: (TextFieldValue) -> Unit = {},
    val onBraceletSizeChange: (TextFieldValue) -> Unit = {},
    val onStockChange: (TextFieldValue) -> Unit = {},
    val onShortDescriptionChange: (TextFieldValue) -> Unit = {},
    val onVintedUrlChange: (TextFieldValue) -> Unit = {},
    val onImagesPicked: (List<Uri>) -> Unit = {},
    val onSave: () -> Unit = {},
    val onSync: () -> Unit = {},
    val onPublish: () -> Unit = {},
    val onMarkSold: () -> Unit = {},
    val onDisable: () -> Unit = {},
    val onMessageShown: () -> Unit = {},
) {
    companion object {
        fun noop() = ProductUiActions()
    }
}

@Composable
internal fun ProductLoginScreen(
    state: ProductUiState,
    actions: ProductUiActions,
    modifier: Modifier = Modifier,
) {
    if (!state.sessionChecked) {
        Box(
            contentAlignment = Alignment.Center,
            modifier = modifier.fillMaxSize(),
        ) {
            CircularProgressIndicator(modifier = Modifier.testTag("product-session-check"))
        }
        return
    }

    Column(
        verticalArrangement = Arrangement.spacedBy(14.dp),
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 24.dp, vertical = 32.dp),
    ) {
        Text(
            text = "Carmaja-Perlen Produktverwaltung Test",
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.SemiBold,
        )
        Text(
            text = "TESTUMGEBUNG",
            style = MaterialTheme.typography.titleSmall,
            fontWeight = FontWeight.Bold,
            color = MaterialTheme.colorScheme.error,
            modifier = Modifier.testTag("login-test-environment"),
        )
        Text(
            text = "Test-API: test-api.carmaja-perlen.de",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.testTag("login-api-environment"),
        )
        OutlinedTextField(
            value = state.loginEditor.username,
            onValueChange = actions.onUsernameChange,
            label = { Text("Benutzer") },
            singleLine = true,
            enabled = !state.busy,
            modifier = Modifier
                .fillMaxWidth()
                .testTag("login-username"),
        )
        OutlinedTextField(
            value = state.loginEditor.password,
            onValueChange = actions.onPasswordChange,
            label = { Text("Passwort") },
            visualTransformation = PasswordVisualTransformation(),
            singleLine = true,
            enabled = !state.busy,
            modifier = Modifier
                .fillMaxWidth()
                .testTag("login-password"),
        )
        OutlinedTextField(
            value = state.loginEditor.deviceName,
            onValueChange = actions.onDeviceNameChange,
            label = { Text("Gerät") },
            singleLine = true,
            enabled = !state.busy,
            modifier = Modifier
                .fillMaxWidth()
                .testTag("login-device"),
        )
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Checkbox(
                checked = state.loginEditor.rememberSession,
                onCheckedChange = actions.onRememberSessionChange,
                enabled = !state.busy,
                modifier = Modifier.testTag("login-remember-session"),
            )
            Text("Dauerhaft eingeloggt bleiben")
        }
        state.error?.let { error ->
            Text(
                text = error,
                color = MaterialTheme.colorScheme.error,
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier.testTag("login-error"),
            )
        }
        Button(
            onClick = actions.onLogin,
            enabled = !state.busy,
            modifier = Modifier
                .fillMaxWidth()
                .height(52.dp)
                .testTag("login-submit"),
        ) {
            Text(if (state.busy) "Anmeldung läuft ..." else "Anmelden")
        }
    }
}

@Composable
internal fun ProductManagementSection(
    state: ProductUiState,
    actions: ProductUiActions,
    modifier: Modifier = Modifier,
) {
    val imagePicker = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.PickMultipleVisualMedia(maxItems = 5),
    ) { uris ->
        actions.onImagesPicked(uris)
    }

    Column(
        verticalArrangement = Arrangement.spacedBy(14.dp),
        modifier = modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 20.dp),
    ) {
        SectionHeading(text = "Produktverwaltung")
        if (BuildConfig.PRODUCT_PUBLISH_TARGET == "test") {
            Text(
                text = "TEST · Carmaja Produktverwaltung",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.error,
                modifier = Modifier.testTag("product-test-environment"),
            )
            Text(
                text = "Test-API: test-api.carmaja-perlen.de",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.testTag("product-api-environment"),
            )
        }

        Button(
            onClick = actions.onCreateFromCalculation,
            enabled = !state.busy,
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text("Produkt aus Kalkulation erstellen")
        }

        if (state.drafts.isEmpty()) {
            Text(
                text = "Noch keine Produktentwürfe gespeichert.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        } else {
            DraftList(
                drafts = state.drafts,
                editors = state.editors,
                selectedDraftId = state.selectedDraftId,
                onSelectDraft = actions.onSelectDraft,
                busy = state.busy,
            )
        }

        val draft = state.selectedDraft
        val editor = state.selectedEditor
        if (draft != null && editor != null) {
            key(draft.draftId) {
                if (draft.status == ProductStatus.Published &&
                    state.editingDraftId != draft.draftId
                ) {
                    PublishedProductView(
                        draft = draft,
                        busy = state.busy,
                        actions = actions,
                    )
                } else {
                    ProductDraftForm(
                        draft = draft,
                        editor = editor,
                        fieldErrors = state.fieldErrors,
                        busy = state.busy,
                        actions = actions,
                        isPublishedEdit = state.editingDraftId == draft.draftId,
                        onPickImages = {
                            imagePicker.launch(
                                PickVisualMediaRequest(
                                    ActivityResultContracts.PickVisualMedia.ImageOnly,
                                ),
                            )
                        },
                    )
                }
            }
        }
    }
}

@Composable
internal fun PublishedProductView(
    draft: ProductDraft,
    busy: Boolean,
    actions: ProductUiActions,
) {
    Surface(
        shape = MaterialTheme.shapes.small,
        border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(
            verticalArrangement = Arrangement.spacedBy(10.dp),
            modifier = Modifier.padding(12.dp),
        ) {
            Text(
                text = draft.name,
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = "${draft.sku.orEmpty()} · veröffentlicht · Version ${draft.version}",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text("Perlen: ${draft.materials.joinToString(", ")}")
            Text("Spacer: ${draft.metalElements.joinToString(", ")}")
            Text(draft.shortDescription)
            Text("Bilder: ${draft.images.size}/5")
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(
                    onClick = actions.onMarkSold,
                    enabled = !busy,
                    modifier = Modifier
                        .weight(1f)
                        .testTag("published-mark-sold"),
                ) {
                    Text("Verkauft")
                }
                OutlinedButton(
                    onClick = actions.onDisable,
                    enabled = !busy,
                    modifier = Modifier
                        .weight(1f)
                        .testTag("published-disable"),
                ) {
                    Text("Deaktivieren")
                }
            }
            OutlinedButton(
                onClick = actions.onEdit,
                enabled = !busy,
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag("published-edit"),
            ) {
                Text("Bearbeiten")
            }
        }
    }
}

@Composable
private fun DraftList(
    drafts: List<ProductDraft>,
    editors: Map<String, ProductDraftEditorState>,
    selectedDraftId: String?,
    onSelectDraft: (String) -> Unit,
    busy: Boolean,
) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(
            text = "Entwürfe",
            style = MaterialTheme.typography.titleSmall,
            fontWeight = FontWeight.SemiBold,
        )
        drafts.forEach { draft ->
            OutlinedButton(
                onClick = { onSelectDraft(draft.draftId) },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Column(
                    horizontalAlignment = Alignment.Start,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(
                        text = editors[draft.draftId]?.name?.text
                            ?.ifBlank { "Unbenannter Entwurf" }
                            ?: draft.displayName,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                    Text(
                        text = "${statusLabel(draft.status)} · Version ${draft.version}" +
                            if (draft.draftId == selectedDraftId) " · ausgewählt" else "",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

@Composable
internal fun ProductDraftForm(
    draft: ProductDraft,
    editor: ProductDraftEditorState,
    fieldErrors: Map<String, String>,
    busy: Boolean,
    actions: ProductUiActions,
    onPickImages: () -> Unit,
    isPublishedEdit: Boolean = false,
) {
    var showPublishConfirmation by remember(draft.draftId) { mutableStateOf(false) }

    if (showPublishConfirmation) {
        AlertDialog(
            onDismissRequest = { showPublishConfirmation = false },
            title = {
                Text(
                    if (isPublishedEdit) {
                        "Änderungen erneut veröffentlichen?"
                    } else {
                        "Auf Testwebsite veröffentlichen?"
                    },
                )
            },
            text = {
                Text(
                    "Das Produkt wird für die geschützte Testwebsite bereitgestellt. " +
                        "Ein Vinted-Link ist in dieser Testphase optional.",
                )
            },
            confirmButton = {
                Button(
                    onClick = {
                        showPublishConfirmation = false
                        actions.onPublish()
                    },
                ) {
                    Text(
                        if (isPublishedEdit) {
                            "Änderungen erneut veröffentlichen"
                        } else {
                            "Auf Testwebsite veröffentlichen"
                        },
                    )
                }
            },
            dismissButton = {
                OutlinedButton(onClick = { showPublishConfirmation = false }) {
                    Text("Abbrechen")
                }
            },
        )
    }

    Surface(
        shape = MaterialTheme.shapes.small,
        border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(
            verticalArrangement = Arrangement.spacedBy(10.dp),
            modifier = Modifier.padding(12.dp),
        ) {
            Text(
                text = draft.sku ?: "Entwurf ${draft.draftId.take(8)}",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = "Status: ${statusLabel(draft.status)} · Server-Version ${draft.version}",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            ProductTextField(
                value = editor.name,
                onValueChange = actions.onNameChange,
                label = "Produktname",
                testTag = "product-name",
                error = fieldErrors["name"],
                busy = busy,
            )
            ProductTextField(
                value = editor.materials,
                onValueChange = actions.onMaterialsChange,
                label = "Perlen",
                testTag = "product-pearls",
                error = fieldErrors["materials"],
                busy = busy,
                singleLine = false,
            )
            ProductTextField(
                value = editor.metalElements,
                onValueChange = actions.onMetalElementsChange,
                label = "Spacer",
                testTag = "product-spacers",
                busy = busy,
                singleLine = false,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ProductTextField(
                    value = editor.braceletSize,
                    onValueChange = actions.onBraceletSizeChange,
                    label = "Größe",
                    testTag = "product-size",
                    error = fieldErrors["braceletSize"],
                    busy = busy,
                    modifier = Modifier.weight(1f),
                )
                ProductTextField(
                    value = editor.stock,
                    onValueChange = actions.onStockChange,
                    label = "Bestand",
                    testTag = "product-stock",
                    error = fieldErrors["stock"],
                    busy = busy,
                    keyboardType = KeyboardType.Number,
                    modifier = Modifier.weight(1f),
                )
            }
            ProductTextField(
                value = editor.shortDescription,
                onValueChange = actions.onShortDescriptionChange,
                label = "Kurzbeschreibung",
                testTag = "product-short-description",
                error = fieldErrors["shortDescription"],
                busy = busy,
                singleLine = false,
            )
            ProductTextField(
                value = editor.vintedUrl,
                onValueChange = actions.onVintedUrlChange,
                label = "Vinted-Angebotslink (optional)",
                testTag = "product-vinted-url",
                error = fieldErrors["vintedUrl"],
                busy = busy,
            )

            Text(
                text = "Interner kalkulierter Preis: ${draft.internalCalculation.recommendedSalePrice} €",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                text = "Bilder: ${draft.images.size}/5",
                style = MaterialTheme.typography.bodySmall,
                color = if (fieldErrors["images"] == null) {
                    MaterialTheme.colorScheme.onSurfaceVariant
                } else {
                    MaterialTheme.colorScheme.error
                },
            )

            OutlinedButton(
                onClick = onPickImages,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Fotos auswählen")
            }

            Spacer(Modifier.height(4.dp))

            OutlinedButton(
                onClick = actions.onSave,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Speichern")
            }
            Button(
                onClick = actions.onSync,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Speichern und synchronisieren")
            }
            if (isPublishedEdit ||
                draft.status == ProductStatus.Draft ||
                draft.status == ProductStatus.Ready
            ) {
                Button(
                    onClick = { showPublishConfirmation = true },
                    enabled = !busy,
                    modifier = Modifier
                        .fillMaxWidth()
                        .testTag("product-publish"),
                ) {
                    Text(
                        if (isPublishedEdit) {
                            "Änderungen erneut veröffentlichen"
                        } else {
                            "Auf Testwebsite veröffentlichen"
                        },
                    )
                }
            }
        }
    }
}

@Composable
private fun ProductTextField(
    value: TextFieldValue,
    onValueChange: (TextFieldValue) -> Unit,
    label: String,
    testTag: String,
    busy: Boolean,
    modifier: Modifier = Modifier,
    error: String? = null,
    singleLine: Boolean = true,
    keyboardType: KeyboardType = KeyboardType.Text,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        supportingText = error?.let { message -> { Text(message) } },
        isError = error != null,
        enabled = !busy,
        singleLine = singleLine,
        keyboardOptions = KeyboardOptions(keyboardType = keyboardType),
        modifier = modifier
            .fillMaxWidth()
            .testTag(testTag),
    )
}

private fun statusLabel(status: ProductStatus): String {
    return when (status) {
        ProductStatus.Draft -> "Entwurf"
        ProductStatus.Ready -> "bereit"
        ProductStatus.Published -> "veröffentlicht"
        ProductStatus.Sold -> "verkauft"
        ProductStatus.Disabled -> "deaktiviert"
    }
}
