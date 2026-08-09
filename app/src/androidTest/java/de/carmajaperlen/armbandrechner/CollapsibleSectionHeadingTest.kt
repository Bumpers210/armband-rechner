package de.carmajaperlen.armbandrechner

import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertTextContains
import androidx.compose.ui.test.junit4.StateRestorationTester
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import org.junit.Rule
import org.junit.Test

class CollapsibleSectionHeadingTest {
    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun sectionsToggleIndependentlyAndExposeTheirState() {
        setSectionContent()

        composeRule.onNodeWithContentDescription("Perlen ausgeklappt")
            .performClick()
        composeRule.onNodeWithContentDescription("Perlen eingeklappt")
            .assertTextContains("Eingeklappt ▼")
        composeRule.onNodeWithContentDescription("Edelsteinspacer ausgeklappt")
            .assertTextContains("Ausgeklappt ▲")
    }

    @Test
    fun collapseStateSurvivesSavedStateRestoration() {
        val restorationTester = StateRestorationTester(composeRule)
        restorationTester.setContent { CollapsibleSections() }

        composeRule.onNodeWithText("Perlen").performClick()
        restorationTester.emulateSavedInstanceStateRestore()

        composeRule.onNodeWithContentDescription("Perlen eingeklappt")
            .assertTextContains("Eingeklappt ▼")
        composeRule.onNodeWithContentDescription("Edelsteinspacer ausgeklappt")
            .assertTextContains("Ausgeklappt ▲")
    }

    private fun setSectionContent() {
        composeRule.setContent { CollapsibleSections() }
    }
}

@androidx.compose.runtime.Composable
private fun CollapsibleSections() {
    var pearlsExpanded by rememberSaveable { mutableStateOf(true) }
    var spacersExpanded by rememberSaveable { mutableStateOf(true) }

    MaterialTheme {
        CollapsibleSectionHeading(
            text = "Perlen",
            expanded = pearlsExpanded,
            onToggle = { pearlsExpanded = !pearlsExpanded },
        )
        CollapsibleSectionHeading(
            text = "Edelsteinspacer",
            expanded = spacersExpanded,
            onToggle = { spacersExpanded = !spacersExpanded },
        )
    }
}
