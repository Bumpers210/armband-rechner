package de.carmajaperlen.armbandrechner

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalIconButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import java.math.BigDecimal
import java.text.DateFormat
import java.text.NumberFormat
import java.util.Date
import java.util.Locale

class MainActivity : ComponentActivity() {
    private val viewModel: CalculatorViewModel by viewModels {
        CalculatorViewModel.factory(applicationContext)
    }
    private val productViewModel: ProductViewModel by viewModels {
        ProductViewModel.factory(applicationContext)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            ArmbandRechnerTheme {
                val state by viewModel.uiState.collectAsStateWithLifecycle()
                val productState by productViewModel.uiState.collectAsStateWithLifecycle()
                val productActions = ProductUiActions(
                    onCreateFromCalculation = {
                        productViewModel.createFromCalculation(
                            state.prices,
                            state.calculator,
                            state.totals,
                        )
                    },
                    onSelectDraft = productViewModel::selectDraft,
                    onUsernameChange = productViewModel::updateUsername,
                    onPasswordChange = productViewModel::updatePassword,
                    onDeviceNameChange = productViewModel::updateDeviceName,
                    onRememberSessionChange = productViewModel::updateRememberSession,
                    onLogin = productViewModel::login,
                    onLogout = productViewModel::logout,
                    onEdit = productViewModel::beginEditingSelected,
                    onNameChange = { value ->
                        productViewModel.updateSelectedEditor(ProductEditorField.Name, value)
                    },
                    onMaterialsChange = { value ->
                        productViewModel.updateSelectedEditor(ProductEditorField.Materials, value)
                    },
                    onMetalElementsChange = { value ->
                        productViewModel.updateSelectedEditor(
                            ProductEditorField.MetalElements,
                            value,
                        )
                    },
                    onBraceletSizeCmChange = { value ->
                        productViewModel.updateSelectedEditor(
                            ProductEditorField.BraceletSizeCm,
                            value,
                        )
                    },
                    onPearlSizeMmChange = { value ->
                        productViewModel.updateSelectedEditor(ProductEditorField.PearlSizeMm, value)
                    },
                    onShortDescriptionChange = { value ->
                        productViewModel.updateSelectedEditor(
                            ProductEditorField.ShortDescription,
                            value,
                        )
                    },
                    onImagesPicked = productViewModel::addImages,
                    onSave = productViewModel::saveSelected,
                    onSync = productViewModel::syncSelected,
                    onPublish = productViewModel::publishSelected,
                    onMarkSold = productViewModel::markSelectedSold,
                    onDisable = productViewModel::disableSelected,
                    onDiscardSelected = productViewModel::discardSelected,
                    onMessageShown = productViewModel::consumeMessage,
                )
                if (productState.authenticated) {
                    ArmbandCalculatorScreen(
                        state = state,
                        productState = productState,
                        onRefresh = viewModel::refreshPriceList,
                        onQuantityChange = viewModel::changeQuantity,
                        onWorkMinutesChange = viewModel::updateWorkMinutes,
                        onHourlyRateChange = viewModel::updateHourlyRate,
                        onOtherCostsChange = viewModel::updateOtherCosts,
                        onMarkupChange = viewModel::updateMarkup,
                        onNewCalculation = viewModel::newCalculation,
                        onNoticeShown = viewModel::consumeNotice,
                        productActions = productActions,
                    )
                } else {
                    ProductLoginScreen(
                        state = productState,
                        actions = productActions,
                    )
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ArmbandCalculatorScreen(
    state: AppUiState,
    productState: ProductUiState? = null,
    onRefresh: () -> Unit,
    onQuantityChange: (String, Int) -> Unit,
    onWorkMinutesChange: (String) -> Unit,
    onHourlyRateChange: (String) -> Unit,
    onOtherCostsChange: (String) -> Unit,
    onMarkupChange: (String) -> Unit,
    onNewCalculation: () -> Unit,
    onNoticeShown: () -> Unit,
    productActions: ProductUiActions = ProductUiActions.noop(),
) {
    val snackbarHostState = remember { SnackbarHostState() }
    val pearlPrices = state.prices.filterNot(PriceItem::isSpacer)
    val spacerPrices = state.prices.filter(PriceItem::isSpacer)
    var pearlsExpanded by rememberSaveable { mutableStateOf(true) }
    var spacersExpanded by rememberSaveable { mutableStateOf(true) }
    LaunchedEffect(state.notice) {
        state.notice?.let {
            snackbarHostState.showSnackbar(it)
            onNoticeShown()
        }
    }
    LaunchedEffect(productState?.message, productState?.error) {
        val message = productState?.message ?: productState?.error
        message?.let {
            snackbarHostState.showSnackbar(it)
            productActions.onMessageShown()
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        text = if (BuildConfig.PRODUCT_PUBLISH_TARGET == "test") {
                            "Carmaja Test"
                        } else {
                            "Armband-Rechner"
                        },
                        fontWeight = FontWeight.SemiBold,
                    )
                },
                actions = {
                    if (productState != null) {
                        TextButton(
                            onClick = productActions.onLogout,
                            enabled = !productState.busy,
                            modifier = Modifier.testTag("product-logout"),
                        ) {
                            Text("Abmelden")
                        }
                    }
                    IconButton(
                        onClick = onRefresh,
                        enabled = !state.refreshing,
                        modifier = Modifier.testTag("refresh-prices"),
                    ) {
                        Icon(
                            imageVector = Icons.Default.Refresh,
                            contentDescription = "Preisliste aktualisieren",
                        )
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.surface,
                ),
            )
        },
        snackbarHost = { SnackbarHost(snackbarHostState) },
    ) { contentPadding ->
        LazyColumn(
            modifier = Modifier
                .fillMaxSize()
                .padding(contentPadding),
            contentPadding = PaddingValues(bottom = 32.dp),
        ) {
            item {
                SyncStatus(
                    state = state,
                    modifier = Modifier.fillMaxWidth(),
                )
            }

            item {
                CollapsibleSectionHeading(
                    text = "Perlen",
                    expanded = pearlsExpanded,
                    onToggle = { pearlsExpanded = !pearlsExpanded },
                    modifier = Modifier.padding(
                        start = 16.dp,
                        top = 20.dp,
                        end = 16.dp,
                        bottom = 10.dp,
                    ),
                )
            }

            when {
                state.loadingStoredData -> item {
                    LoadingPriceList()
                }

                state.prices.isEmpty() -> item {
                    EmptyPriceList(
                        hasError = state.refreshError != null,
                        refreshing = state.refreshing,
                        onRetry = onRefresh,
                    )
                }

                pearlsExpanded -> items(
                    items = pearlPrices,
                    key = { it.name },
                ) { item ->
                    PriceItemRow(
                        item = item,
                        quantity = state.calculator.quantities[item.name] ?: 0,
                        onQuantityChange = onQuantityChange,
                        modifier = Modifier.padding(
                            horizontal = 16.dp,
                            vertical = 4.dp,
                        ),
                    )
                }
            }

            if (!state.loadingStoredData && spacerPrices.isNotEmpty()) {
                item {
                    CollapsibleSectionHeading(
                        text = "Edelsteinspacer",
                        expanded = spacersExpanded,
                        onToggle = { spacersExpanded = !spacersExpanded },
                        modifier = Modifier.padding(
                            start = 16.dp,
                            top = 20.dp,
                            end = 16.dp,
                            bottom = 10.dp,
                        ),
                    )
                }
                if (spacersExpanded) items(
                    items = spacerPrices,
                    key = { it.name },
                ) { item ->
                    PriceItemRow(
                        item = item,
                        quantity = state.calculator.quantities[item.name] ?: 0,
                        onQuantityChange = onQuantityChange,
                        modifier = Modifier.padding(
                            horizontal = 16.dp,
                            vertical = 4.dp,
                        ),
                    )
                }
            }

            item {
                HorizontalDivider(modifier = Modifier.padding(top = 20.dp))
                SectionHeading(
                    text = "Arbeits- und Nebenkosten",
                    modifier = Modifier.padding(
                        start = 16.dp,
                        top = 20.dp,
                        end = 16.dp,
                        bottom = 12.dp,
                    ),
                )
                CalculatorInputs(
                    values = state.calculator,
                    onWorkMinutesChange = onWorkMinutesChange,
                    onHourlyRateChange = onHourlyRateChange,
                    onOtherCostsChange = onOtherCostsChange,
                    onMarkupChange = onMarkupChange,
                )
            }

            item {
                HorizontalDivider(modifier = Modifier.padding(top = 24.dp))
                CostSummary(
                    totals = state.totals,
                    modifier = Modifier.padding(top = 4.dp),
                )
            }

            item {
                OutlinedButton(
                    onClick = onNewCalculation,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 20.dp)
                        .height(52.dp)
                        .testTag("new-calculation"),
                ) {
                    Text("Neue Kalkulation")
                }
            }

            if (productState != null) {
                item {
                    HorizontalDivider(modifier = Modifier.padding(top = 4.dp))
                    ProductManagementSection(
                        state = productState,
                        actions = productActions,
                    )
                }
            }
        }
    }
}

@Composable
private fun SyncStatus(
    state: AppUiState,
    modifier: Modifier = Modifier,
) {
    val isError = state.refreshError != null
    val background = when {
        isError && state.prices.isEmpty() -> MaterialTheme.colorScheme.errorContainer
        isError -> MaterialTheme.colorScheme.secondaryContainer
        else -> MaterialTheme.colorScheme.surfaceVariant
    }
    val foreground = when {
        isError && state.prices.isEmpty() -> MaterialTheme.colorScheme.onErrorContainer
        isError -> MaterialTheme.colorScheme.onSecondaryContainer
        else -> MaterialTheme.colorScheme.onSurfaceVariant
    }
    val text = when {
        state.loadingStoredData -> "Lokale Daten werden geladen ..."
        state.refreshing && state.prices.isEmpty() -> "Preisliste wird geladen ..."
        state.refreshing -> "Preisliste wird aktualisiert ..."
        state.refreshError != null && state.prices.isNotEmpty() ->
            "${state.refreshError}. Gespeicherter Stand ${formatSyncTime(state.lastSyncMillis)} bleibt aktiv."
        state.refreshError != null -> "${state.refreshError}. Bitte erneut versuchen."
        state.lastSyncMillis != null ->
            "${state.prices.size} aktive Einträge · Stand ${formatSyncTime(state.lastSyncMillis)}"
        else -> "Noch keine Preisliste gespeichert."
    }

    Surface(
        color = background,
        contentColor = foreground,
        modifier = modifier,
    ) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 10.dp),
        ) {
            if (state.loadingStoredData || state.refreshing) {
                CircularProgressIndicator(
                    modifier = Modifier.size(18.dp),
                    strokeWidth = 2.dp,
                    color = foreground,
                )
                Spacer(Modifier.width(10.dp))
            }
            Text(
                text = text,
                style = MaterialTheme.typography.bodySmall,
                modifier = Modifier.testTag("sync-status"),
            )
        }
    }
}

@Composable
private fun LoadingPriceList() {
    Row(
        horizontalArrangement = Arrangement.Center,
        modifier = Modifier
            .fillMaxWidth()
            .padding(32.dp),
    ) {
        CircularProgressIndicator()
    }
}

@Composable
private fun EmptyPriceList(
    hasError: Boolean,
    refreshing: Boolean,
    onRetry: () -> Unit,
) {
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 24.dp, vertical = 24.dp),
    ) {
        Text(
            text = if (hasError) {
                "Ohne gespeicherte Preisliste ist noch keine Kalkulation möglich."
            } else {
                "Die Preisliste enthält keine aktiven Einträge."
            },
            style = MaterialTheme.typography.bodyMedium,
        )
        Spacer(Modifier.height(16.dp))
        Button(
            onClick = onRetry,
            enabled = !refreshing,
            modifier = Modifier.height(48.dp),
        ) {
            Text("Erneut laden")
        }
    }
}

@Composable
private fun PriceItemRow(
    item: PriceItem,
    quantity: Int,
    onQuantityChange: (String, Int) -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        shape = RoundedCornerShape(8.dp),
        border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
        modifier = modifier.fillMaxWidth(),
    ) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 10.dp, vertical = 10.dp),
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = item.name,
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.Medium,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    text = "${formatMoney(item.unitPrice)} / Stück",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Spacer(Modifier.width(8.dp))
            FilledTonalIconButton(
                onClick = { onQuantityChange(item.name, -1) },
                enabled = quantity > 0,
                modifier = Modifier
                    .size(52.dp)
                    .testTag("minus-${item.name}")
                    .semantics { contentDescription = "${item.name} entfernen" },
                colors = IconButtonDefaults.filledTonalIconButtonColors(),
            ) {
                Text(
                    text = "−",
                    fontSize = 28.sp,
                    modifier = Modifier.testTag("minus-icon-${item.name}"),
                )
            }
            Text(
                text = quantity.toString(),
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
                modifier = Modifier
                    .width(44.dp)
                    .testTag("quantity-${item.name}"),
                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            )
            FilledTonalIconButton(
                onClick = { onQuantityChange(item.name, 1) },
                modifier = Modifier
                    .size(52.dp)
                    .testTag("plus-${item.name}"),
                colors = IconButtonDefaults.filledTonalIconButtonColors(),
            ) {
                Icon(
                    imageVector = Icons.Default.Add,
                    contentDescription = "${item.name} hinzufügen",
                )
            }
        }
    }
}

@Composable
private fun CalculatorInputs(
    values: CalculatorValues,
    onWorkMinutesChange: (String) -> Unit,
    onHourlyRateChange: (String) -> Unit,
    onOtherCostsChange: (String) -> Unit,
    onMarkupChange: (String) -> Unit,
) {
    Column(
        verticalArrangement = Arrangement.spacedBy(10.dp),
        modifier = Modifier.padding(horizontal = 16.dp),
    ) {
        DecimalField(
            value = values.workMinutes,
            onValueChange = onWorkMinutesChange,
            label = "Arbeitszeit",
            suffix = "Minuten",
            testTag = "work-minutes",
        )
        DecimalField(
            value = values.hourlyRate,
            onValueChange = onHourlyRateChange,
            label = "Stundenlohn",
            suffix = "€/Std.",
            testTag = "hourly-rate",
        )
        DecimalField(
            value = values.otherCosts,
            onValueChange = onOtherCostsChange,
            label = "Sonstige Kosten",
            suffix = "€",
            supportingText = "Band, Verschluss und Verpackung",
            testTag = "other-costs",
        )
        DecimalField(
            value = values.markupPercent,
            onValueChange = onMarkupChange,
            label = "Gewinnaufschlag",
            suffix = "%",
            testTag = "markup-percent",
        )
    }
}

@Composable
private fun DecimalField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    suffix: String,
    testTag: String,
    supportingText: String? = null,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        suffix = { Text(suffix) },
        supportingText = supportingText?.let { text -> { Text(text) } },
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
        modifier = Modifier
            .fillMaxWidth()
            .testTag(testTag),
    )
}

@Composable
private fun CostSummary(
    totals: CalculatorTotals,
    modifier: Modifier = Modifier,
) {
    Surface(
        color = MaterialTheme.colorScheme.surfaceVariant,
        modifier = modifier.fillMaxWidth(),
    ) {
        Column(modifier = Modifier.padding(horizontal = 16.dp, vertical = 18.dp)) {
            SectionHeading(text = "Kostenübersicht")
            Spacer(Modifier.height(12.dp))
            CostRow("Materialkosten", totals.materialCosts)
            CostRow("Arbeitskosten", totals.laborCosts)
            CostRow("Sonstige Kosten", totals.otherCosts)
            HorizontalDivider(modifier = Modifier.padding(vertical = 8.dp))
            CostRow("Gesamtkosten", totals.totalCosts, emphasized = true)
            CostRow("Gewinnaufschlag", totals.profit)
            HorizontalDivider(modifier = Modifier.padding(vertical = 8.dp))
            CostRow("Exakter Verkaufspreis", totals.exactSalePrice, emphasized = true)
            Spacer(Modifier.height(16.dp))
            Text(
                text = "Empfohlener Verkaufspreis",
                style = MaterialTheme.typography.labelLarge,
                color = MaterialTheme.colorScheme.primary,
            )
            Text(
                text = formatMoney(totals.recommendedSalePrice),
                style = MaterialTheme.typography.headlineMedium.copy(
                    fontSize = 30.sp,
                    letterSpacing = 0.sp,
                ),
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.primary,
                modifier = Modifier.testTag("recommended-price"),
            )
        }
    }
}

@Composable
private fun CostRow(
    label: String,
    value: BigDecimal,
    emphasized: Boolean = false,
) {
    Row(
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 3.dp),
    ) {
        Text(
            text = label,
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = if (emphasized) FontWeight.SemiBold else FontWeight.Normal,
        )
        Text(
            text = formatMoney(value),
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = if (emphasized) FontWeight.Bold else FontWeight.Medium,
        )
    }
}

@Composable
internal fun SectionHeading(
    text: String,
    modifier: Modifier = Modifier,
) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleMedium,
        fontWeight = FontWeight.SemiBold,
        modifier = modifier,
    )
}

@Composable
internal fun CollapsibleSectionHeading(
    text: String,
    expanded: Boolean,
    onToggle: () -> Unit,
    modifier: Modifier = Modifier,
) {
    TextButton(
        onClick = onToggle,
        modifier = modifier
            .fillMaxWidth()
            .semantics { contentDescription = "$text ${if (expanded) "ausgeklappt" else "eingeklappt"}" },
    ) {
        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text(text, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
            Text(if (expanded) "Ausgeklappt ▲" else "Eingeklappt ▼")
        }
    }
}

private fun formatMoney(value: BigDecimal): String {
    return NumberFormat.getCurrencyInstance(Locale.GERMANY).format(value)
}

private fun formatSyncTime(timestamp: Long?): String {
    if (timestamp == null) return "unbekannt"
    return DateFormat.getDateTimeInstance(
        DateFormat.SHORT,
        DateFormat.SHORT,
        Locale.GERMANY,
    ).format(Date(timestamp))
}

private val LightColors = lightColorScheme(
    primary = Color(0xFF006B5E),
    onPrimary = Color.White,
    primaryContainer = Color(0xFF9EF2E0),
    onPrimaryContainer = Color(0xFF00201B),
    secondary = Color(0xFF9C432D),
    onSecondary = Color.White,
    secondaryContainer = Color(0xFFFFDBD1),
    onSecondaryContainer = Color(0xFF3B0902),
    tertiary = Color(0xFF4D6518),
    surface = Color(0xFFF7FAF9),
    surfaceVariant = Color(0xFFDCE5E2),
    background = Color(0xFFF7FAF9),
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF82D5C4),
    onPrimary = Color(0xFF00382F),
    primaryContainer = Color(0xFF005046),
    onPrimaryContainer = Color(0xFF9EF2E0),
    secondary = Color(0xFFFFB5A2),
    onSecondary = Color(0xFF5E160B),
    secondaryContainer = Color(0xFF7D2C18),
    onSecondaryContainer = Color(0xFFFFDBD1),
    tertiary = Color(0xFFB5CF70),
    surface = Color(0xFF101412),
    surfaceVariant = Color(0xFF3F4946),
    background = Color(0xFF101412),
)

@Composable
private fun ArmbandRechnerTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = if (androidx.compose.foundation.isSystemInDarkTheme()) {
            DarkColors
        } else {
            LightColors
        },
        content = content,
    )
}
