package de.carmajaperlen.armbandrechner

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.dp

@Composable
internal fun ProductConflictDialog(review: ProductConflictReview, busy: Boolean, actions: ProductUiActions) {
    var choice by remember(review) { mutableStateOf<ProductConflictChoice?>(null) }
    AlertDialog(
        onDismissRequest = { if (!busy) actions.onDismissConflict() },
        title = { Text("Fassungen vergleichen") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp),
                modifier = Modifier.verticalScroll(rememberScrollState()).testTag("conflict-comparison")) {
                Text("${review.local.displayName}: lokal Produktversion ${review.local.productVersion}, " +
                    "Server Produktversion ${review.server.productVersion}.")
                Text("Es wurde nichts überschrieben. Wähle bewusst eine Fassung. " +
                    "Vor der Übernahme wird dein lokaler Entwurf mit allen Bildern auf diesem Gerät gesichert. " +
                    "Der Abgleich lädt nichts hoch und ändert keinen Bestand.")
                comparisonFields(review).forEach { (label, local, server) ->
                    Text(label, style = MaterialTheme.typography.titleSmall)
                    Text("Auf diesem Gerät: $local\nAuf dem Server: $server",
                        style = MaterialTheme.typography.bodySmall)
                }
                OutlinedButton(onClick = { choice = ProductConflictChoice.LoadServer }, enabled = !busy,
                    modifier = Modifier.fillMaxWidth().testTag("conflict-choose-server")) {
                    Text("Serverfassung laden")
                }
                OutlinedButton(onClick = { choice = ProductConflictChoice.KeepLocal }, enabled = !busy,
                    modifier = Modifier.fillMaxWidth().testTag("conflict-choose-local")) {
                    Text("Eigene Fassung weiterbearbeiten")
                }
                choice?.let {
                    Text(if (it == ProductConflictChoice.LoadServer) {
                        "Auswahl: Serverfassung. Die bisherige lokale Fassung bleibt als Sicherung erhalten."
                    } else {
                        "Auswahl: Eigene Fassung. Beim späteren Speichern ersetzt sie die bearbeitbaren " +
                            "Serverfelder einschließlich Preis, Verkaufsfreigabe und Bilder. " +
                            "Bitte zuvor alle Unterschiede prüfen. Jetzt wird noch nichts gespeichert oder veröffentlicht."
                    })
                }
            }
        },
        confirmButton = {
            TextButton(onClick = { choice?.let(actions.onResolveConflict) }, enabled = !busy && choice != null,
                modifier = Modifier.testTag("conflict-confirm")) { Text("Auswahl übernehmen") }
        },
        dismissButton = {
            TextButton(onClick = actions.onDismissConflict, enabled = !busy,
                modifier = Modifier.testTag("conflict-cancel")) { Text("Abbrechen – nichts ändern") }
        },
    )
}

internal fun comparisonFields(review: ProductConflictReview): List<Triple<String, String, String>> {
    val local = review.local
    val server = review.server
    return listOf(
        Triple("Name", local.name, server.name),
        Triple("Preis", "${displayPriceMinor(local.priceMinor)} ${local.currency}",
            "${displayPriceMinor(server.priceMinor)} ${server.currency}"),
        Triple("Verkaufsfreigabe", if (local.salesEnabled) "an" else "aus", if (server.salesEnabled) "an" else "aus"),
        Triple("Materialien", local.materials.joinToString(), server.materials.joinToString()),
        Triple("Metallelemente", local.metalElements.joinToString(), server.metalElements.joinToString()),
        Triple("Armbandumfang", "${local.braceletSizeCm} cm", "${server.braceletSizeCm} cm"),
        Triple("Perlengröße", "${local.pearlSizeMm} mm", "${server.pearlSizeMm} mm"),
        Triple("Beschreibung", local.shortDescription, server.shortDescription),
        Triple("Textformatierung", if (local.descriptionDocument == server.descriptionDocument) "gleich" else "abweichend",
            if (local.descriptionDocument == server.descriptionDocument) "gleich" else "abweichend"),
        Triple("Pflegehinweise", local.careInstructions.joinToString("\n"), server.careInstructions.joinToString("\n")),
        Triple("Bilder (Reihenfolge)", local.images.joinToString("\n") { "${it.alt} · ${it.imageId}" },
            server.images.joinToString("\n") { "${it.alt} · ${it.imageId}" }),
        Triple("Produkthinweise (serververwaltet)", local.notices.joinToString("\n"), server.notices.joinToString("\n")),
    )
}
