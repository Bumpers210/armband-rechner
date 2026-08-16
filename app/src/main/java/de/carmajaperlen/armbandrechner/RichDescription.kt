package de.carmajaperlen.armbandrechner

import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.unit.sp
import org.json.JSONArray
import org.json.JSONObject

const val DESCRIPTION_DOCUMENT_VERSION = 1
const val DESCRIPTION_MAX_CHARACTERS = 500
const val DESCRIPTION_MAX_PARAGRAPHS = 25
const val DESCRIPTION_MAX_SPANS = 100
const val DESCRIPTION_MAX_SERIALIZED_BYTES = 16 * 1024

enum class DescriptionFont(val wireName: String) {
    Standard("standard"),
    Elegant("elegant"),
    ;

    companion object {
        fun fromWireName(value: String): DescriptionFont =
            entries.firstOrNull { it.wireName == value }
                ?: throw IllegalArgumentException("Unbekannte Beschreibungsschrift.")
    }
}

enum class DescriptionSize(val wireName: String) {
    Small("small"),
    Normal("normal"),
    Large("large"),
    ;

    companion object {
        fun fromWireName(value: String): DescriptionSize =
            entries.firstOrNull { it.wireName == value }
                ?: throw IllegalArgumentException("Unbekannte Beschreibungsgröße.")
    }
}

data class DescriptionTextStyle(
    val bold: Boolean = false,
    val italic: Boolean = false,
    val font: DescriptionFont = DescriptionFont.Standard,
    val size: DescriptionSize = DescriptionSize.Normal,
) {
    fun toSpanStyle(): SpanStyle = SpanStyle(
        fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal,
        fontStyle = if (italic) FontStyle.Italic else FontStyle.Normal,
        fontFamily = if (font == DescriptionFont.Elegant) FontFamily.Serif else FontFamily.SansSerif,
        fontSize = when (size) {
            DescriptionSize.Small -> 14.sp
            DescriptionSize.Normal -> 16.sp
            DescriptionSize.Large -> 20.sp
        },
    )
}

data class DescriptionSpan(
    val text: String,
    val style: DescriptionTextStyle = DescriptionTextStyle(),
)

data class DescriptionParagraph(
    val spans: List<DescriptionSpan>,
)

data class DescriptionDocument(
    val version: Int = DESCRIPTION_DOCUMENT_VERSION,
    val blocks: List<DescriptionParagraph>,
) {
    fun plainText(): String = blocks.joinToString("\n\n") { block ->
        block.spans.joinToString("") { it.text }
    }

    fun normalized(): DescriptionDocument {
        require(version == DESCRIPTION_DOCUMENT_VERSION) { "Beschreibungsversion ist ungültig." }
        val normalizedBlocks = blocks.mapNotNull { block ->
            val merged = buildList<DescriptionSpan> {
                block.spans.forEach { span ->
                    val cleanText = span.text.replace("\u0000", "")
                    if (cleanText.isEmpty()) return@forEach
                    val previous = lastOrNull()
                    if (previous != null && previous.style == span.style) {
                        removeAt(lastIndex)
                        add(previous.copy(text = previous.text + cleanText))
                    } else {
                        add(span.copy(text = cleanText))
                    }
                }
            }
            merged.takeIf { it.isNotEmpty() }?.let(::DescriptionParagraph)
        }
        val result = DescriptionDocument(blocks = normalizedBlocks)
        result.requireValid()
        return result
    }

    fun requireValid() {
        require(version == DESCRIPTION_DOCUMENT_VERSION) { "Beschreibungsversion ist ungültig." }
        require(blocks.isNotEmpty()) { "Beschreibung ist erforderlich." }
        require(blocks.size <= DESCRIPTION_MAX_PARAGRAPHS) { "Zu viele Absätze." }
        require(blocks.all { it.spans.isNotEmpty() }) { "Leere Absätze sind nicht erlaubt." }
        require(blocks.sumOf { it.spans.size } <= DESCRIPTION_MAX_SPANS) { "Zu viele Formatbereiche." }
        require(plainText().isNotBlank()) { "Beschreibung ist erforderlich." }
        require(plainText().length <= DESCRIPTION_MAX_CHARACTERS) { "Beschreibung ist zu lang." }
        require(toJson().toString().toByteArray(Charsets.UTF_8).size <= DESCRIPTION_MAX_SERIALIZED_BYTES) {
            "Formatierte Beschreibung ist zu groß."
        }
    }

    fun toJson(): JSONObject = JSONObject()
        .put("version", version)
        .put(
            "blocks",
            JSONArray(blocks.map { block ->
                JSONObject()
                    .put("type", "paragraph")
                    .put(
                        "spans",
                        JSONArray(block.spans.map { span ->
                            JSONObject()
                                .put("text", span.text)
                                .put("bold", span.style.bold)
                                .put("italic", span.style.italic)
                                .put("font", span.style.font.wireName)
                                .put("size", span.style.size.wireName)
                        }),
                    )
            }),
        )

    fun toAnnotatedString(): AnnotatedString {
        val builder = AnnotatedString.Builder()
        blocks.forEachIndexed { blockIndex, block ->
            if (blockIndex > 0) builder.append("\n\n")
            block.spans.forEach { span ->
                val start = builder.length
                builder.append(span.text)
                builder.addStyle(span.style.toSpanStyle(), start, builder.length)
            }
        }
        return builder.toAnnotatedString()
    }

    companion object {
        fun fromPlainText(value: String): DescriptionDocument {
            val blocks = value
                .replace("\r\n", "\n")
                .replace('\r', '\n')
                .trim()
                .split(Regex("\\n\\s*\\n+"))
                .map(String::trim)
                .filter(String::isNotEmpty)
                .map { text -> DescriptionParagraph(listOf(DescriptionSpan(text))) }
            return DescriptionDocument(blocks = blocks.ifEmpty {
                listOf(DescriptionParagraph(listOf(DescriptionSpan(""))))
            })
        }

        fun fromJson(json: JSONObject): DescriptionDocument {
            require(json.keys().asSequence().toSet() == setOf("version", "blocks")) {
                "Beschreibung enthält unbekannte Felder."
            }
            val blocksJson = json.getJSONArray("blocks")
            val blocks = (0 until blocksJson.length()).map { blockIndex ->
                val block = blocksJson.getJSONObject(blockIndex)
                require(block.keys().asSequence().toSet() == setOf("type", "spans")) {
                    "Absatz enthält unbekannte Felder."
                }
                require(block.getString("type") == "paragraph") { "Absatztyp ist ungültig." }
                val spansJson = block.getJSONArray("spans")
                DescriptionParagraph(
                    spans = (0 until spansJson.length()).map { spanIndex ->
                        val span = spansJson.getJSONObject(spanIndex)
                        require(
                            span.keys().asSequence().toSet() ==
                                setOf("text", "bold", "italic", "font", "size"),
                        ) { "Textbereich enthält unbekannte Felder." }
                        DescriptionSpan(
                            text = span.getString("text"),
                            style = DescriptionTextStyle(
                                bold = span.getBoolean("bold"),
                                italic = span.getBoolean("italic"),
                                font = DescriptionFont.fromWireName(span.getString("font")),
                                size = DescriptionSize.fromWireName(span.getString("size")),
                            ),
                        )
                    },
                )
            }
            return DescriptionDocument(
                version = json.getInt("version"),
                blocks = blocks,
            ).normalized()
        }
    }
}

data class RichDescriptionEditorState(
    val value: TextFieldValue,
    val typingStyle: DescriptionTextStyle = DescriptionTextStyle(),
) {
    val characterCount: Int get() = value.text.length

    fun update(next: TextFieldValue): RichDescriptionEditorState {
        if (next.text.length > DESCRIPTION_MAX_CHARACTERS) return this
        if (next.text == value.text) return copy(value = next.copy(annotatedString = value.annotatedString))

        val oldText = value.text
        val newText = next.text
        var prefix = 0
        val prefixLimit = minOf(oldText.length, newText.length)
        while (prefix < prefixLimit && oldText[prefix] == newText[prefix]) prefix += 1

        var oldSuffix = oldText.length
        var newSuffix = newText.length
        while (oldSuffix > prefix && newSuffix > prefix && oldText[oldSuffix - 1] == newText[newSuffix - 1]) {
            oldSuffix -= 1
            newSuffix -= 1
        }

        val oldStyles = stylesFor(value.annotatedString)
        val styles = buildList {
            addAll(oldStyles.take(prefix))
            repeat(newSuffix - prefix) { add(typingStyle) }
            addAll(oldStyles.drop(oldSuffix))
        }
        val annotated = annotatedFrom(newText, styles)
        return copy(
            value = TextFieldValue(
                annotatedString = annotated,
                selection = next.selection,
                composition = next.composition,
            ),
        )
    }

    fun toggleBold(): RichDescriptionEditorState = mutateStyle { selected, style ->
        style.copy(bold = if (selected) !selectionStyles().all { it.bold } else !typingStyle.bold)
    }

    fun toggleItalic(): RichDescriptionEditorState = mutateStyle { selected, style ->
        style.copy(italic = if (selected) !selectionStyles().all { it.italic } else !typingStyle.italic)
    }

    fun setFont(font: DescriptionFont): RichDescriptionEditorState = mutateStyle { _, style ->
        style.copy(font = font)
    }

    fun setSize(size: DescriptionSize): RichDescriptionEditorState = mutateStyle { _, style ->
        style.copy(size = size)
    }

    fun currentStyle(): DescriptionTextStyle = selectionStyles().firstOrNull() ?: typingStyle

    fun toDocument(): DescriptionDocument {
        val text = value.text.trim()
        require(text.isNotEmpty()) { "Beschreibung ist erforderlich." }
        val trimmedStart = value.text.indexOfFirst { !it.isWhitespace() }.coerceAtLeast(0)
        val trimmedEnd = value.text.indexOfLast { !it.isWhitespace() } + 1
        val styles = stylesFor(value.annotatedString).subList(trimmedStart, trimmedEnd)
        val paragraphRanges = paragraphRanges(text)
        val blocks = paragraphRanges.map { range ->
            val spanStyles = styles.subList(range.first, range.last + 1)
            DescriptionParagraph(spansFrom(text.substring(range), spanStyles))
        }
        return DescriptionDocument(blocks = blocks).normalized()
    }

    private fun mutateStyle(
        transform: (hasSelection: Boolean, DescriptionTextStyle) -> DescriptionTextStyle,
    ): RichDescriptionEditorState {
        val selection = value.selection
        if (selection.collapsed) {
            return copy(typingStyle = transform(false, typingStyle))
        }
        val start = selection.min.coerceIn(0, value.text.length)
        val end = selection.max.coerceIn(start, value.text.length)
        val styles = stylesFor(value.annotatedString).toMutableList()
        for (index in start until end) styles[index] = transform(true, styles[index])
        return copy(
            value = value.copy(annotatedString = annotatedFrom(value.text, styles)),
            typingStyle = styles.getOrNull(end - 1) ?: typingStyle,
        )
    }

    private fun selectionStyles(): List<DescriptionTextStyle> {
        if (value.selection.collapsed) return emptyList()
        val styles = stylesFor(value.annotatedString)
        return styles.subList(
            value.selection.min.coerceIn(0, styles.size),
            value.selection.max.coerceIn(0, styles.size),
        )
    }

    companion object {
        fun fromDocument(document: DescriptionDocument): RichDescriptionEditorState {
            val annotated = document.toAnnotatedString()
            return RichDescriptionEditorState(
                value = TextFieldValue(
                    annotatedString = annotated,
                    selection = TextRange(annotated.length),
                ),
            )
        }

        fun fromPlainText(value: String): RichDescriptionEditorState =
            fromDocument(DescriptionDocument.fromPlainText(value))
    }
}

private fun paragraphRanges(value: String): List<IntRange> {
    val ranges = mutableListOf<IntRange>()
    var start = 0
    Regex("\\n\\s*\\n+").findAll(value).forEach { match ->
        val end = match.range.first
        if (end > start) ranges += start until end
        start = match.range.last + 1
    }
    if (start < value.length) ranges += start until value.length
    return ranges
}

private fun spansFrom(text: String, styles: List<DescriptionTextStyle>): List<DescriptionSpan> {
    if (text.isEmpty()) return emptyList()
    val spans = mutableListOf<DescriptionSpan>()
    var start = 0
    while (start < text.length) {
        val style = styles.getOrElse(start) { DescriptionTextStyle() }
        var end = start + 1
        while (end < text.length && styles.getOrElse(end) { DescriptionTextStyle() } == style) end += 1
        spans += DescriptionSpan(text.substring(start, end), style)
        start = end
    }
    return spans
}

private fun stylesFor(value: AnnotatedString): List<DescriptionTextStyle> {
    val styles = MutableList(value.length) { DescriptionTextStyle() }
    value.spanStyles.forEach { range ->
        val style = DescriptionTextStyle(
            bold = range.item.fontWeight == FontWeight.Bold,
            italic = range.item.fontStyle == FontStyle.Italic,
            font = if (range.item.fontFamily == FontFamily.Serif) {
                DescriptionFont.Elegant
            } else {
                DescriptionFont.Standard
            },
            size = when (range.item.fontSize) {
                14.sp -> DescriptionSize.Small
                20.sp -> DescriptionSize.Large
                else -> DescriptionSize.Normal
            },
        )
        for (index in range.start.coerceAtLeast(0) until range.end.coerceAtMost(value.length)) {
            styles[index] = style
        }
    }
    return styles
}

private fun annotatedFrom(text: String, styles: List<DescriptionTextStyle>): AnnotatedString {
    val builder = AnnotatedString.Builder(text)
    if (text.isEmpty()) return builder.toAnnotatedString()
    var start = 0
    while (start < text.length) {
        val style = styles.getOrElse(start) { DescriptionTextStyle() }
        var end = start + 1
        while (end < text.length && styles.getOrElse(end) { DescriptionTextStyle() } == style) end += 1
        builder.addStyle(style.toSpanStyle(), start, end)
        start = end
    }
    return builder.toAnnotatedString()
}
