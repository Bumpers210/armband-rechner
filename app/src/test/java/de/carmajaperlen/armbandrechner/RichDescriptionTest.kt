package de.carmajaperlen.armbandrechner

import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.TextFieldValue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test

class RichDescriptionTest {
    @Test
    fun selectedTextCanCombineAllAllowedStyles() {
        val editor = RichDescriptionEditorState.fromPlainText("Rosenquarz Armband").copy(
            value = RichDescriptionEditorState.fromPlainText("Rosenquarz Armband").value.copy(
                selection = TextRange(0, 10),
            ),
        )

        val formatted = editor
            .toggleBold()
            .toggleItalic()
            .setFont(DescriptionFont.Elegant)
            .setSize(DescriptionSize.Large)
            .toDocument()

        assertEquals(2, formatted.blocks.single().spans.size)
        val styled = formatted.blocks.single().spans.first()
        assertEquals("Rosenquarz", styled.text)
        assertTrue(styled.style.bold)
        assertTrue(styled.style.italic)
        assertEquals(DescriptionFont.Elegant, styled.style.font)
        assertEquals(DescriptionSize.Large, styled.style.size)
        assertEquals(" Armband", formatted.blocks.single().spans.last().text)
    }

    @Test
    fun collapsedSelectionAppliesStyleOnlyToNewTyping() {
        val original = RichDescriptionEditorState.fromPlainText("Achat").copy(
            value = RichDescriptionEditorState.fromPlainText("Achat").value.copy(
                selection = TextRange(5),
            ),
        )
        val prepared = original.toggleBold().setFont(DescriptionFont.Elegant)
        val typed = prepared.update(
            TextFieldValue(text = "Achat neu", selection = TextRange(9)),
        )

        val spans = typed.toDocument().blocks.single().spans
        assertEquals("Achat", spans[0].text)
        assertFalse(spans[0].style.bold)
        assertEquals(" neu", spans[1].text)
        assertTrue(spans[1].style.bold)
        assertEquals(DescriptionFont.Elegant, spans[1].style.font)
    }

    @Test
    fun pastedAnnotationsAreDiscarded() {
        val editor = RichDescriptionEditorState.fromPlainText("Start ").copy(
            value = RichDescriptionEditorState.fromPlainText("Start ").value.copy(
                selection = TextRange(6),
            ),
        )
        val pasted = AnnotatedString.Builder("Start fremd").apply {
            addStyle(SpanStyle(fontWeight = FontWeight.Bold), 6, 11)
        }.toAnnotatedString()

        val updated = editor.update(
            TextFieldValue(annotatedString = pasted, selection = TextRange(11)),
        )

        assertFalse(updated.toDocument().blocks.single().spans.single().style.bold)
    }

    @Test
    fun deletionPreservesFormattingAroundDeletedText() {
        val base = RichDescriptionEditorState.fromPlainText("Rot Blau").copy(
            value = RichDescriptionEditorState.fromPlainText("Rot Blau").value.copy(
                selection = TextRange(4, 8),
            ),
        ).toggleItalic()

        val deleted = base.update(TextFieldValue(text = "Rot ", selection = TextRange(4)))

        assertEquals("Rot", deleted.toDocument().plainText())
        assertFalse(deleted.toDocument().blocks.single().spans.single().style.italic)
    }

    @Test
    fun paragraphsAndJsonRoundTripPreserveTextAndStyles() {
        val document = DescriptionDocument(
            blocks = listOf(
                DescriptionParagraph(
                    listOf(
                        DescriptionSpan("Erster "),
                        DescriptionSpan(
                            "Absatz",
                            DescriptionTextStyle(bold = true, size = DescriptionSize.Large),
                        ),
                    ),
                ),
                DescriptionParagraph(
                    listOf(
                        DescriptionSpan(
                            "Zweiter Absatz",
                            DescriptionTextStyle(italic = true, font = DescriptionFont.Elegant),
                        ),
                    ),
                ),
            ),
        )

        val restored = DescriptionDocument.fromJson(document.toJson())

        assertEquals(document, restored)
        assertEquals("Erster Absatz\n\nZweiter Absatz", restored.plainText())
    }

    @Test
    fun editorRejectsTextBeyondFiveHundredCharacters() {
        val editor = RichDescriptionEditorState.fromPlainText("a".repeat(DESCRIPTION_MAX_CHARACTERS))

        val rejected = editor.update(
            TextFieldValue(
                text = "a".repeat(DESCRIPTION_MAX_CHARACTERS) + "b",
                selection = TextRange(DESCRIPTION_MAX_CHARACTERS + 1),
            ),
        )

        assertEquals(DESCRIPTION_MAX_CHARACTERS, rejected.characterCount)
    }

    @Test
    fun documentRejectsMoreThanTwentyFiveParagraphs() {
        val document = DescriptionDocument.fromPlainText(
            (1..26).joinToString("\n\n") { "Absatz $it" },
        )

        assertThrows(IllegalArgumentException::class.java) { document.requireValid() }
    }
}
