package de.carmajaperlen.armbandrechner

import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue

enum class ProductEditorField {
    Name,
    Materials,
    MetalElements,
    BraceletSize,
    Stock,
    ShortDescription,
    CareInstructions,
    VintedUrl,
}

val ProductEditorField.errorKey: String
    get() = when (this) {
        ProductEditorField.Name -> "name"
        ProductEditorField.Materials -> "materials"
        ProductEditorField.MetalElements -> "metalElements"
        ProductEditorField.BraceletSize -> "braceletSize"
        ProductEditorField.Stock -> "stock"
        ProductEditorField.ShortDescription -> "shortDescription"
        ProductEditorField.CareInstructions -> "careInstructions"
        ProductEditorField.VintedUrl -> "vintedUrl"
    }

data class ProductDraftEditorState(
    val draftId: String,
    val name: TextFieldValue,
    val materials: TextFieldValue,
    val metalElements: TextFieldValue,
    val braceletSize: TextFieldValue,
    val stock: TextFieldValue,
    val shortDescription: TextFieldValue,
    val careInstructions: TextFieldValue,
    val vintedUrl: TextFieldValue,
) {
    fun update(field: ProductEditorField, value: TextFieldValue): ProductDraftEditorState {
        return when (field) {
            ProductEditorField.Name -> copy(name = value)
            ProductEditorField.Materials -> copy(materials = value)
            ProductEditorField.MetalElements -> copy(metalElements = value)
            ProductEditorField.BraceletSize -> copy(braceletSize = value)
            ProductEditorField.Stock -> copy(stock = value)
            ProductEditorField.ShortDescription -> copy(shortDescription = value)
            ProductEditorField.CareInstructions -> copy(careInstructions = value)
            ProductEditorField.VintedUrl -> copy(vintedUrl = value)
        }
    }

    fun validateForSave(): Map<String, String> {
        return buildMap {
            val parsedStock = stock.text.trim().toIntOrNull()
            if (parsedStock == null || parsedStock !in 0..99) {
                put("stock", "Bestand muss eine Zahl zwischen 0 und 99 sein.")
            }
        }
    }

    fun applyTo(draft: ProductDraft): ProductDraft {
        require(draft.draftId == draftId) { "Editor und Entwurf stimmen nicht ueberein." }
        val parsedStock = stock.text.trim().toIntOrNull()
        require(parsedStock != null && parsedStock in 0..99) {
            "Bestand muss eine Zahl zwischen 0 und 99 sein."
        }

        return draft.copy(
            name = name.text.trim(),
            materials = multilineTextToList(materials.text),
            metalElements = multilineTextToList(metalElements.text),
            braceletSize = braceletSize.text.trim(),
            stock = parsedStock,
            shortDescription = shortDescription.text.trim(),
            careInstructions = multilineTextToList(careInstructions.text),
            vintedUrl = vintedUrl.text.trim(),
        )
    }

    companion object {
        fun fromDraft(draft: ProductDraft): ProductDraftEditorState {
            return ProductDraftEditorState(
                draftId = draft.draftId,
                name = editorValue(draft.name),
                materials = editorValue(draft.materials.toMultilineText()),
                metalElements = editorValue(draft.metalElements.toMultilineText()),
                braceletSize = editorValue(draft.braceletSize),
                stock = editorValue(draft.stock.toString()),
                shortDescription = editorValue(draft.shortDescription),
                careInstructions = editorValue(draft.careInstructions.toMultilineText()),
                vintedUrl = editorValue(draft.vintedUrl),
            )
        }
    }
}

data class ProductLoginEditorState(
    val apiBaseUrl: TextFieldValue = TextFieldValue(),
    val username: TextFieldValue = TextFieldValue(),
    val password: TextFieldValue = TextFieldValue(),
    val deviceName: TextFieldValue = editorValue("Android"),
) {
    companion object {
        fun fromStored(apiBaseUrl: String, deviceName: String): ProductLoginEditorState {
            return ProductLoginEditorState(
                apiBaseUrl = editorValue(apiBaseUrl),
                deviceName = editorValue(deviceName),
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
