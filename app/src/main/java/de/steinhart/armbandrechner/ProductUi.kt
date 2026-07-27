package de.steinhart.armbandrechner

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp

data class ProductUiActions(
    val onCreateFromCalculation: () -> Unit = {},
    val onSelectDraft: (String) -> Unit = {},
    val onApiBaseUrlChange: (String) -> Unit = {},
    val onUsernameChange: (String) -> Unit = {},
    val onPasswordChange: (String) -> Unit = {},
    val onDeviceNameChange: (String) -> Unit = {},
    val onLogin: () -> Unit = {},
    val onNameChange: (String) -> Unit = {},
    val onMaterialsChange: (String) -> Unit = {},
    val onMetalElementsChange: (String) -> Unit = {},
    val onBraceletSizeChange: (String) -> Unit = {},
    val onStockChange: (String) -> Unit = {},
    val onShortDescriptionChange: (String) -> Unit = {},
    val onCareInstructionsChange: (String) -> Unit = {},
    val onVintedUrlChange: (String) -> Unit = {},
    val onImagesPicked: (List<Uri>) -> Unit = {},
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

        Button(
            onClick = actions.onCreateFromCalculation,
            enabled = !state.busy,
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text("Produkt aus Kalkulation erstellen")
        }

        ServerLoginPanel(state = state, actions = actions)

        if (state.drafts.isEmpty()) {
            Text(
                text = "Noch keine Produktentwürfe gespeichert.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        } else {
            DraftList(
                drafts = state.drafts,
                selectedDraftId = state.selectedDraftId,
                onSelectDraft = actions.onSelectDraft,
                busy = state.busy,
            )
        }

        state.selectedDraft?.let { draft ->
            ProductDraftForm(
                draft = draft,
                fieldErrors = state.fieldErrors,
                busy = state.busy,
                actions = actions,
                onPickImages = {
                    imagePicker.launch(
                        PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly),
                    )
                },
            )
        }
    }
}

@Composable
private fun ServerLoginPanel(
    state: ProductUiState,
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
                text = if (state.authenticated) "Server verbunden" else "Server-Anmeldung",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
            )
            OutlinedTextField(
                value = state.apiBaseUrl,
                onValueChange = actions.onApiBaseUrlChange,
                label = { Text("API-URL") },
                singleLine = true,
                enabled = !state.busy,
                modifier = Modifier.fillMaxWidth(),
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = state.username,
                    onValueChange = actions.onUsernameChange,
                    label = { Text("Benutzer") },
                    singleLine = true,
                    enabled = !state.busy,
                    modifier = Modifier.weight(1f),
                )
                OutlinedTextField(
                    value = state.deviceName,
                    onValueChange = actions.onDeviceNameChange,
                    label = { Text("Gerät") },
                    singleLine = true,
                    enabled = !state.busy,
                    modifier = Modifier.weight(1f),
                )
            }
            OutlinedTextField(
                value = state.password,
                onValueChange = actions.onPasswordChange,
                label = { Text("Passwort") },
                visualTransformation = PasswordVisualTransformation(),
                singleLine = true,
                enabled = !state.busy,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedButton(
                onClick = actions.onLogin,
                enabled = !state.busy && state.apiBaseUrl.isNotBlank(),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Anmelden")
            }
        }
    }
}

@Composable
private fun DraftList(
    drafts: List<ProductDraft>,
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
                        text = draft.displayName,
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
private fun ProductDraftForm(
    draft: ProductDraft,
    fieldErrors: Map<String, String>,
    busy: Boolean,
    actions: ProductUiActions,
    onPickImages: () -> Unit,
) {
    var showPublishConfirmation by remember { mutableStateOf(false) }

    if (showPublishConfirmation) {
        AlertDialog(
            onDismissRequest = { showPublishConfirmation = false },
            title = { Text("Produkt veröffentlichen?") },
            text = {
                Text(
                    "Nach der Veröffentlichung wird ein Website-Build gestartet. " +
                        "Der Vinted-Link muss das verbindliche Angebot enthalten.",
                )
            },
            confirmButton = {
                Button(
                    onClick = {
                        showPublishConfirmation = false
                        actions.onPublish()
                    },
                ) {
                    Text("Veröffentlichen")
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
                value = draft.name,
                onValueChange = actions.onNameChange,
                label = "Produktname",
                error = fieldErrors["name"],
                busy = busy,
            )
            ProductTextField(
                value = draft.materials.toMultilineText(),
                onValueChange = actions.onMaterialsChange,
                label = "Materialien",
                error = fieldErrors["materials"],
                busy = busy,
                singleLine = false,
            )
            ProductTextField(
                value = draft.metalElements.toMultilineText(),
                onValueChange = actions.onMetalElementsChange,
                label = "Metallelemente",
                busy = busy,
                singleLine = false,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ProductTextField(
                    value = draft.braceletSize,
                    onValueChange = actions.onBraceletSizeChange,
                    label = "Größe",
                    error = fieldErrors["braceletSize"],
                    busy = busy,
                    modifier = Modifier.weight(1f),
                )
                ProductTextField(
                    value = draft.stock.toString(),
                    onValueChange = actions.onStockChange,
                    label = "Bestand",
                    busy = busy,
                    keyboardType = KeyboardType.Number,
                    modifier = Modifier.weight(1f),
                )
            }
            ProductTextField(
                value = draft.shortDescription,
                onValueChange = actions.onShortDescriptionChange,
                label = "Kurzbeschreibung",
                error = fieldErrors["shortDescription"],
                busy = busy,
                singleLine = false,
            )
            ProductTextField(
                value = draft.careInstructions.toMultilineText(),
                onValueChange = actions.onCareInstructionsChange,
                label = "Pflegehinweise",
                busy = busy,
                singleLine = false,
            )
            ProductTextField(
                value = draft.vintedUrl,
                onValueChange = actions.onVintedUrlChange,
                label = "Vinted-Angebotslink",
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

            Button(
                onClick = actions.onSync,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Speichern und synchronisieren")
            }
            Button(
                onClick = { showPublishConfirmation = true },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Veröffentlichen")
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(
                    onClick = actions.onMarkSold,
                    enabled = !busy && draft.sku != null,
                    modifier = Modifier.weight(1f),
                ) {
                    Text("Verkauft")
                }
                OutlinedButton(
                    onClick = actions.onDisable,
                    enabled = !busy && draft.sku != null,
                    modifier = Modifier.weight(1f),
                ) {
                    Text("Deaktivieren")
                }
            }
        }
    }
}

@Composable
private fun ProductTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
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
        modifier = modifier.fillMaxWidth(),
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
