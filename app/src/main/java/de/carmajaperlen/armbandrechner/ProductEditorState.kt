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
    Price,
}

val ProductEditorField.errorKey: String
    get() = when (this) {
        ProductEditorField.Name -> "name"
        ProductEditorField.Materials -> "materials"
        ProductEditorField.MetalElements -> "metalElements"
        ProductEditorField.BraceletSizeCm -> "braceletSizeCm"
        ProductEditorField.PearlSizeMm -> "pearlSizeMm"
        ProductEditorField.ShortDescription -> "shortDescription"
        ProductEditorField.Price -> "priceMinor"
    }

data class ProductDraftEditorState(
    val draftId: String,
    val name: TextFieldValue,
    val materials: TextFieldValue,
    val metalElements: TextFieldValue,
    val braceletSizeCm: TextFieldValue,
    val pearlSizeMm: TextFieldValue,
    val shortDescription: RichDescriptionEditorState,
    val price: TextFieldValue,
) {
    fun update(field: ProductEditorField, value: TextFieldValue): ProductDraftEditorState {
        return when (field) {
            ProductEditorField.Name -> copy(name = value)
            ProductEditorField.Materials -> copy(materials = value)
            ProductEditorField.MetalElements -> copy(metalElements = value)
            ProductEditorField.BraceletSizeCm -> copy(braceletSizeCm = value)
            ProductEditorField.PearlSizeMm -> copy(pearlSizeMm = value)
            ProductEditorField.ShortDescription -> copy(shortDescription = shortDescription.update(value))
            ProductEditorField.Price -> copy(price = value)
        }
    }

    fun validateForSave(): Map<String, String> {
        return buildMap {
            if (normalizeMeasurement(braceletSizeCm.text) == null) put("braceletSizeCm", "Armbandgröße muss größer als null sein.")
            if (normalizeMeasurement(pearlSizeMm.text) == null) put("pearlSizeMm", "Perlengröße muss größer als null sein.")
            if (parsePriceMinor(price.text) == null) put("priceMinor", "Preis mit höchstens zwei Nachkommastellen, mindestens 0,50 €.")
            if (shortDescription.value.text.isBlank()) put("shortDescription", "Kurzbeschreibung ist erforderlich.")
            if (shortDescription.characterCount > DESCRIPTION_MAX_CHARACTERS) {
                put("shortDescription", "Kurzbeschreibung darf höchstens 500 Zeichen enthalten.")
            }
            runCatching { shortDescription.toDocument() }
                .exceptionOrNull()
                ?.let { put("shortDescription", it.message ?: "Formatierte Beschreibung ist ungültig.") }
        }
    }

    fun applyTo(draft: ProductDraft): ProductDraft {
        require(draft.draftId == draftId) { "Editor und Entwurf stimmen nicht ueberein." }
        val braceletSizeCm = requireNotNull(normalizeMeasurement(braceletSizeCm.text)) { "Armbandgröße muss größer als null sein." }
        val pearlSizeMm = requireNotNull(normalizeMeasurement(pearlSizeMm.text)) { "Perlengröße muss größer als null sein." }
        val priceMinor = requireNotNull(parsePriceMinor(price.text)) { "Verkaufspreis ist ungültig." }

        val descriptionDocument = shortDescription.value.text
            .takeIf { it.isNotBlank() }
            ?.let { shortDescription.toDocument() }
        return draft.copy(
            name = name.text.trim(),
            materials = multilineTextToList(materials.text),
            metalElements = multilineTextToList(metalElements.text)
                .map(::normalizeSpacerLabel)
                .distinct(),
            braceletSizeCm = braceletSizeCm,
            pearlSizeMm = pearlSizeMm,
            modelVersion = if (descriptionDocument == null) draft.modelVersion else PRODUCT_MODEL_VERSION,
            shortDescription = descriptionDocument?.plainText().orEmpty(),
            descriptionDocument = descriptionDocument,
            priceMinor = priceMinor,
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
                shortDescription = RichDescriptionEditorState.fromDocument(
                    draft.descriptionDocument
                        ?: DescriptionDocument.fromPlainText(draft.shortDescription),
                ),
                price = editorValue(displayPriceMinor(draft.priceMinor)),
            )
        }
    }
}

data class ProductLoginEditorState(
    val username: TextFieldValue = TextFieldValue(),
    val password: TextFieldValue = TextFieldValue(),
    val deviceName: TextFieldValue = editorValue("Android"),
    val rememberSession: Boolean = false,
) {
    override fun toString(): String {
        return "ProductLoginEditorState(" +
            "username=$username, " +
            "password=<redacted>, " +
            "deviceName=$deviceName, " +
            "rememberSession=$rememberSession)"
    }

    companion object {
        fun fromStored(
            deviceName: String,
            rememberSession: Boolean = false,
        ): ProductLoginEditorState {
            return ProductLoginEditorState(
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
