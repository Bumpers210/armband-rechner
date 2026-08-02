package de.carmajaperlen.armbandrechner

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class ProductApiTargetTest {
    @Test
    fun configuredEndpointsAcceptOnlyTheirExactVariantApi() {
        assertEquals(
            "https://test-api.carmaja-perlen.de/",
            requireProductApiEndpoint(
                "https://test-api.carmaja-perlen.de/",
                "test",
            ).baseUrl,
        )
        assertEquals(
            "https://api.carmaja-perlen.de/",
            requireProductApiEndpoint(
                "https://api.carmaja-perlen.de/",
                "production",
            ).baseUrl,
        )

        listOf(
            "https://api.carmaja-perlen.de/",
            "https://test-api.carmaja-perlen.de.example/",
            "https://user@test-api.carmaja-perlen.de/",
            "https://test-api.carmaja-perlen.de:443/",
            "http://test-api.carmaja-perlen.de/",
        ).forEach { endpoint ->
            assertThrows(ProductApiException::class.java) {
                requireProductApiEndpoint(endpoint, "test")
            }
        }

        assertThrows(ProductApiException::class.java) {
            requireProductApiEndpoint("https://test-api.carmaja-perlen.de/", "production")
        }
    }

    @Test
    fun loginTargetMustMatchTheConfiguredVariant() {
        requireMatchingPublishTarget("test", "test")
        requireMatchingPublishTarget("production", "production")

        assertThrows(ProductTargetMismatchException::class.java) {
            requireMatchingPublishTarget("test", "production")
        }
        assertThrows(ProductTargetMismatchException::class.java) {
            requireMatchingPublishTarget("production", "test")
        }
    }

    @Test
    fun onlyTestTargetCanRestoreRememberedSessions() {
        assertEquals(
            true,
            requireProductApiEndpoint(
                "https://test-api.carmaja-perlen.de/",
                "test",
            ).allowsRememberedSession,
        )
        assertEquals(
            false,
            requireProductApiEndpoint(
                "https://api.carmaja-perlen.de/",
                "production",
            ).allowsRememberedSession,
        )
    }

    @Test
    fun allKnownApiCallsRejectUnknownEndpoints() {
        assertEquals(
            "https://api.carmaja-perlen.de/",
            requireKnownProductApiBaseUrl("https://api.carmaja-perlen.de/"),
        )
        assertThrows(ProductApiException::class.java) {
            requireKnownProductApiBaseUrl("https://example.invalid/")
        }
    }
}
