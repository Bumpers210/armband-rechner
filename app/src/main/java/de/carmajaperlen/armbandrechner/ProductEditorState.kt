package de.carmajaperlen.armbandrechner

import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue

enum class ProductEditorField {
    Name,
    Materials,
    MetalElements,
    BraceletSizeCm,
    PearlSizeMm,
    ShortDescription,
}

val ProductEditorField.errorKey: String
    get() = when (this) {
        ProductEditorField.Name -> "name"
        ProductEditorField.Materials -> "materials"
        ProductEditorField.MetalElements -> "metalElements"
        ProductEditorField.BraceletSizeCm -> "braceletSizeCm"
        ProductEditorField.PearlSizeMm -> "pearlSizeMm"
        ProductEditorField.ShortDescription -> "shortDescription"
    }

data class ProductDraftEditorState(
    val draftId: String,
    val name: TextFieldValue,
    val materials: TextFieldValue,
    val metalElements: TextFieldValue,
    val braceletSizeCm: TextFieldValue,
    val pearlSizeMm: TextFieldValue,
    val shortDescription: TextFieldValue,
) {
    fun update(field: ProductEditorField, value: TextFieldValue): ProductDraftEditorState {
        return when (field) {
            ProductEditorField.Name -> copy(name = value)
            ProductEditorField.Materials -> copy(materials = value)
            ProductEditorField.MetalElements -> copy(metalElements = value)
            ProductEditorField.BraceletSizeCm -> copy(braceletSizeCm = value)
            ProductEditorField.PearlSizeMm -> copy(pearlSizeMm = value)
            ProductEditorField.ShortDescription -> copy(shortDescription = value)
        }
    }

    fun validateForSave(): Map<String, String> {
        return buildMap {
            if (normalizeMeasurement(braceletSizeCm.text) == null) put("braceletSizeCm", "Armbandgröße muss größer als null sein.")
            if (normalizeMeasurement(pearlSizeMm.text) == null) put("pearlSizeMm", "Perlengröße muss größer als null sein.")
        }
    }

    fun applyTo(draft: ProductDraft): ProductDraft {
        require(draft.draftId == draftId) { "Editor und Entwurf stimmen nicht ueberein." }
        val braceletSizeCm = requireNotNull(normalizeMeasurement(braceletSizeCm.text)) { "Armbandgröße muss größer als null sein." }
        val pearlSizeMm = requireNotNull(normalizeMeasurement(pearlSizeMm.text)) { "Perlengröße muss größer als null sein." }

        return draft.copy(
            name = name.text.trim(),
            materials = multilineTextToList(materials.text),
            metalElements = multilineTextToList(metalElements.text)
                .map(::normalizeSpacerLabel)
                .distinct(),
            braceletSizeCm = braceletSizeCm,
            pearlSizeMm = pearlSizeMm,
            shortDescription = shortDescription.text.trim(),
        )
    }

    companion object {
        fun fromDraft(draft: ProductDraft): ProductDraftEditorState {
            return ProductDraftEditorState(
                draftId = draft.draftId,
                name = editorValue(draft.name),
                materials = editorValue(draft.materials.toMultilineText()),
                metalElements = editorValue(draft.metalElements.toMultilineText()),
                braceletSizeCm = editorValue(draft.braceletSizeCm.replace('.', ',')),
                pearlSizeMm = editorValue(draft.pearlSizeMm.replace('.', ',')),
                shortDescription = editorValue(draft.shortDescription),
            )
        }
    }
}

data class ProductLoginEditorState(
    val apiBaseUrl: TextFieldValue = TextFieldValue(),
    val username: TextFieldValue = TextFieldValue(),
    val password: TextFieldValue = TextFieldValue(),
    val deviceName: TextFieldValue = editorValue("Android"),
    val rememberSession: Boolean = false,
) {
    override fun toString(): String {
        return "ProductLoginEditorState(" +
            "apiBaseUrl=$apiBaseUrl, " +
            "username=$username, " +
            "password=<redacted>, " +
            "deviceName=$deviceName, " +
            "rememberSession=$rememberSession)"
    }

    companion object {
        fun fromStored(
            apiBaseUrl: String,
            deviceName: String,
            rememberSession: Boolean = false,
        ): ProductLoginEditorState {
            return ProductLoginEditorState(
                apiBaseUrl = editorValue(apiBaseUrl),
                deviceName = editorValue(deviceName),
                rememberSession = rememberSession,
            )
        }
    }
}

private fun editorValue(text: String): TextFieldValue {
    return TextFieldValue(
        text = text,
        selection = TextRange(text.length),
    )
}
